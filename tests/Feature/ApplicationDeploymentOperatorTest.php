<?php

use App\Actions\Docker\GetContainersStatus;
use App\Jobs\ApplicationDeploymentJob;
use App\Jobs\ApplicationDeploymentVerificationJob;
use App\Models\Application;
use App\Models\ApplicationDeploymentQueue;
use App\Models\ApplicationPreview;
use App\Models\ApplicationSetting;
use App\Models\EnvironmentVariable;
use App\Models\InstanceSettings;
use App\Models\Project;
use App\Models\Server;
use App\Models\Team;
use App\Services\ApplicationDeploymentOperatorService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Schema;
use Visus\Cuid2\Cuid2;

uses(RefreshDatabase::class);

beforeEach(function () {
    Bus::fake([ApplicationDeploymentJob::class]);

    $this->team = Team::factory()->create();
    $this->server = Server::factory()->create(['team_id' => $this->team->id]);
    $this->destination = $this->server->standaloneDockers()->firstOrCreate(
        ['network' => 'coolify'],
        ['uuid' => (string) new Cuid2, 'name' => 'test-destination']
    );
    $this->project = Project::create([
        'uuid' => (string) new Cuid2,
        'name' => 'operator-project',
        'team_id' => $this->team->id,
    ]);
    $this->environment = $this->project->environments()->firstOrFail();

    InstanceSettings::unguarded(function () {
        InstanceSettings::updateOrCreate(
            ['id' => 0],
            [
                'fqdn' => 'http://coolify.test',
                'is_dns_validation_enabled' => false,
            ]
        );
    });
});

function makeOperatorApplication(array $applicationOverrides = [], array $settingOverrides = []): Application
{
    $uuid = (string) new Cuid2;
    $serverForDefaultUrl = test()->server->fresh('settings');

    /** @var Application $application */
    $application = Application::factory()->create(array_merge([
        'uuid' => $uuid,
        'environment_id' => test()->environment->id,
        'destination_id' => test()->destination->id,
        'destination_type' => test()->destination->getMorphClass(),
        'build_pack' => 'nixpacks',
        'git_commit_sha' => 'abc123def456abc123def456abc123def456abc1',
        'publish_directory' => '/dist',
        'build_command' => 'npm run build',
        'install_command' => 'npm ci',
        'fqdn' => generateUrl($serverForDefaultUrl, $uuid),
        'ports_exposes' => '3000',
    ], $applicationOverrides));

    $application = $application->fresh(['destination.server', 'settings']);

    if (! $application->settings) {
        ApplicationSetting::create([
            'application_id' => $application->id,
        ]);
        $application->refresh();
    }

    $application->settings->forceFill(array_merge([
        'is_deployment_operator_enabled' => true,
        'deployment_operator_max_attempts' => 2,
        'is_static' => false,
    ], $settingOverrides))->save();

    return $application->fresh('settings', 'destination.server');
}

function makeDeployment(Application $application, string $status = 'failed', array $overrides = []): ApplicationDeploymentQueue
{
    return ApplicationDeploymentQueue::create(array_merge([
        'application_id' => $application->id,
        'application_name' => $application->name,
        'server_id' => $application->destination->server_id,
        'server_name' => $application->destination->server->name,
        'destination_id' => $application->destination_id,
        'deployment_uuid' => (string) new Cuid2,
        'deployment_url' => '/projects/test/environments/test/applications/test/deployment/test',
        'pull_request_id' => 0,
        'force_rebuild' => false,
        'commit' => $application->git_commit_sha,
        'status' => $status,
    ], $overrides));
}

function makePreview(Application $application, int $pullRequestId, array $overrides = []): ApplicationPreview
{
    return ApplicationPreview::create(array_merge([
        'uuid' => (string) new Cuid2,
        'application_id' => $application->id,
        'pull_request_id' => $pullRequestId,
        'pull_request_html_url' => "https://github.com/example/repo/pull/{$pullRequestId}",
        'fqdn' => "https://pr-{$pullRequestId}.example.com",
    ], $overrides));
}

function makeComposeOperatorApplication(array $applicationOverrides = [], array $settingOverrides = []): Application
{
    $defaultCompose = <<<'YAML'
services:
  web:
    image: nginx:alpine
  db:
    image: postgres:15
YAML;

    return makeOperatorApplication(array_merge([
        'build_pack' => 'dockercompose',
        'compose_parsing_version' => 3,
        'docker_compose_raw' => $defaultCompose,
        'docker_compose_domains' => json_encode([
            'web' => ['domain' => 'https://web.example.com'],
        ], JSON_THROW_ON_ERROR),
        'docker_compose_custom_start_command' => null,
        'docker_compose_custom_build_command' => null,
        'fqdn' => 'https://generic-app.example.com',
    ], $applicationOverrides), $settingOverrides);
}

function attachAdditionalDestination(Application $application): array
{
    $server = Server::factory()->create(['team_id' => test()->team->id]);
    $destination = $server->standaloneDockers()->firstOrCreate(
        ['network' => 'coolify'],
        ['uuid' => (string) new Cuid2, 'name' => 'secondary-destination']
    );

    $application->additional_networks()->attach($destination->id, ['server_id' => $server->id]);

    return [
        $application->fresh(['settings', 'destination.server']),
        $server->fresh(),
        $destination->fresh(),
    ];
}

function deploymentOperatorLogs(string ...$outputs): string
{
    return json_encode(collect($outputs)->values()->map(
        fn (string $output, int $index): array => [
            'command' => null,
            'output' => $output,
            'type' => 'stderr',
            'timestamp' => now()->toISOString(),
            'hidden' => false,
            'batch' => 1,
            'order' => $index + 1,
        ]
    )->all(), JSON_THROW_ON_ERROR);
}

describe('application deployment operator schema and models', function () {
    test('operator columns and casts exist', function () {
        expect(Schema::hasColumn('application_settings', 'is_deployment_operator_enabled'))->toBeTrue()
            ->and(Schema::hasColumn('application_settings', 'deployment_operator_max_attempts'))->toBeTrue()
            ->and(Schema::hasColumn('application_deployment_queues', 'operator_attempt'))->toBeTrue()
            ->and(Schema::hasColumn('application_deployment_queues', 'operator_root_deployment_id'))->toBeTrue()
            ->and(Schema::hasColumn('application_deployment_queues', 'operator_status'))->toBeTrue()
            ->and(Schema::hasColumn('application_deployment_queues', 'operator_rule'))->toBeTrue()
            ->and(Schema::hasColumn('application_deployment_queues', 'operator_verification'))->toBeTrue()
            ->and((new ApplicationSetting)->getFillable())->toContain('is_deployment_operator_enabled', 'deployment_operator_max_attempts')
            ->and((new ApplicationSetting)->getCasts())->toHaveKeys(['is_deployment_operator_enabled', 'deployment_operator_max_attempts'])
            ->and((new ApplicationDeploymentQueue)->getFillable())->toContain('operator_attempt', 'operator_root_deployment_id', 'operator_status', 'operator_rule', 'operator_verification')
            ->and((new ApplicationDeploymentQueue)->getCasts())->toHaveKeys(['operator_attempt', 'operator_root_deployment_id', 'operator_verification']);
    });
});

