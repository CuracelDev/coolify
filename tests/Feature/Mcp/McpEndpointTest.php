<?php

use App\Jobs\ApplicationDeploymentJob;
use App\Models\Application;
use App\Models\ApplicationDeploymentQueue;
use App\Models\GithubApp;
use App\Models\InstanceSettings;
use App\Models\PrivateKey;
use App\Models\Project;
use App\Models\Server;
use App\Models\Team;
use App\Models\User;
use App\Services\RepositoryDeploymentResultWaiter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Once;

uses(RefreshDatabase::class);

beforeEach(function () {
    config()->set('app.maintenance.driver', 'file');
    config()->set('app.maintenance.store', 'array');
    config()->set('cache.default', 'array');

    InstanceSettings::query()->where('id', 0)->delete();
    InstanceSettings::query()->delete();
    $settings = new InstanceSettings(['is_mcp_server_enabled' => true]);
    $settings->id = 0;
    $settings->save();

    $this->team = Team::factory()->create();
    $this->user = User::factory()->create();
    $this->team->members()->attach($this->user->id, ['role' => 'owner']);
    session(['currentTeam' => $this->team]);
});

function mcpPost(array $payload, ?string $token = null)
{
    $headers = [
        'Content-Type' => 'application/json',
        'Accept' => 'application/json, text/event-stream',
    ];
    if ($token) {
        $headers['Authorization'] = 'Bearer '.$token;
    }

    return test()->withHeaders($headers)->postJson('/mcp', $payload);
}

function mcpListTools(string $token)
{
    return mcpPost([
        'jsonrpc' => '2.0',
        'id' => 1,
        'method' => 'tools/list',
        'params' => (object) [],
    ], $token);
}

function mcpCallTool(string $token, string $name, array $arguments = [])
{
    return mcpPost([
        'jsonrpc' => '2.0',
        'id' => 1,
        'method' => 'tools/call',
        'params' => [
            'name' => $name,
            'arguments' => (object) $arguments,
        ],
    ], $token);
}

function mcpToolJson($response): array
{
    return json_decode($response->json('result.content.0.text'), true);
}

function mcpGithubAppForTeam(Team $team, int $installationId = 67890, bool $isSystemWide = false): GithubApp
{
    $rsaKey = openssl_pkey_new([
        'private_key_bits' => 2048,
        'private_key_type' => OPENSSL_KEYTYPE_RSA,
    ]);
    openssl_pkey_export($rsaKey, $pemKey);

    $privateKey = PrivateKey::create([
        'name' => 'MCP Test Key '.$team->id,
        'private_key' => $pemKey,
        'team_id' => $team->id,
    ]);

    return GithubApp::create([
        'name' => 'MCP GitHub App '.$team->id,
        'api_url' => 'https://api.github.com',
        'html_url' => 'https://github.com',
        'custom_user' => 'git',
        'custom_port' => 22,
        'app_id' => 12345,
        'installation_id' => $installationId,
        'client_id' => 'mcp-client-id',
        'client_secret' => 'mcp-client-secret',
        'webhook_secret' => 'mcp-webhook-secret',
        'private_key_id' => $privateKey->id,
        'team_id' => $team->id,
        'is_public' => false,
        'is_system_wide' => $isSystemWide,
    ]);
}

