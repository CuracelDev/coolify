<?php

namespace App\Mcp\Servers;

use App\Mcp\Tools\AnalyzeRepositoryDeploymentDefaults;
use App\Mcp\Tools\DeployRepository;
use App\Mcp\Tools\GetApplication;
use App\Mcp\Tools\GetDatabase;
use App\Mcp\Tools\GetInfrastructureOverview;
use App\Mcp\Tools\GetServer;
use App\Mcp\Tools\GetService;
use App\Mcp\Tools\ListApplications;
use App\Mcp\Tools\ListDatabases;
use App\Mcp\Tools\ListProjects;
use App\Mcp\Tools\ListServers;
use App\Mcp\Tools\ListServices;
use Laravel\Mcp\Server;
use Laravel\Mcp\Server\Attributes\Instructions;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Attributes\Version;

#[Name('Coolify')]
#[Version('0.1.0')]
#[Instructions(<<<'MD'
Read-only MCP server for Coolify, scoped to the authenticated team token.

Recommended workflow:
1. get_infrastructure_overview — start here; single call returns all servers, projects with resource counts, and aggregates.
2. analyze_repository_deployment_defaults — inspect a GitHub repo through Coolify and get structured deployment defaults + caveats for coding agents.
3. deploy_repository — create and queue a GitHub App-backed application deploy using those defaults, with optional bounded wait for result.
4. list_servers / list_projects / list_applications / list_databases / list_services — paginated summary listings (default 50 per page, cap 100).
5. get_server / get_application / get_database / get_service — full details for a single UUID.

Every response is `{ data, _actions?, _pagination? }`. `_actions` suggests the next tool + args; `_pagination.next` is the args to call again for the next page.
MD)]
class CoolifyServer extends Server
{
    protected array $tools = [
        GetInfrastructureOverview::class,
        AnalyzeRepositoryDeploymentDefaults::class,
        DeployRepository::class,
        ListServers::class,
        GetServer::class,
        ListProjects::class,
        ListApplications::class,
        GetApplication::class,
        ListDatabases::class,
        GetDatabase::class,
        ListServices::class,
        GetService::class,
    ];

    protected array $resources = [];

    protected array $prompts = [];
}