describe('application deployment operator service', function () {
    test('default generated url only app is eligible and classifies known nixpacks failure', function () {
        $application = makeOperatorApplication();
        $deployment = makeDeployment($application, overrides: [
            'logs' => deploymentOperatorLogs('⚠️ NIXPACKS_NODE_VERSION not set. Nixpacks will use Node.js 18 by default, which is EOL.'),
        ]);

        $service = app(ApplicationDeploymentOperatorService::class);

        expect($service->eligibility($deployment))->toBe([
            'eligible' => true,
            'reason' => 'eligible_nixpacks',
        ])
            ->and($service->latestDeploymentGuard($deployment))->toBe([
                'is_latest' => true,
                'reason' => 'latest',
            ])
            ->and($service->classifyDeployment($deployment))->toBe([
                'action' => 'remediate',
                'classification' => 'node18_mismatch',
                'rule' => 'set_nixpacks_node22',
            ]);
    });

    test('custom domain only app is ineligible', function () {
        $application = makeOperatorApplication([
            'fqdn' => 'https://custom.example.com',
        ]);

        $deployment = makeDeployment($application);
        $service = app(ApplicationDeploymentOperatorService::class);

        expect($service->eligibility($deployment))->toBe([
            'eligible' => false,
            'reason' => 'default_generated_url_only_required',
        ]);
    });

    test('default route plus custom domain app stays eligible with default route as source of truth', function () {
        $uuid = (string) new Cuid2;
        $application = makeOperatorApplication([
            'uuid' => $uuid,
            'fqdn' => generateUrl(test()->server, $uuid).',https://custom.example.com',
        ]);

        $deployment = makeDeployment($application);
        $service = app(ApplicationDeploymentOperatorService::class);

        expect($service->eligibility($deployment))->toBe([
            'eligible' => true,
            'reason' => 'eligible_nixpacks',
        ]);
    });

    test('standalone static app is eligible in verify-only mode', function () {
        $application = makeOperatorApplication([
            'build_pack' => 'static',
        ]);

        $deployment = makeDeployment($application);
        $service = app(ApplicationDeploymentOperatorService::class);

        expect($service->eligibility($deployment))->toBe([
            'eligible' => true,
            'reason' => 'eligible_static_verify_only',
        ]);
    });

    test('railpack app is eligible in verify-only mode', function () {
        $application = makeOperatorApplication([
            'build_pack' => 'railpack',
        ]);

        $deployment = makeDeployment($application);
        $service = app(ApplicationDeploymentOperatorService::class);

        expect($service->eligibility($deployment))->toBe([
            'eligible' => true,
            'reason' => 'eligible_railpack_verify_only',
        ]);
    });

    test('eligible parsed compose app with one public service and one fqdn', function () {
        $application = makeComposeOperatorApplication();
        $deployment = makeDeployment($application);
        $service = app(ApplicationDeploymentOperatorService::class);

        expect($service->eligibility($deployment))->toBe([
            'eligible' => true,
            'reason' => 'eligible_compose_verify_only',
        ])
            ->and($service->deriveVerificationTarget($deployment))->toBe('https://web.example.com');
    });

    test('raw compose app is ineligible', function () {
        $application = makeComposeOperatorApplication();
        $application->forceFill([
            'compose_parsing_version' => 2,
        ])->save();
        $application->refresh();
        $deployment = makeDeployment($application);
        $service = app(ApplicationDeploymentOperatorService::class);

        expect($service->eligibility($deployment))->toBe([
            'eligible' => false,
            'reason' => 'raw_compose_mode',
        ]);
    });

    test('multi-public-service app is ineligible', function () {
        $application = makeComposeOperatorApplication([
            'docker_compose_raw' => <<<'YAML'
services:
  web:
    image: nginx:alpine
  api:
    image: ghcr.io/example/api:latest
  db:
    image: postgres:15
YAML,
            'docker_compose_domains' => json_encode([
                'web' => ['domain' => 'https://web.example.com'],
                'api' => ['domain' => 'https://api.example.com'],
            ], JSON_THROW_ON_ERROR),
        ]);
        $deployment = makeDeployment($application);
        $service = app(ApplicationDeploymentOperatorService::class);

        expect($service->eligibility($deployment))->toBe([
            'eligible' => false,
            'reason' => 'multiple_public_routed_services',
        ]);
    });

    test('multi-domain routed service app is ineligible', function () {
        $application = makeComposeOperatorApplication([
            'docker_compose_domains' => json_encode([
                'web' => ['domain' => 'https://web.example.com,https://alt.example.com'],
            ], JSON_THROW_ON_ERROR),
        ]);
        $deployment = makeDeployment($application);
        $service = app(ApplicationDeploymentOperatorService::class);

        expect($service->eligibility($deployment))->toBe([
            'eligible' => false,
            'reason' => 'multiple_domains_per_routed_service',
        ]);
    });

    test('path-based or port-based compose fqdn is ineligible', function (string $domain) {
        $application = makeComposeOperatorApplication([
            'docker_compose_domains' => json_encode([
                'web' => ['domain' => $domain],
            ], JSON_THROW_ON_ERROR),
        ]);
        $deployment = makeDeployment($application);
        $service = app(ApplicationDeploymentOperatorService::class);

        expect($service->eligibility($deployment))->toBe([
            'eligible' => false,
            'reason' => 'compose_target_must_be_plain_absolute_host_url',
        ]);
    })->with([
        'path-based' => 'https://web.example.com/login',
        'port-based' => 'https://web.example.com:8443',
    ]);

    test('custom compose command app is ineligible', function () {
        $application = makeComposeOperatorApplication([
            'docker_compose_custom_start_command' => 'docker compose up -d',
        ]);
        $deployment = makeDeployment($application);
        $service = app(ApplicationDeploymentOperatorService::class);

        expect($service->eligibility($deployment))->toBe([
            'eligible' => false,
            'reason' => 'custom_compose_commands_present',
        ]);
    });

    test('connect_to_docker_network app is ineligible', function () {
        $application = makeComposeOperatorApplication(settingOverrides: [
            'connect_to_docker_network' => true,
        ]);
        $deployment = makeDeployment($application);
        $service = app(ApplicationDeploymentOperatorService::class);

        expect($service->eligibility($deployment))->toBe([
            'eligible' => false,
            'reason' => 'connect_to_docker_network_enabled',
        ]);
    });

    test('eligible canary row for multi-destination app', function () {
        [$application] = attachAdditionalDestination(makeOperatorApplication());
        $deployment = makeDeployment($application, overrides: [
            'only_this_server' => true,
        ]);
        $service = app(ApplicationDeploymentOperatorService::class);

        expect($service->eligibility($deployment))->toBe([
            'eligible' => true,
            'reason' => 'eligible_multi_destination_canary_verify_only',
        ]);
    });
});

