<?php

namespace App\Livewire\Project\New;

use App\Enums\BuildPackTypes;
use App\Models\Application;
use App\Models\GithubApp;
use App\Models\Project;
use App\Rules\ValidGitBranch;
use App\Services\DeploymentAdvisorService;
use App\Services\GithubRepositoryInspectionService;
use App\Support\ValidationPatterns;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Locked;
use Livewire\Component;

class GithubPrivateRepository extends Component
{
    public $current_step = 'github_apps';

    public $github_apps;

    public GithubApp $github_app;

    public $parameters;

    public $currentRoute;

    public $query;

    public $type;

    public int $selected_repository_id;

    #[Locked]
    public int $selected_github_app_id;

    public string $selected_repository_owner;

    public string $selected_repository_repo;

    public string $selected_branch_name = 'main';

    public $repositories;

    public int $total_repositories_count = 0;

    public $branches;

    public int $total_branches_count = 0;

    public int $port = 3000;

    public bool $is_static = false;

    public ?string $install_command = null;

    public ?string $build_command = null;

    public ?string $start_command = null;

    public ?string $publish_directory = null;

    // In case of docker compose
    public ?string $base_directory = '/';

    public ?string $docker_compose_location = '/docker-compose.yaml';
    // End of docker compose

    protected int $page = 1;

    public $build_pack = 'nixpacks';

    public bool $show_is_static = true;

    public array $analysis_result = [];

    public array $applied_recommendation = [];

    public function mount()
    {
        $this->currentRoute = Route::currentRouteName();
        $this->parameters = get_route_parameters();
        $this->query = request()->query();
        $this->repositories = $this->branches = collect();
        $this->github_apps = GithubApp::ownedByCurrentTeam()
            ->where('is_public', false)
            ->whereNotNull('app_id')
            ->get();
    }

    public function updatedSelectedRepositoryId(): void
    {
        $this->resetAnalysis();
        $this->loadBranches();
    }

    public function updatedSelectedBranchName(): void
    {
        $this->resetAnalysis();
    }

    public function updatedBaseDirectory(): void
    {
        $this->resetAnalysis();
    }

    public function updatedBuildPack()
    {
        if ($this->build_pack === 'nixpacks' || $this->build_pack === 'railpack') {
            $this->show_is_static = true;
            if (! $this->is_static) {
                $this->port = 3000;
            }
        } elseif ($this->build_pack === 'static') {
            $this->show_is_static = false;
            $this->is_static = false;
            $this->port = 80;
        } else {
            $this->show_is_static = false;
            $this->is_static = false;
        }
    }

    public function loadRepositories(int $github_app_id): void
    {
        $this->resetAnalysis();
        $this->repositories = collect();
        $this->branches = collect();
        $this->total_branches_count = 0;
        $this->page = 1;
        $this->selected_github_app_id = $github_app_id;
        $this->github_app = GithubApp::ownedByCurrentTeam()
            ->where('is_public', false)
            ->whereNotNull('app_id')
            ->findOrFail($github_app_id);
        $token = generateGithubInstallationToken($this->github_app);
        $repositories = loadRepositoryByPage($this->github_app, $token, $this->page);
        $this->total_repositories_count = $repositories['total_count'];
        $this->repositories = $this->repositories->concat(collect($repositories['repositories']));
        if ($this->repositories->count() < $this->total_repositories_count) {
            while ($this->repositories->count() < $this->total_repositories_count) {
                $this->page++;
                $repositories = loadRepositoryByPage($this->github_app, $token, $this->page);
                $this->total_repositories_count = $repositories['total_count'];
                $this->repositories = $this->repositories->concat(collect($repositories['repositories']));
            }
        }
        $this->repositories = $this->repositories->sortBy('name');
        if ($this->repositories->count() > 0) {
            $this->selected_repository_id = data_get($this->repositories->first(), 'id');
        }
        $this->current_step = 'repository';
    }

    public function loadBranches()
    {
        $this->resetAnalysis();
        $this->selected_repository_owner = $this->repositories->where('id', $this->selected_repository_id)->first()['owner']['login'];
        $this->selected_repository_repo = $this->repositories->where('id', $this->selected_repository_id)->first()['name'];
        $this->branches = collect();
        $this->page = 1;
        $this->loadBranchByPage();
        if ($this->total_branches_count === 100) {
            while ($this->total_branches_count === 100) {
                $this->page++;
                $this->loadBranchByPage();
            }
        }
        $this->branches = sortBranchesByPriority($this->branches);
        $this->selected_branch_name = data_get($this->branches, '0.name', 'main');
    }

