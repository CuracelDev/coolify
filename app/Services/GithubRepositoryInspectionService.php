<?php

namespace App\Services;

use App\Models\GithubApp;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use RuntimeException;

class GithubRepositoryInspectionService
{
    private const MAX_TEXT_FILE_LENGTH = 12000;

    private const CONFIG_EXCERPT_LENGTH = 1200;

    private const README_EXCERPT_LENGTH = 1600;

    /**
     * @var array<int, string>
     */
    private const FULL_CONTENT_FILES = [
        'package.json',
        'next.config.js',
        'next.config.mjs',
        'next.config.ts',
        'vite.config.js',
        'vite.config.mjs',
        'vite.config.cjs',
        'vite.config.ts',
        'Dockerfile',
        'docker-compose.yml',
        'docker-compose.yaml',
        'compose.yml',
        'compose.yaml',
    ];

    /**
     * @var array<int, string>
     */
    private const CANDIDATE_FILES = [
        'package.json',
        'package-lock.json',
        'pnpm-lock.yaml',
        'yarn.lock',
        'bun.lockb',
        'next.config.js',
        'next.config.mjs',
        'next.config.ts',
        'vite.config.js',
        'vite.config.mjs',
        'vite.config.cjs',
        'vite.config.ts',
        'Dockerfile',
        'docker-compose.yml',
        'docker-compose.yaml',
        'compose.yml',
        'compose.yaml',
        'nixpacks.toml',
        'railpack.toml',
        'railpack.json',
        'README.md',
    ];

    /**
     * @return array<string, mixed>
     */
    public function inspectPrivateRepository(GithubApp $githubApp, string $owner, string $repo, string $branch, array $selection = []): array
    {
        $normalizedBaseDirectory = $this->normalizeDirectoryPath((string) data_get($selection, 'base_directory', '/'));
        $normalizedSelection = [
            'build_pack' => data_get($selection, 'build_pack'),
            'base_directory' => $normalizedBaseDirectory,
            'port' => data_get($selection, 'port'),
            'is_static' => (bool) data_get($selection, 'is_static', false),
        ];
        $request = $this->githubRequest($githubApp);

        $directoryEntries = $this->fetchDirectoryEntries($request, $owner, $repo, $branch, $normalizedBaseDirectory);
        $selectedFiles = $this->fetchSelectedFiles($request, $owner, $repo, $branch, $normalizedBaseDirectory);
        $packageJson = $this->parsePackageJson($selectedFiles);
        $lockfiles = collect($selectedFiles)
            ->pluck('name')
            ->filter(fn (string $name): bool => str($name)->contains('lock'))
            ->values()
            ->all();

        return [
            'repository' => [
                'owner' => $owner,
                'repo' => $repo,
                'branch' => $branch,
            ],
            'selection' => $normalizedSelection,
            'directory_entries' => $directoryEntries,
            'selected_files' => $selectedFiles,
            'package_json' => $packageJson,
            'detected' => [
                'lockfiles' => $lockfiles,
                'package_manager' => $this->detectPackageManager($packageJson, $selectedFiles),
            ],
            'meta' => [
                'inspection_fingerprint' => $this->inspectionFingerprint(
                    owner: $owner,
                    repo: $repo,
                    branch: $branch,
                    selection: $normalizedSelection,
                    directoryEntries: $directoryEntries,
                    selectedFiles: $selectedFiles,
                ),
            ],
        ];
    }

