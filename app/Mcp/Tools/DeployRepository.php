<?php

namespace App\Mcp\Tools;

use App\Enums\BuildPackTypes;
use App\Mcp\Concerns\BuildsResponse;
use App\Mcp\Concerns\ResolvesTeam;
use App\Services\McpRepositoryDeploymentService;
use App\Support\ValidationPatterns;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Validation\Rule;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tool;
use Throwable;

#[Name('deploy_repository')]
#[Description('Create and deploy a GitHub App-backed repository application for the authenticated Coolify team. Inspects the repository, applies deployment defaults, auto-enables Deployment Operator when supported, queues a deployment, and optionally waits for the result.')]
class DeployRepository extends Tool
{
    use BuildsResponse;
    use ResolvesTeam;

    public function __construct(private McpRepositoryDeploymentService $deploymentService) {}

    public function handle(Request $request): Response
    {
        if ($error = $this->ensureAbility($request, 'write')) {
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

        try {
            $result = $this->deploymentService->deploy($teamId, $arguments);

            return $this->respond($result, [
                [
                    'tool' => 'get_application',
                    'args' => ['uuid' => data_get($result, 'application.uuid')],
                    'hint' => 'Inspect created application',
                ],
            ]);
        } catch (Throwable $exception) {
            return Response::error($exception->getMessage());
        }
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'github_app_uuid' => $schema->string()->description('Coolify GitHub App UUID to use for repository access.'),
            'source_uuid' => $schema->string()->description('Alias for github_app_uuid.'),
            'github_app_id' => $schema->integer()->description('Coolify GitHub App numeric identifier to use for repository access.'),
            'source_id' => $schema->integer()->description('Alias for github_app_id.'),
            'project_uuid' => $schema->string()->description('Target Coolify project UUID.')->required(),
            'server_uuid' => $schema->string()->description('Target Coolify server UUID.')->required(),
            'environment_uuid' => $schema->string()->description('Target environment UUID. Provide this or environment_name.'),
            'environment_name' => $schema->string()->description('Target environment name. Provide this or environment_uuid.'),
            'destination_uuid' => $schema->string()->description('Optional destination UUID when the server has multiple destinations.'),
            'repo_owner' => $schema->string()->description('GitHub repository owner or organization.')->required(),
            'repo_name' => $schema->string()->description('GitHub repository name.')->required(),
            'branch' => $schema->string()->description('Git branch to deploy.')->required(),
            'name' => $schema->string()->description('Optional Coolify application name override.'),
            'description' => $schema->string()->description('Optional Coolify application description override.'),
            'base_directory' => $schema->string()->description('Optional base directory hint inside the repository, default /.'),
            'desired_build_pack' => $schema->string()->description('Optional build pack hint (nixpacks, railpack, static, dockerfile).'),
            'desired_port' => $schema->integer()->description('Optional runtime port hint (1-65535).'),
            'is_static' => $schema->boolean()->description('Optional static-app hint.'),
            'domain_override' => $schema->string()->description('Optional custom domain override. Must be an absolute host URL.'),
            'domain' => $schema->string()->description('Alias for domain_override.'),
            'autogenerate_domain' => $schema->boolean()->description('If true, include the generated Coolify domain. Defaults to true.'),
            'wait_for_result' => $schema->boolean()->description('If true, wait in a bounded way for deployment/operator completion. Defaults to false.'),
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
            'project_uuid' => $request->get('project_uuid'),
            'server_uuid' => $request->get('server_uuid'),
            'environment_uuid' => $request->get('environment_uuid'),
            'environment_name' => $request->get('environment_name'),
            'destination_uuid' => $request->get('destination_uuid'),
            'repo_owner' => $request->get('repo_owner'),
            'repo_name' => $request->get('repo_name'),
            'branch' => $request->get('branch'),
            'name' => $request->get('name'),
            'description' => $request->get('description'),
            'base_directory' => $request->get('base_directory', '/'),
            'desired_build_pack' => $request->get('desired_build_pack') ?? $request->get('build_pack'),
            'desired_port' => $request->get('desired_port') ?? $request->get('port'),
            'is_static' => $request->get('is_static'),
            'domain_override' => $request->get('domain_override') ?? $request->get('domain'),
            'autogenerate_domain' => $request->get('autogenerate_domain', true),
            'wait_for_result' => $request->get('wait_for_result', false),
        ];

        $validator = validator($arguments, [
            'github_app_uuid' => ['nullable', 'string', 'required_without_all:source_uuid,github_app_id,source_id'],
            'source_uuid' => ['nullable', 'string', 'required_without_all:github_app_uuid,github_app_id,source_id'],
            'github_app_id' => ['nullable', 'integer', 'min:1', 'required_without_all:github_app_uuid,source_uuid,source_id'],
            'source_id' => ['nullable', 'integer', 'min:1', 'required_without_all:github_app_uuid,source_uuid,github_app_id'],
            'project_uuid' => ['required', 'string'],
            'server_uuid' => ['required', 'string'],
            'environment_uuid' => ['nullable', 'string', 'required_without:environment_name'],
            'environment_name' => ['nullable', 'string', 'required_without:environment_uuid'],
            'destination_uuid' => ['nullable', 'string'],
            'repo_owner' => ['required', 'string', 'regex:/^[a-zA-Z0-9\-_]+$/'],
            'repo_name' => ['required', 'string', 'regex:/^[a-zA-Z0-9\-_\.]+$/'],
            'branch' => ['required', 'string'],
            'name' => ['nullable', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'base_directory' => array_merge(['nullable'], array_slice(ValidationPatterns::directoryPathRules(), 1)),
            'desired_build_pack' => ['nullable', 'string', Rule::in(collect(BuildPackTypes::cases())->map->value->all())],
            'desired_port' => ['nullable', 'integer', 'between:1,65535'],
            'is_static' => ['nullable', 'boolean'],
            'domain_override' => ['nullable', 'url'],
            'autogenerate_domain' => ['nullable', 'boolean'],
            'wait_for_result' => ['nullable', 'boolean'],
        ]);

        if ($validator->fails()) {
            return 'Invalid arguments: '.$validator->errors()->first();
        }

        return $arguments;
    }
}