function fakeGithubInspectionResponses(int $installationId, array $fileMap, array $rootEntries): void
{
    Http::fake(function ($request) use ($installationId, $fileMap, $rootEntries) {
        $url = $request->url();

        if ($url === 'https://api.github.com/zen') {
            return Http::response('Keep it logically awesome.', 200, [
                'Date' => now()->toRfc7231String(),
            ]);
        }

        if ($url === "https://api.github.com/app/installations/{$installationId}/access_tokens") {
            return Http::response(['token' => 'mcp-installation-token'], 201);
        }

        if ($url === 'https://llm.crl.to/v1/chat/completions') {
            return Http::response([
                'choices' => [[
                    'message' => [
                        'content' => json_encode([
                            'build_pack' => 'static',
                            'install_command' => 'npm ci',
                            'build_command' => 'npm run build',
                            'publish_directory' => '/dist',
                            'port' => 80,
                            'confidence' => 0.94,
                            'rationale' => 'Frontend repo facts strongly indicate a static deployment.',
                            'caveats' => ['Confirm SPA routing fallback after deploy.'],
                        ]),
                    ],
                ]],
            ]);
        }

        if ($url === 'https://api.github.com/repos/acme/web') {
            return Http::response([
                'id' => 987654,
                'full_name' => 'acme/web',
                'default_branch' => 'main',
            ], 200);
        }

        if ($url === 'https://api.github.com/repos/acme/web/contents?ref=main') {
            return Http::response($rootEntries, 200);
        }

        $path = str($url)->after('https://api.github.com/repos/acme/web/contents')->before('?ref=main')->value();
        if (array_key_exists($path, $fileMap)) {
            return Http::response($fileMap[$path], 200);
        }

        return Http::response(['message' => 'Not Found'], 404);
    });
}

function mcpDeploymentTargetForTeam(Team $team): array
{
    $server = Server::factory()->create(['team_id' => $team->id]);
    $server->settings->forceFill(['wildcard_domain' => 'https://apps.example.test'])->save();
    $project = Project::factory()->create(['team_id' => $team->id]);
    $environment = $project->environments()->firstOrFail();
    $destination = $server->standaloneDockers()->firstOrFail();

    return [$project->fresh(), $environment->fresh(), $server->fresh('settings'), $destination->fresh()];
}

test('MCP endpoint returns 404 when the instance setting is disabled', function () {
    InstanceSettings::query()->where('id', 0)->update(['is_mcp_server_enabled' => false]);
    Once::flush();

    $response = mcpPost(['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list']);
    $response->assertStatus(404);
});

test('MCP endpoint rejects unauthenticated requests', function () {
    $response = mcpPost(['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list']);
    $response->assertStatus(401);
});

test('MCP endpoint lists tools for an authenticated token', function () {
    $token = $this->user->createToken('mcp-read', ['read'])->plainTextToken;

    $response = mcpListTools($token);
    $response->assertOk();

    $toolNames = collect($response->json('result.tools'))->pluck('name')->all();
    expect($toolNames)->toContain(
        'get_infrastructure_overview',
        'analyze_repository_deployment_defaults',
        'deploy_repository',
        'list_servers',
        'get_server',
        'list_projects',
        'list_applications',
        'get_application',
        'list_databases',
        'get_database',
        'list_services',
        'get_service',
    );
    expect($toolNames)->not->toContain('get_resource_status');
});

test('list_projects returns summary + pagination scoped to the token team', function () {
    $project = Project::create(['name' => 'Mine', 'team_id' => $this->team->id]);

    $otherTeam = Team::factory()->create();
    Project::create(['name' => 'Theirs', 'team_id' => $otherTeam->id]);

    $token = $this->user->createToken('mcp-read', ['read'])->plainTextToken;

    $response = mcpCallTool($token, 'list_projects');
    $response->assertOk();

    $body = mcpToolJson($response);

    expect($body)->toHaveKey('data');
    expect($body)->toHaveKey('_pagination');
    expect($body['_pagination']['total'])->toBe(1);
    expect($body['_pagination']['per_page'])->toBe(50);
    expect($body['_pagination'])->not->toHaveKey('next');

    $uuids = collect($body['data'])->pluck('uuid')->all();
    $names = collect($body['data'])->pluck('name')->all();
    expect($uuids)->toContain($project->uuid);
    expect($names)->not->toContain('Theirs');
    expect($body['data'][0])->toHaveKeys(['uuid', 'name', 'description']);
});

