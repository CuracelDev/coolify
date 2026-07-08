<?php

namespace App\Services;

use App\Enums\BuildPackTypes;
use App\Support\ValidationPatterns;
use Illuminate\Config\Repository;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Throwable;

class DeploymentAdvisorService
{
    public function __construct(private Repository $config) {}

    /**
     * @param  array<string, mixed>  $inspection
     * @return array<string, mixed>
     */
    public function recommend(array $inspection): array
    {
        $heuristicRecommendation = $this->heuristicRecommendation($inspection);
        $aiResult = $this->requestAiRecommendation($inspection, $heuristicRecommendation);
        $finalRecommendation = $this->mergeRecommendations($heuristicRecommendation, data_get($aiResult, 'recommendation', []));

        return [
            'recommendation' => $finalRecommendation,
            'heuristics' => $heuristicRecommendation,
            'ai' => $aiResult,
            'audit' => [
                'inspection_fingerprint' => data_get($inspection, 'meta.inspection_fingerprint'),
                'model' => data_get($aiResult, 'model'),
                'rationale' => data_get($finalRecommendation, 'rationale'),
                'caveats' => data_get($finalRecommendation, 'caveats', []),
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $inspection
     * @return array<string, mixed>
     */
    private function heuristicRecommendation(array $inspection): array
    {
        $selection = data_get($inspection, 'selection', []);
        $packageJson = data_get($inspection, 'package_json', []);
        $packageManager = (string) data_get($inspection, 'detected.package_manager', 'npm');
        $nodeEngine = data_get($packageJson, 'engines.node');
        $scripts = data_get($packageJson, 'scripts', []);

        $dockerComposeFile = $this->findFile($inspection, ['docker-compose.yml', 'docker-compose.yaml', 'compose.yml', 'compose.yaml']);
        if ($dockerComposeFile) {
            return $this->normalizeRecommendation([
                'build_pack' => BuildPackTypes::DOCKERCOMPOSE->value,
                'base_directory' => data_get($selection, 'base_directory', '/'),
                'port' => data_get($selection, 'port'),
                'is_static' => false,
                'confidence' => 0.98,
                'rationale' => 'Detected a Docker Compose manifest in the selected base directory, so Compose is the safest explicit deploy mode.',
                'caveats' => [
                    'Review the compose file path and exposed services before continuing.',
                ],
                'source' => 'heuristics',
            ], $inspection);
        }

        $dockerfile = $this->findFile($inspection, ['Dockerfile']);
        if ($dockerfile) {
            return $this->normalizeRecommendation([
                'build_pack' => BuildPackTypes::DOCKERFILE->value,
                'base_directory' => data_get($selection, 'base_directory', '/'),
                'port' => data_get($selection, 'port'),
                'is_static' => false,
                'confidence' => 0.95,
                'rationale' => 'Detected a Dockerfile, so Dockerfile mode is the most deterministic starting point.',
                'caveats' => [
                    'Confirm the Dockerfile exposes the expected runtime port.',
                ],
                'source' => 'heuristics',
            ], $inspection);
        }

        $nextConfig = $this->firstFileContent($inspection, ['next.config.js', 'next.config.mjs', 'next.config.ts']);
        $viteConfig = $this->firstFileContent($inspection, ['vite.config.js', 'vite.config.mjs', 'vite.config.cjs', 'vite.config.ts']);
        $dependencies = array_keys(array_merge(
            data_get($packageJson, 'dependencies', []),
            data_get($packageJson, 'dev_dependencies', []),
        ));

        $caveats = [];
        if (filled($nodeEngine)) {
            $caveats[] = "Repo requests Node {$nodeEngine}; align the runtime version if builds fail.";
        }

        $isNextProject = in_array('next', $dependencies, true);
        $startScript = (string) data_get($scripts, 'start', '');
        $buildScript = data_get($scripts, 'build');
        $hasStandaloneHint = $isNextProject
            && (
                Str::contains((string) $nextConfig, ['standalone', "output: 'standalone'", 'output:"standalone"'])
                || Str::contains($startScript, '.next/standalone/server.js')
            );

        if ($hasStandaloneHint) {
            $caveats[] = 'Next standalone builds usually need a direct standalone start command and should not rely on static mode.';

            return $this->normalizeRecommendation([
                'build_pack' => BuildPackTypes::NIXPACKS->value,
                'install_command' => $this->defaultInstallCommand($packageManager, $inspection),
                'build_command' => $buildScript ? $this->scriptCommand($packageManager, 'build') : null,
                'start_command' => Str::contains($startScript, '.next/standalone/server.js') ? $startScript : 'node .next/standalone/server.js',
                'port' => 3000,
                'base_directory' => data_get($selection, 'base_directory', '/'),
                'publish_directory' => null,
                'is_static' => false,
                'confidence' => 0.94,
                'rationale' => 'Detected Next.js with a standalone runtime hint, so a Node app deploy with an explicit standalone start command is recommended.',
                'caveats' => $caveats,
                'source' => 'heuristics',
            ], $inspection);
        }

        $isViteProject = in_array('vite', $dependencies, true)
            || filled($viteConfig)
            || Str::contains((string) data_get($scripts, 'build', ''), 'vite build');

        if ($isViteProject) {
            $caveats[] = 'If this is an SPA, make sure routing fallback behavior is configured after deploy.';

            return $this->normalizeRecommendation([
                'build_pack' => BuildPackTypes::STATIC->value,
                'install_command' => $this->defaultInstallCommand($packageManager, $inspection),
                'build_command' => $buildScript ? $this->scriptCommand($packageManager, 'build') : null,
                'start_command' => null,
                'port' => 80,
                'base_directory' => data_get($selection, 'base_directory', '/'),
                'publish_directory' => '/dist',
                'is_static' => false,
                'confidence' => 0.92,
                'rationale' => 'Detected a Vite-style frontend build, so static deployment with a dist publish directory is the safest default.',
                'caveats' => $caveats,
                'source' => 'heuristics',
            ], $inspection);
        }

        if ($packageJson !== []) {
            $caveats[] = 'Review the generated start command if the app uses a custom runtime entrypoint.';

            return $this->normalizeRecommendation([
                'build_pack' => BuildPackTypes::NIXPACKS->value,
                'install_command' => $this->defaultInstallCommand($packageManager, $inspection),
                'build_command' => $buildScript ? $this->scriptCommand($packageManager, 'build') : null,
                'start_command' => data_get($scripts, 'start') ? $this->scriptCommand($packageManager, 'start') : null,
                'port' => (int) data_get($selection, 'port', 3000),
                'base_directory' => data_get($selection, 'base_directory', '/'),
                'publish_directory' => null,
                'is_static' => false,
                'confidence' => 0.62,
                'rationale' => 'Detected a package.json-based app, so a generic Node buildpack recommendation was prepared.',
                'caveats' => $caveats,
                'source' => 'heuristics',
            ], $inspection);
        }

        return $this->normalizeRecommendation([
            'build_pack' => data_get($selection, 'build_pack', BuildPackTypes::NIXPACKS->value),
            'port' => data_get($selection, 'port', 3000),
            'base_directory' => data_get($selection, 'base_directory', '/'),
            'publish_directory' => null,
            'is_static' => (bool) data_get($selection, 'is_static', false),
            'confidence' => 0.35,
            'rationale' => 'No strong deterministic framework signal was found, so the current selection was preserved as a low-confidence default.',
            'caveats' => ['Run AI analysis or adjust the defaults manually before continuing.'],
            'source' => 'heuristics',
        ], $inspection);
    }

    /**
     * @param  array<string, mixed>  $inspection
     * @param  array<string, mixed>  $heuristics
     * @return array<string, mixed>
     */
    private function requestAiRecommendation(array $inspection, array $heuristics): array
    {
        $apiKey = (string) $this->config->get('services.ai_deploy_advisor.api_key');
        $model = (string) $this->config->get('services.ai_deploy_advisor.model', 'gpt-5.5');
        if ($apiKey === '') {
            return [
                'status' => 'skipped',
                'message' => 'AI gateway API key is not configured.',
                'model' => $model,
                'recommendation' => [],
            ];
        }

        try {
            $response = Http::withToken($apiKey)
                ->retry(3, fn (int $attempt): int => $attempt * 250, throw: false)
                ->timeout(20)
                ->baseUrl((string) $this->config->get('services.ai_deploy_advisor.base_url', 'https://llm.crl.to/v1'))
                ->post('/chat/completions', [
                    'model' => $model,
                    'temperature' => 0.1,
                    'messages' => [
                        [
                            'role' => 'system',
                            'content' => 'You recommend Coolify deployment defaults. Use heuristics as hard constraints when they are high-confidence. Return JSON only with keys: build_pack, install_command, build_command, start_command, port, base_directory, publish_directory, is_static, confidence, rationale, caveats.',
                        ],
                        [
                            'role' => 'user',
                            'content' => json_encode([
                                'inspection' => $this->compactInspectionForAi($inspection),
                                'heuristics' => $heuristics,
                            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES),
                        ],
                    ],
                    'response_format' => ['type' => 'json_object'],
                ]);

            if (! $response->successful()) {
                return [
                    'status' => 'failed',
                    'message' => 'AI gateway request failed.',
                    'model' => $model,
                    'recommendation' => [],
                ];
            }

            $decoded = $this->decodeAiResponse($response->json());

            return [
                'status' => 'completed',
                'message' => 'AI recommendation generated.',
                'model' => $model,
                'recommendation' => $this->normalizeRecommendation($decoded, $inspection),
            ];
        } catch (Throwable $exception) {
            return [
                'status' => 'failed',
                'message' => $exception->getMessage(),
                'model' => $model,
                'recommendation' => [],
            ];
        }
    }

    /**
     * @param  array<string, mixed>  $response
     * @return array<string, mixed>
     */
    private function decodeAiResponse(array $response): array
    {
        $content = data_get($response, 'choices.0.message.content');

        if (is_array($content)) {
            $content = collect($content)
                ->map(fn ($item): string => (string) data_get($item, 'text', ''))
                ->implode("\n");
        }

        if (! is_string($content) || $content === '') {
            return [];
        }

        if (preg_match('/```(?:json)?\s*(\{.*\})\s*```/s', $content, $matches) === 1) {
            $content = $matches[1];
        }

        $decoded = json_decode($content, true);
        if (is_array($decoded)) {
            return $decoded;
        }

        $start = strpos($content, '{');
        $end = strrpos($content, '}');
        if ($start === false || $end === false || $end <= $start) {
            return [];
        }

        $decoded = json_decode(substr($content, $start, $end - $start + 1), true);

        return is_array($decoded) ? $decoded : [];
    }

    /**
     * @param  array<string, mixed>  $heuristics
     * @param  array<string, mixed>  $aiRecommendation
     * @return array<string, mixed>
     */
    private function mergeRecommendations(array $heuristics, array $aiRecommendation): array
    {
        $fields = [
            'build_pack',
            'install_command',
            'build_command',
            'start_command',
            'port',
            'base_directory',
            'publish_directory',
            'is_static',
        ];

        $final = $heuristics;
        $filledFromAi = [];
        $overriddenByAi = [];
        $allowAiOverrides = $this->shouldAllowAiOverrides($heuristics);
        $hasHardHeuristicConstraints = $this->hasHardHeuristicConstraints($heuristics);

        if (! $hasHardHeuristicConstraints) {
            foreach ($fields as $field) {
                if (! array_key_exists($field, $aiRecommendation) || ! $this->hasRecommendedValue($field, $aiRecommendation[$field])) {
                    continue;
                }

                $hasHeuristicValue = $this->hasRecommendedValue($field, $final[$field] ?? null);
                if (! $hasHeuristicValue || $allowAiOverrides) {
                    if ($hasHeuristicValue && ($final[$field] ?? null) !== $aiRecommendation[$field]) {
                        $overriddenByAi[] = $field;
                    }
                    $final[$field] = $aiRecommendation[$field];
                    $filledFromAi[] = $field;
                }
            }
        }

        $final['confidence'] = max(
            (float) ($heuristics['confidence'] ?? 0),
            (float) ($aiRecommendation['confidence'] ?? 0)
        );
        $final['caveats'] = collect(array_merge(
            $heuristics['caveats'] ?? [],
            $aiRecommendation['caveats'] ?? [],
        ))->filter()->unique()->values()->all();

        if (! empty($filledFromAi)) {
            $final['source'] = $overriddenByAi === [] ? 'heuristics+ai' : 'ai_override';
            if (filled($aiRecommendation['rationale'] ?? null)) {
                $final['rationale'] = trim(($heuristics['rationale'] ?? '').' AI: '.($aiRecommendation['rationale'] ?? ''));
            }
        }

        return $final;
    }

    /**
     * @param  array<string, mixed>  $inspection
     * @return array<string, mixed>
     */
    private function compactInspectionForAi(array $inspection): array
    {
        return [
            'repository' => data_get($inspection, 'repository', []),
            'selection' => data_get($inspection, 'selection', []),
            'package_json' => data_get($inspection, 'package_json', []),
            'detected' => data_get($inspection, 'detected', []),
            'inspection_fingerprint' => data_get($inspection, 'meta.inspection_fingerprint'),
            'directory_entries' => collect(data_get($inspection, 'directory_entries', []))
                ->take(50)
                ->map(fn (array $entry): array => [
                    'path' => data_get($entry, 'path'),
                    'type' => data_get($entry, 'type'),
                    'name' => data_get($entry, 'name'),
                ])
                ->values()
                ->all(),
            'selected_files' => collect(data_get($inspection, 'selected_files', []))
                ->map(fn (array $file): array => [
                    'path' => data_get($file, 'path'),
                    'name' => data_get($file, 'name'),
                    'size' => data_get($file, 'size'),
                    'sha' => data_get($file, 'sha'),
                    'excerpt' => data_get($file, 'excerpt'),
                ])
                ->values()
                ->all(),
        ];
    }

    /**
     * @param  array<string, mixed>  $heuristics
     */
    private function shouldAllowAiOverrides(array $heuristics): bool
    {
        if ($this->hasHardHeuristicConstraints($heuristics)) {
            return false;
        }

        return (float) ($heuristics['confidence'] ?? 0) < 0.75;
    }

    /**
     * @param  array<string, mixed>  $heuristics
     */
    private function hasHardHeuristicConstraints(array $heuristics): bool
    {
        $buildPack = (string) ($heuristics['build_pack'] ?? '');

        return in_array($buildPack, [BuildPackTypes::DOCKERCOMPOSE->value, BuildPackTypes::DOCKERFILE->value], true);
    }

    private function hasRecommendedValue(string $field, mixed $value): bool
    {
        if ($value === null) {
            return false;
        }

        if (is_bool($value)) {
            return true;
        }

        if ($field === 'port' && is_int($value)) {
            return true;
        }

        return ! blank($value);
    }

    /**
     * @param  array<string, mixed>  $recommendation
     * @param  array<string, mixed>  $inspection
     * @return array<string, mixed>
     */
    private function normalizeRecommendation(array $recommendation, array $inspection): array
    {
        $allowedBuildPacks = collect(BuildPackTypes::cases())->map->value->all();
        $baseDirectory = $this->normalizeDirectoryPath((string) ($recommendation['base_directory'] ?? data_get($inspection, 'selection.base_directory', '/')));
        $publishDirectory = $this->normalizeNullableDirectoryPath($recommendation['publish_directory'] ?? null);

        return [
            'build_pack' => in_array($recommendation['build_pack'] ?? null, $allowedBuildPacks, true) ? $recommendation['build_pack'] : null,
            'install_command' => $this->normalizeCommand($recommendation['install_command'] ?? null),
            'build_command' => $this->normalizeCommand($recommendation['build_command'] ?? null),
            'start_command' => $this->normalizeCommand($recommendation['start_command'] ?? null),
            'port' => $this->normalizePort($recommendation['port'] ?? null),
            'base_directory' => $baseDirectory,
            'publish_directory' => $publishDirectory,
            'is_static' => filter_var($recommendation['is_static'] ?? false, FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE) ?? false,
            'confidence' => $this->normalizeConfidence($recommendation['confidence'] ?? null),
            'rationale' => Str::of((string) ($recommendation['rationale'] ?? ''))->trim()->limit(600)->value(),
            'caveats' => $this->normalizeCaveats($recommendation['caveats'] ?? []),
            'source' => (string) ($recommendation['source'] ?? 'heuristics'),
        ];
    }

    private function normalizeCommand(?string $command): ?string
    {
        if (blank($command)) {
            return null;
        }

        $normalized = Str::of($command)->trim()->value();

        if (preg_match(ValidationPatterns::SHELL_SAFE_COMMAND_PATTERN, $normalized) !== 1) {
            return null;
        }

        return $normalized;
    }

    private function normalizePort(mixed $port): ?int
    {
        if (! is_numeric($port)) {
            return null;
        }

        $normalized = (int) $port;

        return $normalized >= 1 && $normalized <= 65535 ? $normalized : null;
    }

    private function normalizeDirectoryPath(string $path): string
    {
        $normalized = '/'.trim($path, '/');

        if (str_contains($normalized, '..')) {
            return '/';
        }

        if ($normalized === '/' || preg_match(ValidationPatterns::DIRECTORY_PATH_PATTERN, $normalized) === 1) {
            if ($normalized === '//') {
                return '/';
            }

            $trimmed = rtrim($normalized, '/');

            return $trimmed === '' ? '/' : $trimmed;
        }

        return '/';
    }

    private function normalizeNullableDirectoryPath(?string $path): ?string
    {
        if (blank($path)) {
            return null;
        }

        $normalized = '/'.trim((string) $path, '/');

        if (str_contains($normalized, '..')) {
            return null;
        }

        return preg_match(ValidationPatterns::DIRECTORY_PATH_PATTERN, $normalized) === 1 ? $normalized : null;
    }

    /**
     * @param  array<int, string>|string  $caveats
     * @return array<int, string>
     */
    private function normalizeCaveats(array|string $caveats): array
    {
        return collect(is_array($caveats) ? $caveats : [$caveats])
            ->map(fn ($caveat): string => Str::of((string) $caveat)->trim()->limit(300)->value())
            ->filter()
            ->unique()
            ->values()
            ->all();
    }

    private function normalizeConfidence(mixed $confidence): float
    {
        if (is_numeric($confidence)) {
            return max(0, min(1, (float) $confidence));
        }

        return match (str((string) $confidence)->lower()->value()) {
            'high' => 0.9,
            'medium' => 0.6,
            'low' => 0.3,
            default => 0.0,
        };
    }

    /**
     * @param  array<string, mixed>  $inspection
     */
    private function findFile(array $inspection, array $names): ?array
    {
        return collect(data_get($inspection, 'selected_files', []))
            ->first(fn (array $file): bool => in_array($file['name'] ?? null, $names, true));
    }

    /**
     * @param  array<string, mixed>  $inspection
     */
    private function firstFileContent(array $inspection, array $names): ?string
    {
        return data_get($this->findFile($inspection, $names), 'content');
    }

    /**
     * @param  array<string, mixed>  $inspection
     */
    private function defaultInstallCommand(string $packageManager, array $inspection): string
    {
        $lockfiles = data_get($inspection, 'detected.lockfiles', []);

        return match ($packageManager) {
            'pnpm' => 'pnpm install --frozen-lockfile',
            'yarn' => 'yarn install --frozen-lockfile',
            'bun' => 'bun install --frozen-lockfile',
            default => in_array('package-lock.json', $lockfiles, true) ? 'npm ci' : 'npm install',
        };
    }

    private function scriptCommand(string $packageManager, string $script): string
    {
        return match ($packageManager) {
            'pnpm' => "pnpm {$script}",
            'yarn' => "yarn {$script}",
            'bun' => "bun run {$script}",
            default => "npm run {$script}",
        };
    }
}