describe('application deployment verification job', function () {
    test('records success only after real verification passes', function () {
        $application = makeOperatorApplication();
        $deployment = makeDeployment($application, status: 'finished');

        Http::fake([
            $application->fqdn => Http::response('', 200),
        ]);

        $job = new ApplicationDeploymentVerificationJob($deployment->id);
        $job->handle(app(ApplicationDeploymentOperatorService::class));

        $deployment->refresh();

        expect($deployment->status)->toBe('finished')
            ->and($deployment->operator_status)->toBe('recorded')
            ->and($deployment->operator_attempt)->toBe(1)
            ->and($deployment->operator_root_deployment_id)->toBe($deployment->id)
            ->and(data_get($deployment->operator_verification, 'result'))->toBe('passed')
            ->and(data_get($deployment->operator_verification, 'target'))->toBe($application->fqdn)
            ->and(data_get($deployment->operator_verification, 'http_status'))->toBe(200)
            ->and(data_get($deployment->operator_verification, 'pass'))->toBeTrue()
            ->and(data_get($deployment->operator_verification, 'reason'))->toBe('http_ok')
            ->and(data_get($deployment->operator_verification, 'custom_domains.summary.total'))->toBe(0)
            ->and(ApplicationDeploymentQueue::count())->toBe(1);
    });

    test('records success for generated default https verification route', function () {
        test()->server->settings->forceFill([
            'wildcard_domain' => 'https://default.example.test',
        ])->save();
        test()->server->refresh();
        test()->destination->refresh();

        $application = makeOperatorApplication();
        $deployment = makeDeployment($application, status: 'finished');

        Http::fake([
            $application->fqdn => Http::response('', 204),
        ]);

        $job = new ApplicationDeploymentVerificationJob($deployment->id);
        $job->handle(app(ApplicationDeploymentOperatorService::class));

        $deployment->refresh();

        expect($application->fqdn)->toStartWith('https://')
            ->and($deployment->operator_status)->toBe('recorded')
            ->and(data_get($deployment->operator_verification, 'target'))->toBe($application->fqdn)
            ->and(data_get($deployment->operator_verification, 'http_status'))->toBe(204)
            ->and(data_get($deployment->operator_verification, 'pass'))->toBeTrue()
            ->and(data_get($deployment->operator_verification, 'reason'))->toBe('http_ok');
    });

    test('selects configured generated https route when it is the expected default route', function () {
        test()->server->settings->forceFill([
            'wildcard_domain' => 'https://default.example.test',
        ])->save();
        test()->server->refresh();
        test()->destination->refresh();

        $application = makeOperatorApplication();

        $deployment = makeDeployment($application);
        $service = app(ApplicationDeploymentOperatorService::class);

        expect($application->fqdn)->toStartWith('https://')
            ->and($service->deriveVerificationTarget($deployment))->toBe($application->fqdn)
            ->and($service->eligibility($deployment))->toBe([
                'eligible' => true,
                'reason' => 'eligible_nixpacks',
            ]);
    });

    test('default route pass plus one custom domain pass is recorded separately', function () {
        $application = makeOperatorApplication([
            'fqdn' => generateUrl(test()->server, (string) new Cuid2).',https://app.example.com',
        ]);
        $application->forceFill([
            'fqdn' => generateUrl(test()->server, $application->uuid).',https://app.example.com',
        ])->save();
        $application->refresh();
        $deployment = makeDeployment($application, status: 'finished');

        Http::fake([
            generateUrl(test()->server, $application->uuid) => Http::response('', 200),
            'https://app.example.com' => Http::response('', 301, ['Location' => 'https://app.example.com/login']),
        ]);

        $job = new ApplicationDeploymentVerificationJob($deployment->id);
        $job->handle(app(ApplicationDeploymentOperatorService::class));

        $deployment->refresh();

        expect($deployment->operator_status)->toBe('recorded')
            ->and(data_get($deployment->operator_verification, 'pass'))->toBeTrue()
            ->and(data_get($deployment->operator_verification, 'custom_domains.summary.total'))->toBe(1)
            ->and(data_get($deployment->operator_verification, 'custom_domains.summary.passed'))->toBe(1)
            ->and(data_get($deployment->operator_verification, 'custom_domains.domains.0.domain'))->toBe('https://app.example.com')
            ->and(data_get($deployment->operator_verification, 'custom_domains.domains.0.result'))->toBe('passed')
            ->and(data_get($deployment->operator_verification, 'custom_domains.domains.0.attempts'))->toBe(1);
    });

    test('transient custom domain exception then success records final passed', function () {
        $application = makeOperatorApplication([
            'fqdn' => generateUrl(test()->server, (string) new Cuid2).',https://app.example.com',
        ]);
        $application->forceFill([
            'fqdn' => generateUrl(test()->server, $application->uuid).',https://app.example.com',
        ])->save();
        $application->refresh();
        $deployment = makeDeployment($application, status: 'finished');

        $customAttempts = 0;
        Http::fake(function ($request) use ($application, &$customAttempts) {
            if ($request->url() === generateUrl(test()->server, $application->uuid)) {
                return Http::response('', 200);
            }

            if ($request->url() === 'https://app.example.com') {
                $customAttempts++;
                if ($customAttempts === 1) {
                    throw new RuntimeException('temporary network issue');
                }

                return Http::response('', 200);
            }

            return Http::response('', 404);
        });

        $job = new ApplicationDeploymentVerificationJob($deployment->id);
        $job->handle(app(ApplicationDeploymentOperatorService::class));

        $deployment->refresh();

        expect($deployment->operator_status)->toBe('recorded')
            ->and(data_get($deployment->operator_verification, 'custom_domains.summary.passed'))->toBe(1)
            ->and(data_get($deployment->operator_verification, 'custom_domains.domains.0.result'))->toBe('passed')
            ->and(data_get($deployment->operator_verification, 'custom_domains.domains.0.attempts'))->toBe(2)
            ->and($customAttempts)->toBe(2);
    });

    test('default route pass plus custom domain pending is recorded as pending without overriding success', function () {
        $application = makeOperatorApplication([
            'fqdn' => generateUrl(test()->server, (string) new Cuid2).',https://app.example.com',
        ]);
        $application->forceFill([
            'fqdn' => generateUrl(test()->server, $application->uuid).',https://app.example.com',
        ])->save();
        $application->refresh();
        $deployment = makeDeployment($application, status: 'finished');

        Http::fake([
            generateUrl(test()->server, $application->uuid) => Http::response('', 200),
            'https://app.example.com' => Http::response('', 503),
        ]);

        $job = new ApplicationDeploymentVerificationJob($deployment->id);
        $job->handle(app(ApplicationDeploymentOperatorService::class));

        $deployment->refresh();

        expect($deployment->operator_status)->toBe('recorded')
            ->and(data_get($deployment->operator_verification, 'pass'))->toBeTrue()
            ->and(data_get($deployment->operator_verification, 'custom_domains.summary.pending'))->toBe(1)
            ->and(data_get($deployment->operator_verification, 'custom_domains.domains.0.result'))->toBe('pending')
            ->and(data_get($deployment->operator_verification, 'custom_domains.domains.0.reason'))->toBe('proxy_warmup')
            ->and(data_get($deployment->operator_verification, 'custom_domains.domains.0.attempts'))->toBe(3);
    });

    test('repeated transient custom domain failures stay pending', function () {
        $application = makeOperatorApplication([
            'fqdn' => generateUrl(test()->server, (string) new Cuid2).',https://app.example.com',
        ]);
        $application->forceFill([
            'fqdn' => generateUrl(test()->server, $application->uuid).',https://app.example.com',
        ])->save();
        $application->refresh();
        $deployment = makeDeployment($application, status: 'finished');

        $customAttempts = 0;
        Http::fake(function ($request) use ($application, &$customAttempts) {
            if ($request->url() === generateUrl(test()->server, $application->uuid)) {
                return Http::response('', 200);
            }

            if ($request->url() === 'https://app.example.com') {
                $customAttempts++;

                throw new RuntimeException('temporary network issue');
            }

            return Http::response('', 404);
        });

        $job = new ApplicationDeploymentVerificationJob($deployment->id);
        $job->handle(app(ApplicationDeploymentOperatorService::class));

        $deployment->refresh();

        expect($deployment->operator_status)->toBe('recorded')
            ->and(data_get($deployment->operator_verification, 'custom_domains.summary.pending'))->toBe(1)
            ->and(data_get($deployment->operator_verification, 'custom_domains.domains.0.result'))->toBe('pending')
            ->and(data_get($deployment->operator_verification, 'custom_domains.domains.0.reason'))->toBe('request_exception')
            ->and(data_get($deployment->operator_verification, 'custom_domains.domains.0.attempts'))->toBe(3)
            ->and($customAttempts)->toBe(3);
    });

    test('default route pass plus custom domain failed is recorded separately without overriding success', function () {
        $application = makeOperatorApplication([
            'fqdn' => generateUrl(test()->server, (string) new Cuid2).',https://app.example.com',
        ]);
        $application->forceFill([
            'fqdn' => generateUrl(test()->server, $application->uuid).',https://app.example.com',
        ])->save();
        $application->refresh();
        $deployment = makeDeployment($application, status: 'finished');

        Http::fake([
            generateUrl(test()->server, $application->uuid) => Http::response('', 200),
            'https://app.example.com' => Http::response('', 302, ['Location' => 'https://wrong.example.com']),
        ]);

        $job = new ApplicationDeploymentVerificationJob($deployment->id);
        $job->handle(app(ApplicationDeploymentOperatorService::class));

        $deployment->refresh();

        expect($deployment->operator_status)->toBe('recorded')
            ->and(data_get($deployment->operator_verification, 'pass'))->toBeTrue()
            ->and(data_get($deployment->operator_verification, 'custom_domains.summary.failed'))->toBe(1)
            ->and(data_get($deployment->operator_verification, 'custom_domains.domains.0.result'))->toBe('failed')
            ->and(data_get($deployment->operator_verification, 'custom_domains.domains.0.reason'))->toBe('redirect_host_mismatch')
            ->and(data_get($deployment->operator_verification, 'custom_domains.domains.0.attempts'))->toBe(1);
    });

    test('finished deployment with failed verification does not get recorded as success', function () {
        $application = makeOperatorApplication();
        $deployment = makeDeployment($application, status: 'finished');

        Http::fake([
            $application->fqdn => Http::response('', 503),
        ]);

        $job = new ApplicationDeploymentVerificationJob($deployment->id);
        $job->handle(app(ApplicationDeploymentOperatorService::class));

        $deployment->refresh();

        expect($deployment->operator_status)->toBe('verification_failed')
            ->and(data_get($deployment->operator_verification, 'result'))->toBe('failed')
            ->and(data_get($deployment->operator_verification, 'target'))->toBe($application->fqdn)
            ->and(data_get($deployment->operator_verification, 'http_status'))->toBe(503)
            ->and(data_get($deployment->operator_verification, 'pass'))->toBeFalse()
            ->and(data_get($deployment->operator_verification, 'reason'))->toBe('http_error');
    });

    test('successful compose verification pass', function () {
        $application = makeComposeOperatorApplication();
        $deployment = makeDeployment($application, status: 'finished');

        Http::fake([
            'https://web.example.com' => Http::response('', 302, ['Location' => 'https://web.example.com/login']),
            'https://generic-app.example.com' => Http::response('', 503),
        ]);

        $job = new ApplicationDeploymentVerificationJob($deployment->id);
        $job->handle(app(ApplicationDeploymentOperatorService::class));

        $deployment->refresh();

        expect($deployment->operator_status)->toBe('recorded')
            ->and($deployment->operator_rule)->toBeNull()
            ->and(data_get($deployment->operator_verification, 'mode'))->toBe('compose_verify_only')
            ->and(data_get($deployment->operator_verification, 'selected_service_name'))->toBe('web')
            ->and(data_get($deployment->operator_verification, 'target'))->toBe('https://web.example.com')
            ->and(data_get($deployment->operator_verification, 'result'))->toBe('passed')
            ->and(data_get($deployment->operator_verification, 'http_status'))->toBe(302)
            ->and(data_get($deployment->operator_verification, 'pass'))->toBeTrue()
            ->and(data_get($deployment->operator_verification, 'reason'))->toBe('http_redirect');

        Http::assertSentCount(1);
        Http::assertSent(fn ($request) => $request->url() === 'https://web.example.com');
    });

    test('compose verification failure on bad response', function () {
        $application = makeComposeOperatorApplication();
        $deployment = makeDeployment($application, status: 'finished');

        Http::fake([
            'https://web.example.com' => Http::response('', 503),
        ]);

        $job = new ApplicationDeploymentVerificationJob($deployment->id);
        $job->handle(app(ApplicationDeploymentOperatorService::class));

        $deployment->refresh();

        expect($deployment->operator_status)->toBe('verification_failed')
            ->and($deployment->operator_rule)->toBeNull()
            ->and(data_get($deployment->operator_verification, 'mode'))->toBe('compose_verify_only')
            ->and(data_get($deployment->operator_verification, 'selected_service_name'))->toBe('web')
            ->and(data_get($deployment->operator_verification, 'target'))->toBe('https://web.example.com')
            ->and(data_get($deployment->operator_verification, 'result'))->toBe('failed')
            ->and(data_get($deployment->operator_verification, 'http_status'))->toBe(503)
            ->and(data_get($deployment->operator_verification, 'pass'))->toBeFalse()
            ->and(data_get($deployment->operator_verification, 'reason'))->toBe('http_error');
    });

    test('standalone static finished deployment records success on passing verification', function () {
        $application = makeOperatorApplication([
            'build_pack' => 'static',
        ]);
        $deployment = makeDeployment($application, status: 'finished');

        Http::fake([
            $application->fqdn => Http::response('', 200),
        ]);

        $job = new ApplicationDeploymentVerificationJob($deployment->id);
        $job->handle(app(ApplicationDeploymentOperatorService::class));

        $deployment->refresh();

        expect($deployment->operator_status)->toBe('recorded')
            ->and(data_get($deployment->operator_verification, 'result'))->toBe('passed')
            ->and(data_get($deployment->operator_verification, 'target'))->toBe($application->fqdn)
            ->and(data_get($deployment->operator_verification, 'http_status'))->toBe(200)
            ->and(data_get($deployment->operator_verification, 'pass'))->toBeTrue()
            ->and(data_get($deployment->operator_verification, 'reason'))->toBe('http_ok');
    });

    test('railpack finished deployment records success on passing verification', function () {
        $application = makeOperatorApplication([
            'build_pack' => 'railpack',
        ]);
        $deployment = makeDeployment($application, status: 'finished');

        Http::fake([
            $application->fqdn => Http::response('', 200),
        ]);

        $job = new ApplicationDeploymentVerificationJob($deployment->id);
        $job->handle(app(ApplicationDeploymentOperatorService::class));

        $deployment->refresh();

        expect($deployment->operator_status)->toBe('recorded')
            ->and(data_get($deployment->operator_verification, 'result'))->toBe('passed')
            ->and(data_get($deployment->operator_verification, 'target'))->toBe($application->fqdn)
            ->and(data_get($deployment->operator_verification, 'http_status'))->toBe(200)
            ->and(data_get($deployment->operator_verification, 'pass'))->toBeTrue()
            ->and(data_get($deployment->operator_verification, 'reason'))->toBe('http_ok');
    });

    test('standalone static finished deployment records verification failure on bad verification', function () {
        $application = makeOperatorApplication([
            'build_pack' => 'static',
        ]);
        $deployment = makeDeployment($application, status: 'finished');

        Http::fake([
            $application->fqdn => Http::response('', 503),
        ]);

        $job = new ApplicationDeploymentVerificationJob($deployment->id);
        $job->handle(app(ApplicationDeploymentOperatorService::class));

        $deployment->refresh();

        expect($deployment->operator_status)->toBe('verification_failed')
            ->and(data_get($deployment->operator_verification, 'result'))->toBe('failed')
            ->and(data_get($deployment->operator_verification, 'target'))->toBe($application->fqdn)
            ->and(data_get($deployment->operator_verification, 'http_status'))->toBe(503)
            ->and(data_get($deployment->operator_verification, 'pass'))->toBeFalse()
            ->and(data_get($deployment->operator_verification, 'reason'))->toBe('http_error');
    });

    test('railpack finished deployment records verification failure on bad verification', function () {
        $application = makeOperatorApplication([
            'build_pack' => 'railpack',
        ]);
        $deployment = makeDeployment($application, status: 'finished');

        Http::fake([
            $application->fqdn => Http::response('', 503),
        ]);

        $job = new ApplicationDeploymentVerificationJob($deployment->id);
        $job->handle(app(ApplicationDeploymentOperatorService::class));

        $deployment->refresh();

        expect($deployment->operator_status)->toBe('verification_failed')
            ->and(data_get($deployment->operator_verification, 'result'))->toBe('failed')
            ->and(data_get($deployment->operator_verification, 'target'))->toBe($application->fqdn)
            ->and(data_get($deployment->operator_verification, 'http_status'))->toBe(503)
            ->and(data_get($deployment->operator_verification, 'pass'))->toBeFalse()
            ->and(data_get($deployment->operator_verification, 'reason'))->toBe('http_error');
    });

    test('default route verification failure skips custom domain probing', function () {
        $application = makeOperatorApplication([
            'fqdn' => generateUrl(test()->server, (string) new Cuid2).',https://app.example.com',
        ]);
        $application->forceFill([
            'fqdn' => generateUrl(test()->server, $application->uuid).',https://app.example.com',
        ])->save();
        $application->refresh();
        $deployment = makeDeployment($application, status: 'finished');

        Http::fake([
            generateUrl(test()->server, $application->uuid) => Http::response('', 503),
            'https://app.example.com' => Http::response('', 200),
        ]);

        $job = new ApplicationDeploymentVerificationJob($deployment->id);
        $job->handle(app(ApplicationDeploymentOperatorService::class));

        $deployment->refresh();

        expect($deployment->operator_status)->toBe('verification_failed')
            ->and(data_get($deployment->operator_verification, 'custom_domains'))->toBeNull();

        Http::assertSentCount(1);
    });

    test('hard caps attempts at two even when setting is higher', function () {
        $application = makeOperatorApplication();
        $application->settings->forceFill(['deployment_operator_max_attempts' => 10])->save();
        $application->environment_variables()->where('key', 'NIXPACKS_NODE_VERSION')->delete();
        $application->environment_variables_preview()->where('key', 'NIXPACKS_NODE_VERSION')->delete();
        $deployment = makeDeployment($application, overrides: [
            'logs' => deploymentOperatorLogs('⚠️ NIXPACKS_NODE_VERSION not set. Nixpacks will use Node.js 18 by default, which is EOL.'),
            'operator_attempt' => 2,
            'operator_root_deployment_id' => 999,
        ]);

        $job = new ApplicationDeploymentVerificationJob($deployment->id);
        $job->handle(app(ApplicationDeploymentOperatorService::class));

        $deployment->refresh();

        expect($deployment->operator_status)->toBe('max_attempts_reached')
            ->and(data_get($deployment->operator_verification, 'reason'))->toBe('max_attempts_reached')
            ->and(ApplicationDeploymentQueue::count())->toBe(1)
            ->and(EnvironmentVariable::query()->where('resourceable_id', $application->id)->where('key', 'NIXPACKS_NODE_VERSION')->where('is_preview', false)->first()?->real_value)->toBe('22');
    });

    test('tailwind oxide remediation queues a force rebuild retry once', function () {
        $application = makeOperatorApplication();
        $application->environment_variables()->where('key', 'NIXPACKS_NODE_VERSION')->delete();
        $application->environment_variables_preview()->where('key', 'NIXPACKS_NODE_VERSION')->delete();
        $deployment = makeDeployment($application, overrides: [
            'logs' => deploymentOperatorLogs("Error: Cannot find module '@tailwindcss/oxide-linux-x64-gnu'"),
        ]);

        $job = new ApplicationDeploymentVerificationJob($deployment->id);
        $job->handle(app(ApplicationDeploymentOperatorService::class));

        $deployment->refresh();
        $retryDeployment = ApplicationDeploymentQueue::query()->where('id', '!=', $deployment->id)->latest('id')->first();

        expect($deployment->operator_status)->toBe('retry_queued')
            ->and($retryDeployment)->not->toBeNull()
            ->and((bool) $retryDeployment?->force_rebuild)->toBeTrue()
            ->and($retryDeployment?->operator_attempt)->toBe(2)
            ->and($retryDeployment?->operator_root_deployment_id)->toBe($deployment->id)
            ->and($retryDeployment?->operator_rule)->toBe('set_nixpacks_node22')
            ->and(EnvironmentVariable::query()->where('resourceable_id', $application->id)->where('key', 'NIXPACKS_NODE_VERSION')->where('is_preview', false)->first()?->real_value)->toBe('22');
    });

    test('retry is not marked queued when retry row is not created', function () {
        $application = makeOperatorApplication();
        $application->environment_variables()->where('key', 'NIXPACKS_NODE_VERSION')->delete();
        $application->environment_variables_preview()->where('key', 'NIXPACKS_NODE_VERSION')->delete();
        $application->destination->server->settings->forceFill(['deployment_queue_limit' => 0])->save();

        $deployment = makeDeployment($application, overrides: [
            'logs' => deploymentOperatorLogs('⚠️ NIXPACKS_NODE_VERSION not set. Nixpacks will use Node.js 18 by default, which is EOL.'),
        ]);

        $job = new ApplicationDeploymentVerificationJob($deployment->id);
        $job->handle(app(ApplicationDeploymentOperatorService::class));

        $deployment->refresh();

        expect($deployment->operator_status)->toBe('no_retry')
            ->and(data_get($deployment->operator_verification, 'reason'))->toBe('queue_full')
            ->and(ApplicationDeploymentQueue::count())->toBe(1);
    });

    test('standalone static never queues retry or remediation', function () {
        $application = makeOperatorApplication([
            'build_pack' => 'static',
        ]);
        $deployment = makeDeployment($application, overrides: [
            'logs' => deploymentOperatorLogs('⚠️ NIXPACKS_NODE_VERSION not set. Nixpacks will use Node.js 18 by default, which is EOL.'),
        ]);

        $job = new ApplicationDeploymentVerificationJob($deployment->id);
        $job->handle(app(ApplicationDeploymentOperatorService::class));

        $deployment->refresh();

        expect($deployment->operator_status)->toBe('recorded')
            ->and(data_get($deployment->operator_verification, 'result'))->toBe('recorded')
            ->and(data_get($deployment->operator_verification, 'classification'))->toBe('standalone_static_verify_only')
            ->and(data_get($deployment->operator_verification, 'target'))->toBe($application->fqdn)
            ->and(ApplicationDeploymentQueue::count())->toBe(1)
            ->and(EnvironmentVariable::query()->where('resourceable_id', $application->id)->where('key', 'NIXPACKS_NODE_VERSION')->count())->toBe(0);
    });

    test('railpack failed deployment never queues retry or remediation', function () {
        $application = makeOperatorApplication([
            'build_pack' => 'railpack',
        ]);
        $deployment = makeDeployment($application, overrides: [
            'logs' => deploymentOperatorLogs('Error: build failed'),
        ]);

        $job = new ApplicationDeploymentVerificationJob($deployment->id);
        $job->handle(app(ApplicationDeploymentOperatorService::class));

        $deployment->refresh();

        expect($deployment->operator_status)->toBe('recorded')
            ->and(data_get($deployment->operator_verification, 'result'))->toBe('recorded')
            ->and(data_get($deployment->operator_verification, 'classification'))->toBe('railpack_verify_only')
            ->and(data_get($deployment->operator_verification, 'target'))->toBe($application->fqdn)
            ->and(ApplicationDeploymentQueue::count())->toBe(1)
            ->and(EnvironmentVariable::query()->where('resourceable_id', $application->id)->where('key', 'NIXPACKS_NODE_VERSION')->count())->toBe(0);
    });

    test('superseded deployment does not mutate application config', function () {
        $application = makeOperatorApplication();
        $application->environment_variables()->where('key', 'NIXPACKS_NODE_VERSION')->delete();
        $application->environment_variables_preview()->where('key', 'NIXPACKS_NODE_VERSION')->delete();
        $deployment = makeDeployment($application, overrides: [
            'logs' => deploymentOperatorLogs('⚠️ NIXPACKS_NODE_VERSION not set. Nixpacks will use Node.js 18 by default, which is EOL.'),
        ]);
        makeDeployment($application, status: 'queued');

        $job = new ApplicationDeploymentVerificationJob($deployment->id);
        $job->handle(app(ApplicationDeploymentOperatorService::class));

        $deployment->refresh();
        $application->refresh();

        expect($deployment->operator_status)->toBe('superseded')
            ->and(EnvironmentVariable::query()->where('resourceable_id', $application->id)->where('key', 'NIXPACKS_NODE_VERSION')->count())->toBe(0)
            ->and($application->build_pack)->toBe('nixpacks')
            ->and(ApplicationDeploymentQueue::count())->toBe(2);
    });

    test('older compose deployment becomes superseded when newer row exists for same application', function () {
        $application = makeComposeOperatorApplication();
        $deployment = makeDeployment($application, overrides: [
            'logs' => deploymentOperatorLogs('Error: build failed'),
        ]);
        makeDeployment($application, status: 'queued');

        $job = new ApplicationDeploymentVerificationJob($deployment->id);
        $job->handle(app(ApplicationDeploymentOperatorService::class));

        $deployment->refresh();

        expect($deployment->operator_status)->toBe('superseded')
            ->and($deployment->operator_rule)->toBeNull()
            ->and(data_get($deployment->operator_verification, 'mode'))->toBe('compose_verify_only')
            ->and(data_get($deployment->operator_verification, 'selected_service_name'))->toBe('web')
            ->and(data_get($deployment->operator_verification, 'target'))->toBe('https://web.example.com')
            ->and(data_get($deployment->operator_verification, 'result'))->toBe('skipped')
            ->and(data_get($deployment->operator_verification, 'reason'))->toBe('newer_deployment_exists')
            ->and(ApplicationDeploymentQueue::count())->toBe(2);
    });

    test('compose verify-only slice has no retry or config mutation side effects', function () {
        $application = makeComposeOperatorApplication();
        $deployment = makeDeployment($application, overrides: [
            'logs' => deploymentOperatorLogs('⚠️ NIXPACKS_NODE_VERSION not set. Nixpacks will use Node.js 18 by default, which is EOL.'),
        ]);

        Http::fake([
            'https://web.example.com' => Http::response('', 503),
        ]);

        $job = new ApplicationDeploymentVerificationJob($deployment->id);
        $job->handle(app(ApplicationDeploymentOperatorService::class));

        $deployment->refresh();
        $application->refresh();

        expect($deployment->operator_status)->toBe('verification_failed')
            ->and($deployment->operator_rule)->toBeNull()
            ->and(ApplicationDeploymentQueue::count())->toBe(1)
            ->and(EnvironmentVariable::query()->where('resourceable_id', $application->id)->where('key', 'NIXPACKS_NODE_VERSION')->count())->toBe(0)
            ->and($application->docker_compose_domains)->toBe(json_encode([
                'web' => ['domain' => 'https://web.example.com'],
            ], JSON_THROW_ON_ERROR))
            ->and($application->fqdn)->toBe('https://generic-app.example.com');
    });

    test('non-primary destination row skipped', function () {
        [$application, $secondaryServer, $secondaryDestination] = attachAdditionalDestination(makeOperatorApplication());
        $deployment = makeDeployment($application, status: 'finished', overrides: [
            'server_id' => $secondaryServer->id,
            'server_name' => $secondaryServer->name,
            'destination_id' => $secondaryDestination->id,
            'only_this_server' => true,
        ]);

        Http::fake();

        $job = new ApplicationDeploymentVerificationJob($deployment->id);
        $job->handle(app(ApplicationDeploymentOperatorService::class));

        $deployment->refresh();

        expect($deployment->operator_status)->toBe('ineligible')
            ->and($deployment->operator_rule)->toBeNull()
            ->and(data_get($deployment->operator_verification, 'mode'))->toBe('multi_destination_canary_verify_only')
            ->and(data_get($deployment->operator_verification, 'treated_as_canary'))->toBeFalse()
            ->and(data_get($deployment->operator_verification, 'primary_destination_id'))->toBe($application->destination_id)
            ->and(data_get($deployment->operator_verification, 'deployment_destination_id'))->toBe($secondaryDestination->id)
            ->and(data_get($deployment->operator_verification, 'additional_destination_count'))->toBe(1)
            ->and(data_get($deployment->operator_verification, 'result'))->toBe('skipped')
            ->and(data_get($deployment->operator_verification, 'reason'))->toBe('non_primary_destination_row');

        Http::assertNothingSent();
    });

    test('full-rollout row skipped for multi-destination app', function () {
        [$application] = attachAdditionalDestination(makeOperatorApplication());
        $deployment = makeDeployment($application, status: 'finished', overrides: [
            'only_this_server' => false,
        ]);

        Http::fake();

        $job = new ApplicationDeploymentVerificationJob($deployment->id);
        $job->handle(app(ApplicationDeploymentOperatorService::class));

        $deployment->refresh();

        expect($deployment->operator_status)->toBe('ineligible')
            ->and($deployment->operator_rule)->toBeNull()
            ->and(data_get($deployment->operator_verification, 'mode'))->toBe('multi_destination_canary_verify_only')
            ->and(data_get($deployment->operator_verification, 'treated_as_canary'))->toBeFalse()
            ->and(data_get($deployment->operator_verification, 'primary_destination_id'))->toBe($application->destination_id)
            ->and(data_get($deployment->operator_verification, 'deployment_destination_id'))->toBe($application->destination_id)
            ->and(data_get($deployment->operator_verification, 'additional_destination_count'))->toBe(1)
            ->and(data_get($deployment->operator_verification, 'result'))->toBe('skipped')
            ->and(data_get($deployment->operator_verification, 'reason'))->toBe('full_rollout_row');

        Http::assertNothingSent();
    });

    test('older canary superseded by newer canary row', function () {
        [$application] = attachAdditionalDestination(makeOperatorApplication());
        $deployment = makeDeployment($application, overrides: [
            'only_this_server' => true,
        ]);
        makeDeployment($application, status: 'queued', overrides: [
            'only_this_server' => true,
        ]);

        Http::fake();

        $job = new ApplicationDeploymentVerificationJob($deployment->id);
        $job->handle(app(ApplicationDeploymentOperatorService::class));

        $deployment->refresh();

        expect($deployment->operator_status)->toBe('superseded')
            ->and($deployment->operator_rule)->toBeNull()
            ->and(data_get($deployment->operator_verification, 'mode'))->toBe('multi_destination_canary_verify_only')
            ->and(data_get($deployment->operator_verification, 'treated_as_canary'))->toBeTrue()
            ->and(data_get($deployment->operator_verification, 'result'))->toBe('skipped')
            ->and(data_get($deployment->operator_verification, 'reason'))->toBe('newer_primary_deployment_exists');

        Http::assertNothingSent();
    });

    test('older canary superseded by newer primary full-rollout row', function () {
        [$application] = attachAdditionalDestination(makeOperatorApplication());
        $deployment = makeDeployment($application, overrides: [
            'only_this_server' => true,
        ]);
        makeDeployment($application, status: 'queued', overrides: [
            'only_this_server' => false,
        ]);

        Http::fake();

        $job = new ApplicationDeploymentVerificationJob($deployment->id);
        $job->handle(app(ApplicationDeploymentOperatorService::class));

        $deployment->refresh();

        expect($deployment->operator_status)->toBe('superseded')
            ->and(data_get($deployment->operator_verification, 'mode'))->toBe('multi_destination_canary_verify_only')
            ->and(data_get($deployment->operator_verification, 'treated_as_canary'))->toBeTrue()
            ->and(data_get($deployment->operator_verification, 'reason'))->toBe('newer_primary_deployment_exists');

        Http::assertNothingSent();
    });

    test('newer non-primary destination row does not supersede canary row', function () {
        [$application, $secondaryServer, $secondaryDestination] = attachAdditionalDestination(makeOperatorApplication());
        $deployment = makeDeployment($application, status: 'finished', overrides: [
            'only_this_server' => true,
        ]);
        makeDeployment($application, status: 'queued', overrides: [
            'server_id' => $secondaryServer->id,
            'server_name' => $secondaryServer->name,
            'destination_id' => $secondaryDestination->id,
            'only_this_server' => true,
        ]);

        Http::fake([
            $application->fqdn => Http::response('', 200),
        ]);

        $job = new ApplicationDeploymentVerificationJob($deployment->id);
        $job->handle(app(ApplicationDeploymentOperatorService::class));

        $deployment->refresh();

        expect($deployment->operator_status)->toBe('recorded')
            ->and(data_get($deployment->operator_verification, 'mode'))->toBe('multi_destination_canary_verify_only')
            ->and(data_get($deployment->operator_verification, 'treated_as_canary'))->toBeTrue()
            ->and(data_get($deployment->operator_verification, 'target'))->toBe($application->fqdn)
            ->and(data_get($deployment->operator_verification, 'result'))->toBe('passed');

        Http::assertSentCount(1);
    });

    test('verification pass on canary target', function () {
        [$application] = attachAdditionalDestination(makeOperatorApplication());
        $deployment = makeDeployment($application, status: 'finished', overrides: [
            'only_this_server' => true,
        ]);

        Http::fake([
            $application->fqdn => Http::response('', 302, ['Location' => $application->fqdn.'/login']),
        ]);

        $job = new ApplicationDeploymentVerificationJob($deployment->id);
        $job->handle(app(ApplicationDeploymentOperatorService::class));

        $deployment->refresh();

        expect($deployment->operator_status)->toBe('recorded')
            ->and(data_get($deployment->operator_verification, 'mode'))->toBe('multi_destination_canary_verify_only')
            ->and(data_get($deployment->operator_verification, 'treated_as_canary'))->toBeTrue()
            ->and(data_get($deployment->operator_verification, 'primary_destination_id'))->toBe($application->destination_id)
            ->and(data_get($deployment->operator_verification, 'deployment_destination_id'))->toBe($application->destination_id)
            ->and(data_get($deployment->operator_verification, 'additional_destination_count'))->toBe(1)
            ->and(data_get($deployment->operator_verification, 'target'))->toBe($application->fqdn)
            ->and(data_get($deployment->operator_verification, 'result'))->toBe('passed')
            ->and(data_get($deployment->operator_verification, 'http_status'))->toBe(302)
            ->and(data_get($deployment->operator_verification, 'pass'))->toBeTrue()
            ->and(data_get($deployment->operator_verification, 'reason'))->toBe('http_redirect');
    });

    test('verification fail on canary target', function () {
        [$application] = attachAdditionalDestination(makeOperatorApplication());
        $deployment = makeDeployment($application, status: 'finished', overrides: [
            'only_this_server' => true,
        ]);

        Http::fake([
            $application->fqdn => Http::response('', 302, ['Location' => 'https://wrong.example.com']),
        ]);

        $job = new ApplicationDeploymentVerificationJob($deployment->id);
        $job->handle(app(ApplicationDeploymentOperatorService::class));

        $deployment->refresh();

        expect($deployment->operator_status)->toBe('verification_failed')
            ->and(data_get($deployment->operator_verification, 'mode'))->toBe('multi_destination_canary_verify_only')
            ->and(data_get($deployment->operator_verification, 'treated_as_canary'))->toBeTrue()
            ->and(data_get($deployment->operator_verification, 'target'))->toBe($application->fqdn)
            ->and(data_get($deployment->operator_verification, 'result'))->toBe('failed')
            ->and(data_get($deployment->operator_verification, 'http_status'))->toBe(302)
            ->and(data_get($deployment->operator_verification, 'pass'))->toBeFalse()
            ->and(data_get($deployment->operator_verification, 'reason'))->toBe('redirect_host_mismatch');
    });

    test('multi-destination canary slice has no retry config mutation or fanout side effects', function () {
        [$application] = attachAdditionalDestination(makeOperatorApplication());
        $application->environment_variables()->where('key', 'NIXPACKS_NODE_VERSION')->delete();
        $application->environment_variables_preview()->where('key', 'NIXPACKS_NODE_VERSION')->delete();
        $deployment = makeDeployment($application, overrides: [
            'only_this_server' => true,
            'logs' => deploymentOperatorLogs('⚠️ NIXPACKS_NODE_VERSION not set. Nixpacks will use Node.js 18 by default, which is EOL.'),
        ]);

        Http::fake([
            $application->fqdn => Http::response('', 503),
        ]);

        $job = new ApplicationDeploymentVerificationJob($deployment->id);
        $job->handle(app(ApplicationDeploymentOperatorService::class));

        $deployment->refresh();
        $application->refresh();

        expect($deployment->operator_status)->toBe('verification_failed')
            ->and($deployment->operator_rule)->toBeNull()
            ->and(data_get($deployment->operator_verification, 'mode'))->toBe('multi_destination_canary_verify_only')
            ->and(data_get($deployment->operator_verification, 'treated_as_canary'))->toBeTrue()
            ->and(data_get($deployment->operator_verification, 'reason'))->toBe('http_error')
            ->and(ApplicationDeploymentQueue::count())->toBe(1)
            ->and(EnvironmentVariable::query()->where('resourceable_id', $application->id)->where('key', 'NIXPACKS_NODE_VERSION')->count())->toBe(0)
            ->and($application->build_pack)->toBe('nixpacks')
            ->and($application->fqdn)->toBe($deployment->application->fqdn);

        Http::assertSentCount(1);
        Http::assertSent(fn ($request) => $request->url() === $application->fqdn);
    });

    test('eligible preview deployment for supported build pack stays record only when unfinished', function () {
        $application = makeOperatorApplication();
        $application->environment_variables()->where('key', 'NIXPACKS_NODE_VERSION')->delete();
        $application->environment_variables_preview()->where('key', 'NIXPACKS_NODE_VERSION')->delete();
        makePreview($application, 42);
        $deployment = makeDeployment($application, overrides: [
            'pull_request_id' => 42,
            'logs' => deploymentOperatorLogs('⚠️ NIXPACKS_NODE_VERSION not set. Nixpacks will use Node.js 18 by default, which is EOL.'),
        ]);

        $job = new ApplicationDeploymentVerificationJob($deployment->id);
        $job->handle(app(ApplicationDeploymentOperatorService::class));

        $deployment->refresh();

        expect($deployment->operator_status)->toBe('recorded')
            ->and($deployment->operator_rule)->toBeNull()
            ->and(data_get($deployment->operator_verification, 'mode'))->toBe('preview_verify_only')
            ->and(data_get($deployment->operator_verification, 'pull_request_id'))->toBe(42)
            ->and(data_get($deployment->operator_verification, 'lineage_key'))->toBe($application->id.':42')
            ->and(data_get($deployment->operator_verification, 'result'))->toBe('recorded')
            ->and(data_get($deployment->operator_verification, 'reason'))->toBe('deployment_not_finished')
            ->and(data_get($deployment->operator_verification, 'targets.0.target'))->toBe('https://pr-42.example.com')
            ->and(data_get($deployment->operator_verification, 'targets.0.result'))->toBe('not_probed')
            ->and(ApplicationDeploymentQueue::count())->toBe(1)
            ->and(EnvironmentVariable::query()->where('resourceable_id', $application->id)->where('key', 'NIXPACKS_NODE_VERSION')->count())->toBe(0);
    });

    test('preview with missing preview fqdn target set is ineligible', function () {
        $application = makeOperatorApplication();
        makePreview($application, 42, ['fqdn' => '']);
        $deployment = makeDeployment($application, overrides: [
            'pull_request_id' => 42,
        ]);

        $job = new ApplicationDeploymentVerificationJob($deployment->id);
        $job->handle(app(ApplicationDeploymentOperatorService::class));

        $deployment->refresh();

        expect($deployment->operator_status)->toBe('ineligible')
            ->and($deployment->operator_rule)->toBeNull()
            ->and(data_get($deployment->operator_verification, 'mode'))->toBe('preview_verify_only')
            ->and(data_get($deployment->operator_verification, 'result'))->toBe('skipped')
            ->and(data_get($deployment->operator_verification, 'reason'))->toBe('missing_preview_fqdn_target_set')
            ->and(data_get($deployment->operator_verification, 'targets'))->toBe([]);
    });

    test('preview with ambiguous fqdn target set is ineligible', function () {
        $application = makeOperatorApplication();
        makePreview($application, 42, ['fqdn' => $application->fqdn]);
        $deployment = makeDeployment($application, overrides: [
            'pull_request_id' => 42,
        ]);

        $job = new ApplicationDeploymentVerificationJob($deployment->id);
        $job->handle(app(ApplicationDeploymentOperatorService::class));

        $deployment->refresh();

        expect($deployment->operator_status)->toBe('ineligible')
            ->and(data_get($deployment->operator_verification, 'result'))->toBe('skipped')
            ->and(data_get($deployment->operator_verification, 'reason'))->toBe('ambiguous_preview_fqdn_target_set');
    });

    test('latest preview passes on healthy preview fqdn only', function () {
        $application = makeOperatorApplication();
        $preview = makePreview($application, 42, ['fqdn' => 'https://pr-42.example.com,https://alt-pr-42.example.com']);
        $deployment = makeDeployment($application, status: 'finished', overrides: [
            'pull_request_id' => 42,
        ]);

        Http::fake([
            'https://pr-42.example.com' => Http::response('', 200),
            'https://alt-pr-42.example.com' => Http::response('', 302, ['Location' => 'https://pr-42.example.com/login']),
            $application->fqdn => Http::response('', 503),
        ]);

        $job = new ApplicationDeploymentVerificationJob($deployment->id);
        $job->handle(app(ApplicationDeploymentOperatorService::class));

        $deployment->refresh();

        expect($deployment->operator_status)->toBe('recorded')
            ->and($deployment->operator_rule)->toBeNull()
            ->and(data_get($deployment->operator_verification, 'mode'))->toBe('preview_verify_only')
            ->and(data_get($deployment->operator_verification, 'preview_deployment_id'))->toBe($preview->id)
            ->and(data_get($deployment->operator_verification, 'result'))->toBe('passed')
            ->and(data_get($deployment->operator_verification, 'reason'))->toBe('all_preview_targets_passed')
            ->and(data_get($deployment->operator_verification, 'targets.0.target'))->toBe('https://pr-42.example.com')
            ->and(data_get($deployment->operator_verification, 'targets.1.target'))->toBe('https://alt-pr-42.example.com')
            ->and(data_get($deployment->operator_verification, 'targets.1.reason'))->toBe('http_redirect');

        Http::assertSentCount(2);
    });

    test('preview fails on redirect to primary default host', function () {
        $application = makeOperatorApplication();
        makePreview($application, 42);
        $deployment = makeDeployment($application, status: 'finished', overrides: [
            'pull_request_id' => 42,
        ]);

        Http::fake([
            'https://pr-42.example.com' => Http::response('', 302, ['Location' => $application->fqdn]),
        ]);

        $job = new ApplicationDeploymentVerificationJob($deployment->id);
        $job->handle(app(ApplicationDeploymentOperatorService::class));

        $deployment->refresh();

        expect($deployment->operator_status)->toBe('verification_failed')
            ->and($deployment->operator_rule)->toBeNull()
            ->and(data_get($deployment->operator_verification, 'result'))->toBe('failed')
            ->and(data_get($deployment->operator_verification, 'reason'))->toBe('redirect_host_mismatch')
            ->and(data_get($deployment->operator_verification, 'targets.0.pass'))->toBeFalse();
    });

    test('preview fails on request exception', function () {
        $application = makeOperatorApplication();
        makePreview($application, 42);
        $deployment = makeDeployment($application, status: 'finished', overrides: [
            'pull_request_id' => 42,
        ]);

        Http::fake(function () {
            throw new RuntimeException('preview network issue');
        });

        $job = new ApplicationDeploymentVerificationJob($deployment->id);
        $job->handle(app(ApplicationDeploymentOperatorService::class));

        $deployment->refresh();

        expect($deployment->operator_status)->toBe('verification_failed')
            ->and($deployment->operator_rule)->toBeNull()
            ->and(data_get($deployment->operator_verification, 'result'))->toBe('failed')
            ->and(data_get($deployment->operator_verification, 'reason'))->toBe('request_exception')
            ->and(data_get($deployment->operator_verification, 'targets.0.reason'))->toBe('request_exception');
    });

    test('older preview row becomes superseded when newer row exists for same application and pr', function () {
        $application = makeOperatorApplication();
        $application->environment_variables()->where('key', 'NIXPACKS_NODE_VERSION')->delete();
        $application->environment_variables_preview()->where('key', 'NIXPACKS_NODE_VERSION')->delete();
        makePreview($application, 42);
        $deployment = makeDeployment($application, overrides: [
            'pull_request_id' => 42,
            'logs' => deploymentOperatorLogs('⚠️ NIXPACKS_NODE_VERSION not set. Nixpacks will use Node.js 18 by default, which is EOL.'),
        ]);
        makeDeployment($application, status: 'queued', overrides: [
            'pull_request_id' => 42,
        ]);

        $job = new ApplicationDeploymentVerificationJob($deployment->id);
        $job->handle(app(ApplicationDeploymentOperatorService::class));

        $deployment->refresh();

        expect($deployment->operator_status)->toBe('superseded')
            ->and($deployment->operator_rule)->toBeNull()
            ->and(data_get($deployment->operator_verification, 'mode'))->toBe('preview_verify_only')
            ->and(data_get($deployment->operator_verification, 'reason'))->toBe('newer_deployment_exists')
            ->and(ApplicationDeploymentQueue::count())->toBe(2)
            ->and(EnvironmentVariable::query()->where('resourceable_id', $application->id)->where('key', 'NIXPACKS_NODE_VERSION')->count())->toBe(0);
    });

    test('different prs on same app do not supersede each other', function () {
        $application = makeOperatorApplication();
        makePreview($application, 42);
        makePreview($application, 43, ['fqdn' => 'https://pr-43.example.com']);
        $deployment = makeDeployment($application, status: 'finished', overrides: [
            'pull_request_id' => 42,
        ]);
        makeDeployment($application, status: 'queued', overrides: [
            'pull_request_id' => 43,
        ]);

        Http::fake([
            'https://pr-42.example.com' => Http::response('', 200),
        ]);

        $job = new ApplicationDeploymentVerificationJob($deployment->id);
        $job->handle(app(ApplicationDeploymentOperatorService::class));

        $deployment->refresh();

        expect($deployment->operator_status)->toBe('recorded')
            ->and(data_get($deployment->operator_verification, 'lineage_key'))->toBe($application->id.':42')
            ->and(data_get($deployment->operator_verification, 'result'))->toBe('passed')
            ->and(data_get($deployment->operator_verification, 'targets.0.target'))->toBe('https://pr-42.example.com');
    });
});