test('list_projects paginates with per_page cap at 100', function () {
    for ($i = 0; $i < 3; $i++) {
        Project::create(['name' => "P{$i}", 'team_id' => $this->team->id]);
    }
    $token = $this->user->createToken('mcp-read', ['read'])->plainTextToken;

    $response = mcpCallTool($token, 'list_projects', ['per_page' => 2, 'page' => 1]);
    $body = mcpToolJson($response);

    expect($body['_pagination']['total'])->toBe(3);
    expect($body['_pagination']['total_pages'])->toBe(2);
    expect($body['_pagination']['next']['args'])->toMatchArray(['page' => 2, 'per_page' => 2]);
    expect($body['data'])->toHaveCount(2);

    // Verify max cap
    $capped = mcpCallTool($token, 'list_projects', ['per_page' => 500]);
    $cappedBody = mcpToolJson($capped);
    expect($cappedBody['_pagination']['per_page'])->toBe(100);
});

test('get_infrastructure_overview returns counts', function () {
    Project::create(['name' => 'One', 'team_id' => $this->team->id]);
    Project::create(['name' => 'Two', 'team_id' => $this->team->id]);

    $token = $this->user->createToken('mcp-read', ['read'])->plainTextToken;

    $response = mcpCallTool($token, 'get_infrastructure_overview');
    $response->assertOk();

    $body = mcpToolJson($response);
    expect($body)->toHaveKey('data');
    expect($body['data'])->toHaveKeys(['coolify_version', 'servers', 'projects', 'counts']);
    expect($body['data']['counts']['projects'])->toBe(2);
    expect($body['data']['projects'])->toHaveCount(2);
    expect($body['data']['projects'][0])->toHaveKey('counts');
});

test('get_server scrubs sensitive nested data and exposes connection_timeout', function () {
    $server = Server::factory()->create(['team_id' => $this->team->id]);
    // creating hook auto-generates a sentinel_token; bump connection_timeout
    // via saveQuietly to avoid triggering restartSentinel.
    $server->settings->forceFill(['connection_timeout' => 42])->saveQuietly();

    $token = $this->user->createToken('mcp-read', ['read'])->plainTextToken;

    $response = mcpCallTool($token, 'get_server', ['uuid' => $server->uuid]);
    $response->assertOk();

    $body = mcpToolJson($response);
    $raw = json_encode($body);

    expect($raw)->not->toContain('sentinel_token');
    expect($raw)->not->toContain('"team_id"');
    expect($raw)->not->toContain('"private_key_id"');
    expect($body['data']['connection_timeout'])->toBe(42);
    expect($body['data']['uuid'])->toBe($server->uuid);
});

test('tool calls fail when the token lacks the read ability', function () {
    $token = $this->user->createToken('mcp-no-abilities', [])->plainTextToken;

    $response = mcpCallTool($token, 'list_projects');
    $response->assertOk();

    expect($response->json('result.isError'))->toBeTrue();
    expect($response->json('result.content.0.text'))->toContain('Missing required permissions');
});