    public function analyzeRepository(): void
    {
        try {
            $validator = validator([
                'selected_repository_owner' => $this->selected_repository_owner,
                'selected_repository_repo' => $this->selected_repository_repo,
                'selected_branch_name' => $this->selected_branch_name,
                'base_directory' => $this->base_directory,
                'port' => $this->port,
            ], [
                'selected_repository_owner' => 'required|string|regex:/^[a-zA-Z0-9\-_]+$/',
                'selected_repository_repo' => 'required|string|regex:/^[a-zA-Z0-9\-_\.]+$/',
                'selected_branch_name' => ['required', 'string', new ValidGitBranch],
                'base_directory' => array_merge(['required'], array_slice(ValidationPatterns::directoryPathRules(), 1)),
                'port' => ['nullable', 'integer', 'between:1,65535'],
            ]);

            if ($validator->fails()) {
                throw new \RuntimeException($validator->errors()->first());
            }

            $inspection = app(GithubRepositoryInspectionService::class)->inspectPrivateRepository(
                $this->github_app,
                $this->selected_repository_owner,
                $this->selected_repository_repo,
                $this->selected_branch_name,
                [
                    'build_pack' => $this->build_pack,
                    'base_directory' => $this->base_directory,
                    'port' => $this->port,
                    'is_static' => $this->is_static,
                ],
            );
            $this->analysis_result = app(DeploymentAdvisorService::class)->recommend($inspection);
            $this->applied_recommendation = [];

            auditLog('project.application.autodetect.analyzed', [
                'source' => 'github_private_repository',
                'github_app_id' => $this->selectedGithubAppId(),
                'repository' => $this->selectedRepositorySlug(),
                'branch' => $this->selected_branch_name,
                'recommended_build_pack' => data_get($this->analysis_result, 'recommendation.build_pack'),
                'ai_status' => data_get($this->analysis_result, 'ai.status'),
                'confidence' => data_get($this->analysis_result, 'recommendation.confidence'),
            ] + $this->analysisAuditMetadata());

            $message = data_get($this->analysis_result, 'ai.status') === 'completed'
                ? 'Repository analysis completed.'
                : 'Repository analysis completed with heuristics only.';

            $this->dispatch('success', $message);
        } catch (\Throwable $e) {
            $this->analysis_result = [];

            handleError($e, $this);
        }
    }

    public function applyRecommendations(): void
    {
        $recommendation = data_get($this->analysis_result, 'recommendation', []);

        if ($recommendation === []) {
            $this->dispatch('error', 'No recommendations available to apply.');

            return;
        }

        $applied = [];

        if (filled($recommendation['build_pack'] ?? null)) {
            $this->build_pack = $recommendation['build_pack'];
            $this->updatedBuildPack();
            $applied['build_pack'] = $this->build_pack;
        }

        foreach (['install_command', 'build_command', 'start_command', 'base_directory', 'publish_directory'] as $field) {
            if (array_key_exists($field, $recommendation)) {
                $this->{$field} = $recommendation[$field];
                $applied[$field] = $this->{$field};
            }
        }

        if (array_key_exists('port', $recommendation) && filled($recommendation['port'])) {
            $this->port = (int) $recommendation['port'];
            $applied['port'] = $this->port;
        }

        if (($this->build_pack !== BuildPackTypes::STATIC->value) && array_key_exists('is_static', $recommendation)) {
            $this->is_static = (bool) $recommendation['is_static'];
            $applied['is_static'] = $this->is_static;
        }

        $this->applied_recommendation = $applied;

        auditLog('project.application.autodetect.applied', [
            'source' => 'github_private_repository',
            'github_app_id' => $this->selectedGithubAppId(),
            'repository' => $this->selectedRepositorySlug(),
            'branch' => $this->selected_branch_name,
            'applied' => $this->applied_recommendation,
            'recommended_build_pack' => data_get($this->analysis_result, 'recommendation.build_pack'),
        ] + $this->analysisAuditMetadata());

        $this->dispatch('success', 'Recommendations applied to the form.');
    }

    protected function loadBranchByPage()
    {
        $token = generateGithubInstallationToken($this->github_app);

        $response = Http::GitHub($this->github_app->api_url, $token)
            ->timeout(20)
            ->retry(3, 200, throw: false)
            ->get("/repos/{$this->selected_repository_owner}/{$this->selected_repository_repo}/branches", [
                'per_page' => 100,
                'page' => $this->page,
            ]);
        $json = $response->json();
        if ($response->status() !== 200) {
            return $this->dispatch('error', $json['message']);
        }

        $this->total_branches_count = count($json);
        $this->branches = $this->branches->concat(collect($json));
    }

