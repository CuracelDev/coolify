<?php

use App\Livewire\Project\Application\Advanced;
use App\Models\Application;
use App\Models\Environment;
use App\Models\Project;
use App\Models\Server;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

function createApplicationForAdvancedDeploymentOperatorTest(array $overrides = []): array
{
    $team = Team::factory()->create();
    $server = Server::factory()->create(['team_id' => $team->id]);
    $project = Project::factory()->create(['team_id' => $team->id]);
    $environment = Environment::factory()->create(['project_id' => $project->id]);

    $application = Application::create(array_merge([
        'name' => 'deployment-operator-test-app',
        'git_repository' => 'https://github.com/coollabsio/coolify',
        'git_branch' => 'main',
        'build_pack' => 'nixpacks',
        'ports_exposes' => '3000',
        'environment_id' => $environment->id,
        'destination_id' => $server->standaloneDockers()->firstOrFail()->id,
        'destination_type' => $server->standaloneDockers()->firstOrFail()->getMorphClass(),
    ], $overrides));

    return [$application->fresh(), $server->fresh()];
}

beforeEach(function () {
    $this->actingAs(User::factory()->create());
});

it('saves the deployment operator enable switch', function () {
    [$application, $server] = createApplicationForAdvancedDeploymentOperatorTest();

    $application->update([
        'fqdn' => generateUrl($server, $application->uuid),
    ]);

    Livewire::test(Advanced::class, ['application' => $application->fresh()])
        ->set('isDeploymentOperatorEnabled', true)
        ->call('instantSave')
        ->assertHasNoErrors();

    expect($application->fresh()->settings->is_deployment_operator_enabled)->toBeTrue();
});

it('shows a clear not supported state when the generated Coolify URL is not configured', function () {
    [$application] = createApplicationForAdvancedDeploymentOperatorTest([
        'fqdn' => null,
    ]);

    Livewire::test(Advanced::class, ['application' => $application])
        ->assertSee('Deployment Operator (Beta)')
        ->assertSee('Not supported for this app')
        ->assertSee('Add the generated Coolify URL to Domains');
});
