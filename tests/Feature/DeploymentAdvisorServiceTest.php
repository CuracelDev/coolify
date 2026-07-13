<?php

use App\Livewire\Project\New\GithubPrivateRepository;
use App\Services\DeploymentAdvisorService;
use Illuminate\Support\Facades\Http;

function deploymentAdvisorInspectionPayload(array $overrides = []): array
{
    return array_replace_recursive([
        'repository' => [
            'owner' => 'curacel',
            'repo' => 'learnhub',
            'branch' => 'main',
        ],
        'selection' => [
            'build_pack' => 'nixpacks',
            'base_directory' => '/',
            'port' => 3000,
            'is_static' => false,
        ],
        'selected_files' => [],
        'package_json' => [],
        'detected' => [
            'lockfiles' => [],
            'package_manager' => 'npm',
        ],
        'meta' => [
            'inspection_fingerprint' => 'inspection-test-fingerprint',
        ],
    ], $overrides);
}

beforeEach(function () {
    config()->set('services.ai_deploy_advisor.api_key', null);
    config()->set('services.ai_deploy_advisor.base_url', 'https://llm.crl.to/v1');
    config()->set('services.ai_deploy_advisor.model', 'gpt-5.5');
});

describe('deployment advisor service', function () {
    test('detects next standalone style repositories heuristically', function () {
        $inspection = deploymentAdvisorInspectionPayload([
            'selected_files' => [
                [
                    'name' => 'package.json',
                    'path' => '/package.json',
                    'content' => json_encode(['name' => 'next-app']),
                ],
                [
                    'name' => 'next.config.mjs',
                    'path' => '/next.config.mjs',
                    'content' => "export default { output: 'standalone' }",
                ],
            ],
            'package_json' => [
                'scripts' => [
                    'build' => 'next build',
                    'start' => 'node .next/standalone/server.js',
                ],
                'engines' => ['node' => '22'],
                'dependencies' => ['next' => '^15.0.0', 'react' => '^19.0.0'],
                'dev_dependencies' => [],
            ],
            'detected' => [
                'lockfiles' => ['package-lock.json'],
                'package_manager' => 'npm',
            ],
        ]);

        $result = app(DeploymentAdvisorService::class)->recommend($inspection);

        expect(data_get($result, 'recommendation.build_pack'))->toBe('nixpacks')
            ->and(data_get($result, 'recommendation.start_command'))->toBe('node .next/standalone/server.js')
            ->and(data_get($result, 'recommendation.install_command'))->toBe('npm ci')
            ->and(data_get($result, 'recommendation.port'))->toBe(3000);
    });

    test('detects vite repositories as nixpacks-backed static builds heuristically', function () {
        $inspection = deploymentAdvisorInspectionPayload([
            'selected_files' => [
                [
                    'name' => 'vite.config.ts',
                    'path' => '/vite.config.ts',
                    'content' => 'import { defineConfig } from "vite";',
                ],
            ],
            'package_json' => [
                'scripts' => [
                    'build' => 'vite build',
                ],
                'dependencies' => ['react' => '^19.0.0'],
                'dev_dependencies' => ['vite' => '^6.0.0'],
            ],
            'detected' => [
                'lockfiles' => ['pnpm-lock.yaml'],
                'package_manager' => 'pnpm',
            ],
        ]);

        $result = app(DeploymentAdvisorService::class)->recommend($inspection);

        expect(data_get($result, 'recommendation.build_pack'))->toBe('nixpacks')
            ->and(data_get($result, 'recommendation.install_command'))->toBe('pnpm install --frozen-lockfile')
            ->and(data_get($result, 'recommendation.build_command'))->toBe('pnpm build')
            ->and(data_get($result, 'recommendation.publish_directory'))->toBe('/dist')
            ->and(data_get($result, 'recommendation.is_static'))->toBeTrue()
            ->and(data_get($result, 'recommendation.confidence'))->toBe(0.7)
            ->and(data_get($result, 'recommendation.port'))->toBe(80);
    });

    test('vite heuristics stay overridable by ai', function () {
        config()->set('services.ai_deploy_advisor.api_key', 'test-token');

        Http::fake([
            'https://llm.crl.to/v1/chat/completions' => Http::response([
                'choices' => [[
                    'message' => [
                        'content' => json_encode([
                            'build_pack' => 'railpack',
                            'start_command' => 'node server.js',
                            'port' => 4173,
                            'confidence' => 0.88,
                            'rationale' => 'SSR entrypoint detected.',
                        ]),
                    ],
                ]],
            ]),
        ]);

        $inspection = deploymentAdvisorInspectionPayload([
            'selected_files' => [
                [
                    'name' => 'vite.config.ts',
                    'path' => '/vite.config.ts',
                    'content' => 'import { defineConfig } from "vite";',
                ],
            ],
            'package_json' => [
                'scripts' => [
                    'build' => 'vite build',
                ],
                'dependencies' => ['react' => '^19.0.0'],
                'dev_dependencies' => ['vite' => '^6.0.0'],
            ],
        ]);

        $result = app(DeploymentAdvisorService::class)->recommend($inspection);

        expect(data_get($result, 'heuristics.build_pack'))->toBe('nixpacks')
            ->and(data_get($result, 'heuristics.is_static'))->toBeTrue()
            ->and(data_get($result, 'heuristics.confidence'))->toBe(0.7)
            ->and(data_get($result, 'recommendation.build_pack'))->toBe('railpack')
            ->and(data_get($result, 'recommendation.start_command'))->toBe('node server.js')
            ->and(data_get($result, 'recommendation.port'))->toBe(4173)
            ->and(data_get($result, 'recommendation.source'))->toBe('ai_override');
    });

    test('ai recommendation can be faked and unsafe output is normalized away', function () {
        config()->set('services.ai_deploy_advisor.api_key', 'test-token');

        Http::fake([
            'https://llm.crl.to/v1/chat/completions' => Http::response([
                'choices' => [[
                    'message' => [
                        'content' => json_encode([
                            'build_pack' => 'invalid-pack',
                            'start_command' => 'node server.js; rm -rf /',
                            'publish_directory' => '../dist',
                            'confidence' => 'high',
                            'rationale' => 'AI guessed some values.',
                            'caveats' => ['Double-check output.'],
                        ]),
                    ],
                ]],
            ]),
        ]);

        $inspection = deploymentAdvisorInspectionPayload();
        $result = app(DeploymentAdvisorService::class)->recommend($inspection);

        expect(data_get($result, 'ai.status'))->toBe('completed')
            ->and(data_get($result, 'ai.recommendation.build_pack'))->toBeNull()
            ->and(data_get($result, 'ai.recommendation.start_command'))->toBeNull()
            ->and(data_get($result, 'ai.recommendation.publish_directory'))->toBeNull()
            ->and(data_get($result, 'ai.recommendation.confidence'))->toBe(0.9)
            ->and(data_get($result, 'audit.inspection_fingerprint'))->toBe('inspection-test-fingerprint')
            ->and(data_get($result, 'audit.model'))->toBe('gpt-5.5');
    });

    test('low confidence heuristics allow ai overrides', function () {
        config()->set('services.ai_deploy_advisor.api_key', 'test-token');

        Http::fake([
            'https://llm.crl.to/v1/chat/completions' => Http::response([
                'choices' => [[
                    'message' => [
                        'content' => json_encode([
                            'build_pack' => 'railpack',
                            'install_command' => 'npm ci',
                            'build_command' => 'npm run build',
                            'start_command' => 'node server.js',
                            'port' => 8080,
                            'confidence' => 0.91,
                            'rationale' => 'Custom server entrypoint detected from the compact repo facts.',
                            'caveats' => ['Verify any required runtime env vars before deploy.'],
                        ]),
                    ],
                ]],
            ]),
        ]);

        $inspection = deploymentAdvisorInspectionPayload([
            'package_json' => [
                'scripts' => [
                    'build' => 'webpack build',
                ],
                'dependencies' => ['react' => '^19.0.0'],
                'dev_dependencies' => [],
            ],
        ]);

        $result = app(DeploymentAdvisorService::class)->recommend($inspection);

        expect(data_get($result, 'heuristics.confidence'))->toBe(0.62)
            ->and(data_get($result, 'recommendation.build_pack'))->toBe('railpack')
            ->and(data_get($result, 'recommendation.start_command'))->toBe('node server.js')
            ->and(data_get($result, 'recommendation.port'))->toBe(8080)
            ->and(data_get($result, 'recommendation.source'))->toBe('ai_override');
    });

    test('dockerfile heuristics stay hard even when ai disagrees', function () {
        config()->set('services.ai_deploy_advisor.api_key', 'test-token');

        Http::fake([
            'https://llm.crl.to/v1/chat/completions' => Http::response([
                'choices' => [[
                    'message' => [
                        'content' => json_encode([
                            'build_pack' => 'static',
                            'port' => 80,
                            'publish_directory' => '/dist',
                            'confidence' => 0.95,
                            'rationale' => 'AI prefers a static deployment.',
                        ]),
                    ],
                ]],
            ]),
        ]);

        $inspection = deploymentAdvisorInspectionPayload([
            'selected_files' => [
                [
                    'name' => 'Dockerfile',
                    'path' => '/Dockerfile',
                    'content' => 'FROM node:22-alpine',
                    'excerpt' => 'FROM node:22-alpine',
                ],
            ],
        ]);

        $result = app(DeploymentAdvisorService::class)->recommend($inspection);

        expect(data_get($result, 'recommendation.build_pack'))->toBe('dockerfile')
            ->and(data_get($result, 'recommendation.port'))->toBe(3000)
            ->and(data_get($result, 'recommendation.publish_directory'))->toBeNull()
            ->and(data_get($result, 'recommendation.source'))->toBe('heuristics');
    });

    test('ai request retries and excludes raw lockfile content from payload', function () {
        config()->set('services.ai_deploy_advisor.api_key', 'test-token');

        $attempts = 0;
        $requestBodies = [];

        Http::fake(function ($request) use (&$attempts, &$requestBodies) {
            $attempts++;
            $requestBodies[] = json_decode($request->body(), true);

            if ($attempts === 1) {
                return Http::response([], 500);
            }

            return Http::response([
                'choices' => [[
                    'message' => [
                        'content' => json_encode([
                            'build_pack' => 'nixpacks',
                            'port' => 3000,
                            'confidence' => 0.6,
                            'rationale' => 'Fallback recommendation.',
                        ]),
                    ],
                ]],
            ]);
        });

        $inspection = deploymentAdvisorInspectionPayload([
            'selected_files' => [
                [
                    'name' => 'package.json',
                    'path' => '/package.json',
                    'content' => json_encode(['name' => 'web']),
                    'excerpt' => '{"name":"web"}',
                    'size' => 20,
                    'sha' => 'pkg123',
                ],
                [
                    'name' => 'package-lock.json',
                    'path' => '/package-lock.json',
                    'content' => 'SUPER-SECRET-LOCKFILE-CONTENT',
                    'excerpt' => null,
                    'size' => 120,
                    'sha' => 'lock123',
                ],
            ],
            'detected' => [
                'lockfiles' => ['package-lock.json'],
                'package_manager' => 'npm',
            ],
        ]);

        $result = app(DeploymentAdvisorService::class)->recommend($inspection);
        $encodedRequestBodies = json_encode($requestBodies);

        expect($attempts)->toBe(2)
            ->and(data_get($result, 'ai.status'))->toBe('completed')
            ->and($encodedRequestBodies)->not->toContain('SUPER-SECRET-LOCKFILE-CONTENT')
            ->and($encodedRequestBodies)->not->toContain('"content":"SUPER-SECRET-LOCKFILE-CONTENT"');
    });
});

describe('github private repository recommendations', function () {
    test('applying recommendations updates the livewire form state', function () {
        $component = new class extends GithubPrivateRepository
        {
            public array $dispatchedEvents = [];

            public function dispatch($event, ...$params)
            {
                $this->dispatchedEvents[] = [$event, $params];

                return null;
            }
        };

        $component->analysis_result = [
            'recommendation' => [
                'build_pack' => 'static',
                'install_command' => 'pnpm install --frozen-lockfile',
                'build_command' => 'pnpm build',
                'start_command' => null,
                'port' => 80,
                'base_directory' => '/apps/web',
                'publish_directory' => '/dist',
                'is_static' => false,
            ],
        ];

        $component->applyRecommendations();

        expect($component->build_pack)->toBe('static')
            ->and($component->install_command)->toBe('pnpm install --frozen-lockfile')
            ->and($component->build_command)->toBe('pnpm build')
            ->and($component->port)->toBe(80)
            ->and($component->base_directory)->toBe('/apps/web')
            ->and($component->publish_directory)->toBe('/dist');
    });
});
