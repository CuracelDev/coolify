<?php

namespace App\Mcp\Tools;

use App\Enums\BuildPackTypes;
use App\Mcp\Concerns\BuildsResponse;
use App\Mcp\Concerns\ResolvesTeam;
use App\Models\GithubApp;
use App\Services\DeploymentAdvisorService;
use App\Services\GithubRepositoryInspectionService;
use App\Support\ValidationPatterns;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Validation\Rule;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tool;
use Throwable;

#[Name('analyze_repository_deployment_defaults')]
#[Description('Read-only deployment recommendation analysis for a GitHub repository accessible through a team-scoped Coolify GitHub App. Reuses Coolify repo inspection and AI deployment advisor logic without mutating any resources.')]
class AnalyzeRepositoryDeploymentDefaults extends Tool
{
    use BuildsResponse;
    use ResolvesTeam;

    public function __construct(
        private GithubRepositoryInspectionService $inspectionService,
        private DeploymentAdvisorService $deploymentAdvisorService,
    ) {}

    public function handle(Request $request): Response
    {
        if ($error = $this->ensureAbility($request, 'read')) {
            return $error;
        }

        $teamId = $this->resolveTeamId($request);
        if (is_null($teamId)) {
            return Response::error('Invalid token.');
        }

        $arguments = $this->validatedArguments($request);
        if (is_string($arguments)) {
            return Response::error($arguments);
        }

        $githubApp = $this->resolveGithubApp($teamId, $arguments);
        if (! $githubApp) {
            return Response::error('GitHub source not found for this team.');
        }

        try {
            $inspection = $this->inspectionService->inspectPrivateRepository(
                githubApp: $githubApp,
                owner: $arguments['repo_owner'],
                repo: $arguments['repo_name'],
                branch: $arguments['branch'],
                selection: $this->selectionHints($arguments),
            );

            $analysis = $this->deploymentAdvisorService->recommend($inspection);

            return $this->respond([
                'source' => [
                    'uuid' => $githubApp->uuid,
                    'name' => $githubApp->name,
                    'is_system_wide' => (bool) $githubApp->is_system_wide,
                ],
                'repository' => data_get($inspection, 'repository', []),
                'selection' => data_get($inspection, 'selection', []),
                'inspection' => [
                    'fingerprint' => data_get($inspection, 'meta.inspection_fingerprint'),
                    'package_manager' => data_get($inspection, 'detected.package_manager'),
                    'lockfiles' => data_get($inspection, 'detected.lockfiles', []),
                    'directory_entries' => collect(data_get($inspection, 'directory_entries', []))
                        ->take(30)
                        ->values()
                        ->all(),
                    'files' => collect(data_get($inspection, 'selected_files', []))
                        ->map(fn (array $file): array => [
                            'path' => data_get($file, 'path'),
                            'name' => data_get($file, 'name'),
                            'size' => data_get($file, 'size'),
                            'sha' => data_get($file, 'sha'),
                        ])
                        ->values()
                        ->all(),
                ],
                'recommendation' => data_get($analysis, 'recommendation', []),
                'heuristics' => collect(data_get($analysis, 'heuristics', []))
                    ->only(['build_pack', 'confidence', 'rationale', 'caveats', 'source'])
                    ->all(),
                'ai' => [
                    'status' => data_get($analysis, 'ai.status'),
                    'model' => data_get($analysis, 'ai.model'),
                ],
                'rationale' => [
                    'summary' => data_get($analysis, 'recommendation.rationale'),
                    'caveats' => data_get($analysis, 'recommendation.caveats', []),
                ],
            ]);
        } catch (Throwable $exception) {
            return Response::error($exception->getMessage());
        }
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'github_app_uuid' => $schema->string()->description('Coolify GitHub App UUID to use for repository inspection.'),
            'source_uuid' => $schema->string()->description('Alias for github_app_uuid.'),
            'github_app_id' => $schema->integer()->description('Coolify GitHub App numeric identifier to use for repository inspection.'),
            'source_id' => $schema->integer()->description('Alias for github_app_id.'),
            'repo_owner' => $schema->string()->description('GitHub repository owner or organization.'),
            'repo_name' => $schema->string()->description('GitHub repository name.'),
            'branch' => $schema->string()->description('Git branch to inspect.'),
            'base_directory' => $schema->string()->description('Optional base directory hint inside the repository, default /.'),
            'build_pack' => $schema->string()->description('Optional build pack hint (nixpacks, railpack, static, dockerfile, dockercompose, etc.).'),
            'port' => $schema->integer()->description('Optional runtime port hint (1-65535).'),
            'is_static' => $schema->boolean()->description('Optional static-app hint.'),
        ];
    }

    /**
     * @return array<string, mixed>|string
     */
    private function validatedArguments(Request $request): array|string
    {
        $arguments = [
            'github_app_uuid' => $request->get('github_app_uuid'),
            'source_uuid' => $request->get('source_uuid'),
            'github_app_id' => $request->get('github_app_id'),
            'source_id' => $request->get('source_id'),
            'repo_owner' => $request->get('repo_owner'),
            'repo_name' => $request->get('repo_name'),
            'branch' => $request->get('branch'),
            'base_directory' => $request->get('base_directory', '/'),
            'build_pack' => $request->get('build_pack'),
            'port' => $request->get('port'),
            'is_static' => $request->get('is_static'),
        ];

        $validator = validator($arguments, [
            'github_app_uuid' => ['nullable', 'string', 'required_without_all:source_uuid,github_app_id,source_id'],
            'source_uuid' => ['nullable', 'string', 'required_without_all:github_app_uuid,github_app_id,source_id'],
            'github_app_id' => ['nullable', 'integer', 'min:1', 'required_without_all:github_app_uuid,source_uuid,source_id'],
            'source_id' => ['nullable', 'integer', 'min:1', 'required_without_all:github_app_uuid,source_uuid,github_app_id'],
            'repo_owner' => ['required', 'string', 'regex:/^[a-zA-Z0-9\-_]+$/'],
            'repo_name' => ['required', 'string', 'regex:/^[a-zA-Z0-9\-_\.]+$/'],
            'branch' => ['required', 'string'],
            'base_directory' => array_merge(['nullable'], array_slice(ValidationPatterns::directoryPathRules(), 1)),
            'build_pack' => ['nullable', 'string', Rule::in(collect(BuildPackTypes::cases())->map->value->all())],
            'port' => ['nullable', 'integer', 'between:1,65535'],
            'is_static' => ['nullable', 'boolean'],
        ]);

        if ($validator->fails()) {
            return 'Invalid arguments: '.$validator->errors()->first();
        }

        return $arguments;
    }

    /**
     * @param  array<string, mixed>  $arguments
     */
    private function resolveGithubApp(int $teamId, array $arguments): ?GithubApp
    {
        $identifier = $arguments['github_app_uuid'] ?? $arguments['source_uuid'] ?? null;
        $numericIdentifier = $arguments['github_app_id'] ?? $arguments['source_id'] ?? null;

        return GithubApp::query()
            ->where(function ($query) use ($teamId) {
                $query->where('team_id', $teamId)
                    ->orWhere('is_system_wide', true);
            })
            ->when(filled($identifier), fn ($query) => $query->where('uuid', $identifier))
            ->when(blank($identifier) && filled($numericIdentifier), fn ($query) => $query->whereKey((int) $numericIdentifier))
            ->first();
    }

    /**
     * @param  array<string, mixed>  $arguments
     * @return array<string, mixed>
     */
    private function selectionHints(array $arguments): array
    {
        return [
            'build_pack' => $arguments['build_pack'] ?? null,
            'base_directory' => $arguments['base_directory'] ?? '/',
            'port' => $arguments['port'] ?? null,
            'is_static' => filter_var($arguments['is_static'] ?? false, FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE) ?? false,
        ];
    }
}
