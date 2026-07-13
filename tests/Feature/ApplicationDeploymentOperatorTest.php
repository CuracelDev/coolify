<?php

use App\Actions\Docker\GetContainersStatus;
use App\Jobs\ApplicationDeploymentJob;
use App\Jobs\ApplicationDeploymentVerificationJob;
use App\Models\Application;
use App\Models\ApplicationDeploymentQueue;
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

    test('static build pack app is ineligible', function () {
        $application = makeOperatorApplication([
            'build_pack' => 'static',
        ]);

        $deployment = makeDeployment($application);
        $service = app(ApplicationDeploymentOperatorService::class);

        expect($service->eligibility($deployment))->toBe([
            'eligible' => false,
            'reason' => 'unsupported_build_pack',
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