test('analyze_repository_deployment_defaults returns structured recommendations without raw file bodies', function () {
    config()->set('services.ai_deploy_advisor.api_key', 'test-token');

    $githubApp = mcpGithubAppForTeam($this->team);

    fakeGithubInspectionResponses(
        installationId: $githubApp->installation_id,
        rootEntries: [
            ['path' => 'package.json', 'type' => 'file', 'name' => 'package.json'],
            ['path' => 'README.md', 'type' => 'file', 'name' => 'README.md'],
            ['path' => 'vite.config.ts', 'type' => 'file', 'name' => 'vite.config.ts'],
        ],
        fileMap: [
            '/package.json' => [
                'type' => 'file',
                'name' => 'package.json',
                'path' => 'package.json',
                'size' => 140,
                'sha' => 'pkg-sha',
                'encoding' => 'base64',
                'content' => base64_encode(json_encode([
                    'name' => 'web-ui',
                    'scripts' => ['build' => 'vite build'],
                    'dependencies' => ['react' => '^19.0.0'],
                    'devDependencies' => ['vite' => '^6.0.0'],
                ])),
            ],
            '/README.md' => [
                'type' => 'file',
                'name' => 'README.md',
                'path' => 'README.md',
                'size' => 90,
                'sha' => 'readme-sha',
                'encoding' => 'base64',
                'content' => base64_encode('Very detailed README body that should not be mirrored back to MCP clients.'),
            ],
            '/vite.config.ts' => [
                'type' => 'file',
                'name' => 'vite.config.ts',
                'path' => 'vite.config.ts',
                'size' => 40,
                'sha' => 'vite-sha',
                'encoding' => 'base64',
                'content' => base64_encode('import { defineConfig } from "vite";'),
            ],
        ],
    );

    $token = $this->user->createToken('mcp-read', ['read'])->plainTextToken;

    $response = mcpCallTool($token, 'analyze_repository_deployment_defaults', [
        'github_app_uuid' => $githubApp->uuid,
        'repo_owner' => 'acme',
        'repo_name' => 'web',
        'branch' => 'main',
    ]);
    $response->assertOk();

    $body = mcpToolJson($response);
    $raw = json_encode($body);

    expect($body['data'])->toHaveKeys(['source', 'repository', 'selection', 'inspection', 'recommendation', 'heuristics', 'ai', 'rationale']);
    expect($body['data']['inspection'])->toHaveKeys(['fingerprint', 'package_manager', 'lockfiles', 'directory_entries', 'files']);
    expect($body['data']['recommendation']['build_pack'])->toBe('static');
    expect($body['data']['ai']['status'])->toBe('completed');
    expect($raw)->not->toContain('Very detailed README body');
    expect($raw)->not->toContain('"content"');
});

test('analyze_repository_deployment_defaults fails clearly when base directory is missing', function () {
    $githubApp = mcpGithubAppForTeam($this->team, installationId: 67891);

    Http::fake([
        'https://api.github.com/zen' => Http::response('Keep it logically awesome.', 200, [
            'Date' => now()->toRfc7231String(),
        ]),
        'https://api.github.com/app/installations/67891/access_tokens' => Http::response(['token' => 'mcp-installation-token'], 201),
        'https://api.github.com/repos/acme/web/contents/apps/missing?ref=main' => Http::response(['message' => 'Not Found'], 404),
    ]);

    $token = $this->user->createToken('mcp-read', ['read'])->plainTextToken;

    $response = mcpCallTool($token, 'analyze_repository_deployment_defaults', [
        'github_app_uuid' => $githubApp->uuid,
        'repo_owner' => 'acme',
        'repo_name' => 'web',
        'branch' => 'main',
        'base_directory' => '/apps/missing',
    ]);

    $response->assertOk();
    expect($response->json('result.isError'))->toBeTrue();
    expect($response->json('result.content.0.text'))->toContain('Selected base directory');
});

test('analyze_repository_deployment_defaults enforces team scoped github sources', function () {
    $otherTeam = Team::factory()->create();
    $otherGithubApp = mcpGithubAppForTeam($otherTeam, installationId: 67892);

    Http::fake();
    $token = $this->user->createToken('mcp-read', ['read'])->plainTextToken;

    $response = mcpCallTool($token, 'analyze_repository_deployment_defaults', [
        'github_app_uuid' => $otherGithubApp->uuid,
        'repo_owner' => 'acme',
        'repo_name' => 'web',
        'branch' => 'main',
    ]);

    $response->assertOk();
    expect($response->json('result.isError'))->toBeTrue();
    expect($response->json('result.content.0.text'))->toContain('GitHub source not found');
    Http::assertNothingSent();
});