    public function submit()
    {
        try {
            // Validate git repository parts and branch
            $validator = validator([
                'selected_repository_owner' => $this->selected_repository_owner,
                'selected_repository_repo' => $this->selected_repository_repo,
                'selected_branch_name' => $this->selected_branch_name,
                'docker_compose_location' => $this->docker_compose_location,
                'build_pack' => $this->build_pack,
                'base_directory' => $this->base_directory,
                'publish_directory' => $this->publish_directory,
                'install_command' => $this->install_command,
                'build_command' => $this->build_command,
                'start_command' => $this->start_command,
                'port' => $this->port,
            ], [
                'selected_repository_owner' => 'required|string|regex:/^[a-zA-Z0-9\-_]+$/',
                'selected_repository_repo' => 'required|string|regex:/^[a-zA-Z0-9\-_\.]+$/',
                'selected_branch_name' => ['required', 'string', new ValidGitBranch],
                'docker_compose_location' => ValidationPatterns::filePathRules(),
                'build_pack' => ['required', 'string', Rule::in(collect(BuildPackTypes::cases())->map->value->all())],
                'base_directory' => array_merge(['required'], array_slice(ValidationPatterns::directoryPathRules(), 1)),
                'publish_directory' => ValidationPatterns::directoryPathRules(),
                'install_command' => ValidationPatterns::shellSafeCommandRules(),
                'build_command' => ValidationPatterns::shellSafeCommandRules(),
                'start_command' => ValidationPatterns::shellSafeCommandRules(),
                'port' => ['required', 'integer', 'between:1,65535'],
            ]);

            if ($validator->fails()) {
                throw new \RuntimeException('Invalid repository data: '.$validator->errors()->first());
            }

            $destination_uuid = $this->query['destination'] ?? null;
            $destination = find_destination_for_current_team($destination_uuid);
            if (! $destination) {
                throw new \Exception('Destination not found.');
            }
            $destination_class = $destination->getMorphClass();

            $project = Project::ownedByCurrentTeam()->where('uuid', $this->parameters['project_uuid'])->firstOrFail();
            $environment = $project->environments()->where('uuid', $this->parameters['environment_uuid'])->firstOrFail();

            $application = Application::create([
                'name' => generate_application_name($this->selected_repository_owner.'/'.$this->selected_repository_repo, $this->selected_branch_name),
                'repository_project_id' => $this->selected_repository_id,
                'git_repository' => str($this->selected_repository_owner)->trim()->toString().'/'.str($this->selected_repository_repo)->trim()->toString(),
                'git_branch' => str($this->selected_branch_name)->trim()->toString(),
                'build_pack' => $this->build_pack,
                'install_command' => $this->install_command,
                'build_command' => $this->build_command,
                'start_command' => $this->start_command,
                'ports_exposes' => $this->port,
                'publish_directory' => $this->publish_directory,
                'base_directory' => $this->base_directory,
                'environment_id' => $environment->id,
                'destination_id' => $destination->id,
                'destination_type' => $destination_class,
                'source_id' => $this->github_app->id,
                'source_type' => $this->github_app->getMorphClass(),
            ]);
            $application->settings->is_static = $this->is_static;
            $application->settings->save();

            if ($this->build_pack === 'dockerfile' || $this->build_pack === 'dockerimage') {
                $application->health_check_enabled = false;
            }
            if ($this->build_pack === 'dockercompose') {
                $application['docker_compose_location'] = $this->docker_compose_location;
            }
            $fqdn = generateUrl(server: $destination->server, random: $application->uuid);
            $application->fqdn = $fqdn;

            $application->name = generate_application_name($this->selected_repository_owner.'/'.$this->selected_repository_repo, $this->selected_branch_name, $application->uuid);
            $application->save();

            if ($this->analysis_result !== []) {
                auditLog('project.application.autodetect.saved', [
                    'source' => 'github_private_repository',
                    'application_id' => $application->id,
                    'repository' => $this->selectedRepositorySlug(),
                    'branch' => $this->selected_branch_name,
                    'recommended' => $this->compactRecommendationForAudit(data_get($this->analysis_result, 'recommendation', [])),
                    'applied' => $this->compactRecommendationForAudit($this->applied_recommendation),
                    'ai_status' => data_get($this->analysis_result, 'ai.status'),
                ] + $this->analysisAuditMetadata());
            }

            return redirect()->route('project.application.configuration', [
                'application_uuid' => $application->uuid,
                'environment_uuid' => $environment->uuid,
                'project_uuid' => $project->uuid,
            ]);
        } catch (\Throwable $e) {
            return handleError($e, $this);
        }
    }

    public function instantSave()
    {
        if ($this->is_static) {
            $this->port = 80;
            $this->publish_directory = '/dist';
        } else {
            $this->port = 3000;
            $this->publish_directory = null;
        }
        $this->dispatch('success', 'Application settings updated!');
    }

    private function resetAnalysis(): void
    {
        $this->analysis_result = [];
        $this->applied_recommendation = [];
    }

    /**
     * @param  array<string, mixed>  $recommendation
     * @return array<string, mixed>
     */
    private function compactRecommendationForAudit(array $recommendation): array
    {
        return collect($recommendation)
            ->only([
                'build_pack',
                'install_command',
                'build_command',
                'start_command',
                'port',
                'base_directory',
                'publish_directory',
                'is_static',
                'confidence',
                'rationale',
                'caveats',
                'source',
            ])
            ->all();
    }

    /**
     * @return array<string, mixed>
     */
    private function analysisAuditMetadata(): array
    {
        return collect(data_get($this->analysis_result, 'audit', []))
            ->only([
                'inspection_fingerprint',
                'model',
                'rationale',
                'caveats',
            ])
            ->all();
    }

    private function selectedRepositorySlug(): ?string
    {
        if (! isset($this->selected_repository_owner, $this->selected_repository_repo)) {
            return null;
        }

        return $this->selected_repository_owner.'/'.$this->selected_repository_repo;
    }

    private function selectedGithubAppId(): ?int
    {
        return isset($this->github_app) ? $this->github_app->id : null;
    }
}