describe('application deployment job verifier dispatch', function () {
    test('application deployment job dispatches verifier on success path', function () {
        Bus::fake([GetContainersStatus::class, ApplicationDeploymentVerificationJob::class]);
        Notification::fake();

        $application = makeOperatorApplication();
        $deployment = makeDeployment($application, status: 'in_progress');

        Event::fake();

        $job = (new ReflectionClass(ApplicationDeploymentJob::class))->newInstanceWithoutConstructor();
        $reflection = new ReflectionClass(ApplicationDeploymentJob::class);

        foreach ([
            'application' => $application,
            'application_deployment_queue' => $deployment,
            'deployment_uuid' => $deployment->deployment_uuid,
            'pull_request_id' => 0,
            'server' => $application->destination->server,
            'only_this_server' => true,
        ] as $property => $value) {
            $instanceProperty = $reflection->getProperty($property);
            $instanceProperty->setAccessible(true);
            $instanceProperty->setValue($job, $value);
        }

        $method = $reflection->getMethod('post_deployment');
        $method->setAccessible(true);
        $method->invoke($job);

        Bus::assertDispatched(ApplicationDeploymentVerificationJob::class, fn (ApplicationDeploymentVerificationJob $queuedJob) => $queuedJob->application_deployment_queue_id === $deployment->id);
    });

    test('application deployment job dispatches verifier on failure path', function () {
        Bus::fake([ApplicationDeploymentVerificationJob::class]);
        Notification::fake();

        $application = makeOperatorApplication([
            'build_pack' => 'dockercompose',
        ]);
        $deployment = makeDeployment($application, status: 'in_progress');

        Event::fake();

        $job = (new ReflectionClass(ApplicationDeploymentJob::class))->newInstanceWithoutConstructor();
        $reflection = new ReflectionClass(ApplicationDeploymentJob::class);

        foreach ([
            'application' => $application,
            'application_deployment_queue' => $deployment,
            'deployment_uuid' => $deployment->deployment_uuid,
            'pull_request_id' => 0,
            'container_name' => 'test-container',
        ] as $property => $value) {
            $instanceProperty = $reflection->getProperty($property);
            $instanceProperty->setAccessible(true);
            $instanceProperty->setValue($job, $value);
        }

        $job->failed(new RuntimeException('boom'));

        Bus::assertDispatched(ApplicationDeploymentVerificationJob::class, fn (ApplicationDeploymentVerificationJob $queuedJob) => $queuedJob->application_deployment_queue_id === $deployment->id);
    });
});