test('deploy_repository creates an application and queues a deployment', function () {
    Bus::fake([ApplicationDeploymentJob::class]);
    config()->set('services.ai_deploy_advisor.api_key', 'test-token');

    $githubApp = mcpGithubAppForTeam($this->team);
    [$project, $environment, $server] = mcpDeploymentTargetForTeam($this->team);

    fakeGithubInspectionResponses(
        installationId: $githubApp->installation_id,
        rootEntries: [
            ['path' => 'package.json', 'type' => 'file', 'name' => 'package.json'],
            ['path' => 'vite.config.ts', 'type' => 'file', 'name' => 'vite.config.ts'],
        ],
        fileMap: [
            '/package.json' => [
                'type' => 'file',
                'name' => 'package.json',
                'path' => 'package.json',
                'size' => 140,
                'sha' => 'pkg-sha',
                'encoding' => 'base64',
                'content' => base64_encode(json_encode([
                    'name' => 'web-ui',
                    'scripts' => ['build' => 'vite build'],
                    'dependencies' => ['react' => '^19.0.0'],
                    'devDependencies' => ['vite' => '^6.0.0'],
                ])),
            ],
            '/vite.config.ts' => [
                'type' => 'file',
                'name' => 'vite.config.ts',
                'path' => 'vite.config.ts',
                'size' => 40,
                'sha' => 'vite-sha',
                'encoding' => 'base64',
                'content' => base64_encode('import { defineConfig } from "vite";'),
            ],
        ],
    );

    $token = $this->user->createToken('mcp-write', ['write'])->plainTextToken;

    $response = mcpCallTool($token, 'deploy_repository', [
        'github_app_uuid' => $githubApp->uuid,
        'project_uuid' => $project->uuid,
        'server_uuid' => $server->uuid,
        'environment_uuid' => $environment->uuid,
        'repo_owner' => 'acme',
        'repo_name' => 'web',
        'branch' => 'main',
    ]);
    $response->assertOk();

    $body = mcpToolJson($response);

    expect($body['data']['application']['uuid'])->not->toBeEmpty()
        ->and($body['data']['application']['build_pack'])->toBe('static')
        ->and($body['data']['deployment']['queue_status'])->toBe('queued')
        ->and($body['data']['deployment']['status'])->toBe('in_progress')
        ->and($body['data']['result']['state'])->toBe('pending')
        ->and($body['data']['result']['url'])->toStartWith('https://');

    $application = Application::query()->where('uuid', $body['data']['application']['uuid'])->first();
    expect($application)->not->toBeNull();

    $deployment = ApplicationDeploymentQueue::query()
        ->where('deployment_uuid', $body['data']['deployment']['deployment_uuid'])
        ->first();

    expect($deployment)->not->toBeNull()
        ->and((int) $deployment?->application_id)->toBe($application->id);
});

test('deploy_repository auto enables deployment operator when the created app is supported', function () {
    Bus::fake([ApplicationDeploymentJob::class]);
    config()->set('services.ai_deploy_advisor.api_key', 'test-token');

    $githubApp = mcpGithubAppForTeam($this->team, installationId: 67893);
    [$project, $environment, $server] = mcpDeploymentTargetForTeam($this->team);

    fakeGithubInspectionResponses(
        installationId: $githubApp->installation_id,
        rootEntries: [
            ['path' => 'package.json', 'type' => 'file', 'name' => 'package.json'],
        ],
        fileMap: [
            '/package.json' => [
                'type' => 'file',
                'name' => 'package.json',
                'path' => 'package.json',
                'size' => 110,
                'sha' => 'pkg-sha-2',
                'encoding' => 'base64',
                'content' => base64_encode(json_encode([
                    'name' => 'web-ui',
                    'scripts' => ['build' => 'vite build'],
                    'dependencies' => ['react' => '^19.0.0'],
                    'devDependencies' => ['vite' => '^6.0.0'],
                ])),
            ],
        ],
    );

    $token = $this->user->createToken('mcp-write', ['write'])->plainTextToken;

    $response = mcpCallTool($token, 'deploy_repository', [
        'github_app_uuid' => $githubApp->uuid,
        'project_uuid' => $project->uuid,
        'server_uuid' => $server->uuid,
        'environment_uuid' => $environment->uuid,
        'repo_owner' => 'acme',
        'repo_name' => 'web',
        'branch' => 'main',
    ]);
    $response->assertOk();

    $body = mcpToolJson($response);

    expect($body['data']['application']['operator_enabled'])->toBeTrue()
        ->and($body['data']['application']['operator_support']['enabled'])->toBeTrue()
        ->and($body['data']['application']['operator_support']['reason'])->not->toBeEmpty();
});