    private function githubRequest(GithubApp $githubApp): PendingRequest
    {
        $token = generateGithubInstallationToken($githubApp);

        return Http::GitHub($githubApp->api_url, $token)
            ->timeout(20)
            ->retry(2, 200, throw: false);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function fetchDirectoryEntries(PendingRequest $request, string $owner, string $repo, string $branch, string $baseDirectory): array
    {
        $directoryPath = $this->normalizeRepoPath($baseDirectory);
        $response = $request->get($this->contentsEndpoint($owner, $repo, $directoryPath), ['ref' => $branch]);

        if ($response->status() === 404) {
            throw new RuntimeException("Selected base directory '{$baseDirectory}' was not found or could not be inspected in {$owner}/{$repo}@{$branch}.");
        }

        if (! $response->successful()) {
            throw new RuntimeException("Failed to inspect selected base directory '{$baseDirectory}' in {$owner}/{$repo}@{$branch}.");
        }

        $json = $response->json();
        if (! is_array($json)) {
            return [];
        }

        return collect($json)
            ->filter(fn ($entry): bool => is_array($entry))
            ->map(fn (array $entry): array => [
                'path' => '/'.ltrim((string) data_get($entry, 'path', ''), '/'),
                'type' => data_get($entry, 'type'),
                'name' => data_get($entry, 'name'),
            ])
            ->values()
            ->all();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function fetchSelectedFiles(PendingRequest $request, string $owner, string $repo, string $branch, string $baseDirectory): array
    {
        $candidatePaths = collect(self::CANDIDATE_FILES)
            ->map(fn (string $file): string => $this->joinRepoPath($baseDirectory, $file))
            ->when($baseDirectory !== '/', function ($paths) {
                return $paths->push('/README.md');
            })
            ->unique()
            ->values();

        return $candidatePaths
            ->map(fn (string $path): ?array => $this->fetchFile($request, $owner, $repo, $branch, $path))
            ->filter()
            ->values()
            ->all();
    }

    /**
     * @return array<string, mixed>|null
     */
    private function fetchFile(PendingRequest $request, string $owner, string $repo, string $branch, string $path): ?array
    {
        $response = $request->get($this->contentsEndpoint($owner, $repo, $this->normalizeRepoPath($path)), ['ref' => $branch]);

        if ($response->status() === 404) {
            return null;
        }

        if (! $response->successful()) {
            throw new RuntimeException("Failed to inspect repository file: {$path}");
        }

        $json = $response->json();
        if (! is_array($json) || data_get($json, 'type') !== 'file') {
            return null;
        }

        $name = (string) data_get($json, 'name', basename($path));
        $content = $this->decodeFileContent($name, $json);

        return [
            'path' => '/'.ltrim((string) data_get($json, 'path', $path), '/'),
            'name' => $name,
            'size' => (int) data_get($json, 'size', 0),
            'sha' => data_get($json, 'sha'),
            'content' => $this->contentForHeuristics($name, $content),
            'excerpt' => $this->excerptFor($name, $content),
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function decodeFileContent(string $name, array $payload): ?string
    {
        if (! $this->supportsTextContent($name)) {
            return null;
        }

        $content = (string) data_get($payload, 'content', '');
        $encoding = (string) data_get($payload, 'encoding', '');

        if ($content === '' || $encoding !== 'base64') {
            return null;
        }

        $decoded = base64_decode(str_replace("\n", '', $content), true);
        if ($decoded === false) {
            return null;
        }

        return Str::of($decoded)->substr(0, self::MAX_TEXT_FILE_LENGTH)->value();
    }

    private function supportsTextContent(string $name): bool
    {
        return in_array($name, ['Dockerfile', 'package.json', 'package-lock.json', 'pnpm-lock.yaml', 'yarn.lock', 'README.md', 'nixpacks.toml', 'railpack.toml', 'railpack.json', 'docker-compose.yml', 'docker-compose.yaml', 'compose.yml', 'compose.yaml'], true)
            || Str::endsWith($name, ['.js', '.mjs', '.cjs', '.ts', '.json', '.md', '.toml', '.yaml', '.yml']);
    }

    private function excerptFor(string $name, ?string $content): ?string
    {
        if (blank($content)) {
            return null;
        }

        $limit = $name === 'README.md' ? self::README_EXCERPT_LENGTH : self::CONFIG_EXCERPT_LENGTH;

        return Str::of($content)->substr(0, $limit)->value();
    }

    private function contentForHeuristics(string $name, ?string $content): ?string
    {
        if (blank($content)) {
            return null;
        }

        return in_array($name, self::FULL_CONTENT_FILES, true) ? $content : null;
    }

    /**
     * @param  array<int, array<string, mixed>>  $selectedFiles
     * @return array<string, mixed>
     */
    private function parsePackageJson(array $selectedFiles): array
    {
        $packageJsonFile = collect($selectedFiles)
            ->first(fn (array $file): bool => $file['name'] === 'package.json');
        $packageJsonContent = data_get($packageJsonFile, 'content');

        if (blank($packageJsonContent)) {
            return [];
        }

        $decoded = json_decode($packageJsonContent, true);
        if (! is_array($decoded)) {
            return [];
        }

        return [
            'name' => data_get($decoded, 'name'),
            'scripts' => data_get($decoded, 'scripts', []),
            'engines' => data_get($decoded, 'engines', []),
            'package_manager' => data_get($decoded, 'packageManager'),
            'dependencies' => $this->filterInterestingDependencies(data_get($decoded, 'dependencies', [])),
            'dev_dependencies' => $this->filterInterestingDependencies(data_get($decoded, 'devDependencies', [])),
        ];
    }

    /**
     * @param  array<string, string>  $dependencies
     * @return array<string, string>
     */
    private function filterInterestingDependencies(array $dependencies): array
    {
        $interestingPackages = [
            'next',
            'vite',
            'react',
            'react-dom',
            'vue',
            'svelte',
            'astro',
            '@sveltejs/kit',
            '@remix-run/node',
            '@remix-run/react',
        ];

        return collect($dependencies)
            ->filter(fn (string $version, string $package): bool => in_array($package, $interestingPackages, true))
            ->all();
    }

    /**
     * @param  array<string, mixed>  $packageJson
     * @param  array<int, array<string, mixed>>  $selectedFiles
     */
    private function detectPackageManager(array $packageJson, array $selectedFiles): string
    {
        $packageManager = (string) data_get($packageJson, 'package_manager', '');
        if ($packageManager !== '') {
            return str($packageManager)->before('@')->lower()->value();
        }

        $fileNames = collect($selectedFiles)->pluck('name');

        return match (true) {
            $fileNames->contains('pnpm-lock.yaml') => 'pnpm',
            $fileNames->contains('yarn.lock') => 'yarn',
            $fileNames->contains('bun.lockb') => 'bun',
            default => 'npm',
        };
    }

    private function normalizeDirectoryPath(string $directory): string
    {
        $normalized = '/'.trim($directory, '/');

        return $normalized === '/' ? '/' : rtrim($normalized, '/');
    }

    private function joinRepoPath(string $baseDirectory, string $file): string
    {
        if ($baseDirectory === '/') {
            return '/'.$file;
        }

        return $baseDirectory.'/'.$file;
    }

    private function normalizeRepoPath(string $path): string
    {
        return trim($path, '/');
    }

    private function contentsEndpoint(string $owner, string $repo, string $path = ''): string
    {
        $encodedPath = collect(explode('/', $path))
            ->filter()
            ->map(fn (string $segment): string => rawurlencode($segment))
            ->implode('/');

        $endpoint = "/repos/{$owner}/{$repo}/contents";

        return $encodedPath === '' ? $endpoint : $endpoint.'/'.$encodedPath;
    }

    /**
     * @param  array<string, mixed>  $selection
     * @param  array<int, array<string, mixed>>  $directoryEntries
     * @param  array<int, array<string, mixed>>  $selectedFiles
     */
    private function inspectionFingerprint(string $owner, string $repo, string $branch, array $selection, array $directoryEntries, array $selectedFiles): string
    {
        $payload = [
            'repository' => [
                'owner' => $owner,
                'repo' => $repo,
                'branch' => $branch,
            ],
            'selection' => [
                'build_pack' => data_get($selection, 'build_pack'),
                'base_directory' => data_get($selection, 'base_directory', '/'),
                'port' => data_get($selection, 'port'),
                'is_static' => (bool) data_get($selection, 'is_static', false),
            ],
            'directory_entries' => collect($directoryEntries)
                ->map(fn (array $entry): array => [
                    'path' => data_get($entry, 'path'),
                    'type' => data_get($entry, 'type'),
                    'name' => data_get($entry, 'name'),
                ])
                ->values()
                ->all(),
            'selected_files' => collect($selectedFiles)
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

        return hash('sha256', json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }
}