test('deploy_repository returns immediately when wait_for_result is false', function () {
    Bus::fake([ApplicationDeploymentJob::class]);

    $githubApp = mcpGithubAppForTeam($this->team, installationId: 67894);
    [$project, $environment, $server] = mcpDeploymentTargetForTeam($this->team);

    fakeGithubInspectionResponses(
        installationId: $githubApp->installation_id,
        rootEntries: [
            ['path' => 'package.json', 'type' => 'file', 'name' => 'package.json'],
        ],
        fileMap: [
            '/package.json' => [
                'type' => 'file',
                'name' => 'package.json',
                'path' => 'package.json',
                'size' => 120,
                'sha' => 'pkg-sha-3',
                'encoding' => 'base64',
                'content' => base64_encode(json_encode([
                    'name' => 'api',
                    'scripts' => ['start' => 'node server.js'],
                    'dependencies' => ['express' => '^5.0.0'],
                ])),
            ],
        ],
    );

    $token = $this->user->createToken('mcp-write', ['write'])->plainTextToken;

    $response = mcpCallTool($token, 'deploy_repository', [
        'github_app_uuid' => $githubApp->uuid,
        'project_uuid' => $project->uuid,
        'server_uuid' => $server->uuid,
        'environment_uuid' => $environment->uuid,
        'repo_owner' => 'acme',
        'repo_name' => 'web',
        'branch' => 'main',
        'wait_for_result' => false,
    ]);
    $response->assertOk();

    $body = mcpToolJson($response);

    $application = Application::query()->where('uuid', $body['data']['application']['uuid'])->first();

    expect($body['data']['deployment']['waited'])->toBeFalse()
        ->and($body['data']['deployment']['wait_timed_out'])->toBeFalse()
        ->and($body['data']['result']['state'])->toBe('pending')
        ->and($body['data']['application']['build_pack'])->toBe('nixpacks')
        ->and($application)->not->toBeNull()
        ->and($application?->static_image)->toBe('nginx:alpine')
        ->and($application?->settings?->is_static)->toBeFalse();
});

test('deploy_repository wait mode returns completed deployment and operator result', function () {
    Bus::fake([ApplicationDeploymentJob::class]);
    config()->set('services.ai_deploy_advisor.api_key', 'test-token');

    $githubApp = mcpGithubAppForTeam($this->team, installationId: 67895);
    [$project, $environment, $server] = mcpDeploymentTargetForTeam($this->team);

    fakeGithubInspectionResponses(
        installationId: $githubApp->installation_id,
        rootEntries: [
            ['path' => 'package.json', 'type' => 'file', 'name' => 'package.json'],
        ],
        fileMap: [
            '/package.json' => [
                'type' => 'file',
                'name' => 'package.json',
                'path' => 'package.json',
                'size' => 120,
                'sha' => 'pkg-sha-4',
                'encoding' => 'base64',
                'content' => base64_encode(json_encode([
                    'name' => 'web-ui',
                    'scripts' => ['build' => 'vite build'],
                    'dependencies' => ['react' => '^19.0.0'],
                    'devDependencies' => ['vite' => '^6.0.0'],
                ])),
            ],
        ],
    );

    $waiter = Mockery::mock(RepositoryDeploymentResultWaiter::class);
    $waiter->shouldReceive('wait')
        ->once()
        ->andReturnUsing(function (ApplicationDeploymentQueue $deployment) {
            $target = generateUrl($deployment->application->destination->server, $deployment->application->uuid);
            $deployment->forceFill([
                'status' => 'finished',
                'operator_status' => 'recorded',
                'operator_verification' => [
                    'result' => 'passed',
                    'target' => $target,
                    'http_status' => 200,
                    'pass' => true,
                    'reason' => 'http_ok',
                ],
                'finished_at' => now(),
            ])->save();

            return $deployment->fresh(['application.settings']);
        });
    app()->instance(RepositoryDeploymentResultWaiter::class, $waiter);

    $token = $this->user->createToken('mcp-write', ['write'])->plainTextToken;

    $response = mcpCallTool($token, 'deploy_repository', [
        'github_app_uuid' => $githubApp->uuid,
        'project_uuid' => $project->uuid,
        'server_uuid' => $server->uuid,
        'environment_uuid' => $environment->uuid,
        'repo_owner' => 'acme',
        'repo_name' => 'web',
        'branch' => 'main',
        'wait_for_result' => true,
    ]);
    $response->assertOk();

    $body = mcpToolJson($response);

    expect($body['data']['deployment']['waited'])->toBeTrue()
        ->and($body['data']['deployment']['wait_timed_out'])->toBeFalse()
        ->and($body['data']['deployment']['status'])->toBe('finished')
        ->and($body['data']['deployment']['operator_status'])->toBe('recorded')
        ->and($body['data']['result']['state'])->toBe('ready')
        ->and($body['data']['result']['url'])->toStartWith('https://');
});

test('deploy_repository fails clearly for invalid repo arguments', function () {
    $githubApp = mcpGithubAppForTeam($this->team, installationId: 67896);
    [$project, $environment, $server] = mcpDeploymentTargetForTeam($this->team);

    $token = $this->user->createToken('mcp-write', ['write'])->plainTextToken;

    $response = mcpCallTool($token, 'deploy_repository', [
        'github_app_uuid' => $githubApp->uuid,
        'project_uuid' => $project->uuid,
        'server_uuid' => $server->uuid,
        'environment_uuid' => $environment->uuid,
        'repo_owner' => 'acme/org',
        'repo_name' => 'web',
        'branch' => 'main',
    ]);

    $response->assertOk();
    expect($response->json('result.isError'))->toBeTrue();
    expect($response->json('result.content.0.text'))->toContain('Invalid arguments');
});

test('deploy_repository enforces team scoped github sources', function () {
    $otherTeam = Team::factory()->create();
    $otherGithubApp = mcpGithubAppForTeam($otherTeam, installationId: 67897);
    [$project, $environment, $server] = mcpDeploymentTargetForTeam($this->team);

    $token = $this->user->createToken('mcp-write', ['write'])->plainTextToken;

    $response = mcpCallTool($token, 'deploy_repository', [
        'github_app_uuid' => $otherGithubApp->uuid,
        'project_uuid' => $project->uuid,
        'server_uuid' => $server->uuid,
        'environment_uuid' => $environment->uuid,
        'repo_owner' => 'acme',
        'repo_name' => 'web',
        'branch' => 'main',
    ]);

    $response->assertOk();
    expect($response->json('result.isError'))->toBeTrue();
    expect($response->json('result.content.0.text'))->toContain('GitHub source not found');
});

test('MCP rejects token when user no longer belongs to token team', function () {
    Project::create(['name' => 'Hidden', 'team_id' => $this->team->id]);
    $token = $this->user->createToken('mcp-read', ['read'])->plainTextToken;

    $this->team->members()->detach($this->user->id);

    $response = mcpCallTool($token, 'list_projects');

    $response->assertUnauthorized();
});
