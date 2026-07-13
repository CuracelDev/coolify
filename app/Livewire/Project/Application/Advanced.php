<?php

namespace App\Livewire\Project\Application;

use App\Enums\BuildPackTypes;
use App\Models\Application;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Validate;
use Livewire\Component;
use Spatie\Url\Url;

class Advanced extends Component
{
    use AuthorizesRequests;

    public Application $application;

    #[Validate(['boolean'])]
    public bool $isForceHttpsEnabled = false;

    #[Validate(['boolean'])]
    public bool $isGitSubmodulesEnabled = false;

    #[Validate(['boolean'])]
    public bool $isGitLfsEnabled = false;

    #[Validate(['boolean'])]
    public bool $isGitShallowCloneEnabled = false;

    #[Validate(['boolean'])]
    public bool $isPreviewDeploymentsEnabled = false;

    #[Validate(['boolean'])]
    public bool $isPrDeploymentsPublicEnabled = false;

    #[Validate(['boolean'])]
    public bool $isAutoDeployEnabled = true;

    #[Validate(['boolean'])]
    public bool $isDeploymentOperatorEnabled = false;

    #[Validate(['boolean'])]
    public bool $disableBuildCache = false;

    #[Validate(['boolean'])]
    public bool $injectBuildArgsToDockerfile = true;

    #[Validate(['boolean'])]
    public bool $includeSourceCommitInBuild = false;

    #[Validate(['boolean'])]
    public bool $isLogDrainEnabled = false;

    #[Validate(['boolean'])]
    public bool $isGpuEnabled = false;

    #[Validate(['string'])]
    public string $gpuDriver = '';

    #[Validate(['string', 'nullable'])]
    public ?string $gpuCount = null;

    #[Validate(['string', 'nullable'])]
    public ?string $gpuDeviceIds = null;

    #[Validate(['string', 'nullable'])]
    public ?string $gpuOptions = null;

    #[Validate(['string', 'nullable'])]
    public ?string $stopGracePeriod = null;

    #[Validate(['boolean'])]
    public bool $isBuildServerEnabled = false;

    #[Validate(['boolean'])]
    public bool $isConsistentContainerNameEnabled = false;

    #[Validate(['string', 'nullable'])]
    public ?string $customInternalName = null;

    #[Validate(['boolean'])]
    public bool $isGzipEnabled = true;

    #[Validate(['boolean'])]
    public bool $isStripprefixEnabled = true;

    #[Validate(['boolean'])]
    public bool $isRawComposeDeploymentEnabled = false;

    #[Validate(['boolean'])]
    public bool $isConnectToDockerNetworkEnabled = false;

    #[Validate(['integer', 'min:0'])]
    public int $maxRestartCount = 10;

    public function mount()
    {
        try {
            $this->syncData();
        } catch (\Throwable $e) {
            return handleError($e, $this);
        }
    }

    public function syncData(bool $toModel = false)
    {
        if ($toModel) {
            $this->validate();
            $this->application->settings->is_force_https_enabled = $this->isForceHttpsEnabled;
            $this->application->settings->is_git_submodules_enabled = $this->isGitSubmodulesEnabled;
            $this->application->settings->is_git_lfs_enabled = $this->isGitLfsEnabled;
            $this->application->settings->is_git_shallow_clone_enabled = $this->isGitShallowCloneEnabled;
            $this->application->settings->is_preview_deployments_enabled = $this->isPreviewDeploymentsEnabled;
            $this->application->settings->is_pr_deployments_public_enabled = $this->isPrDeploymentsPublicEnabled;
            $this->application->settings->is_auto_deploy_enabled = $this->isAutoDeployEnabled;
            $this->application->settings->is_deployment_operator_enabled = $this->isDeploymentOperatorEnabled;
            $this->application->settings->is_log_drain_enabled = $this->isLogDrainEnabled;
            $this->application->settings->is_gpu_enabled = $this->isGpuEnabled;
            $this->application->settings->gpu_driver = $this->gpuDriver;
            $this->application->settings->gpu_count = $this->gpuCount;
            $this->application->settings->gpu_device_ids = $this->gpuDeviceIds;
            $this->application->settings->gpu_options = $this->gpuOptions;
            $this->application->settings->is_build_server_enabled = $this->isBuildServerEnabled;
            $this->application->settings->is_consistent_container_name_enabled = $this->isConsistentContainerNameEnabled;
            $this->application->settings->custom_internal_name = $this->customInternalName;
            $this->application->settings->is_gzip_enabled = $this->isGzipEnabled;
            $this->application->settings->is_stripprefix_enabled = $this->isStripprefixEnabled;
            $this->application->settings->is_raw_compose_deployment_enabled = $this->isRawComposeDeploymentEnabled;
            $this->application->settings->connect_to_docker_network = $this->isConnectToDockerNetworkEnabled;
            $this->application->settings->disable_build_cache = $this->disableBuildCache;
            $this->application->settings->inject_build_args_to_dockerfile = $this->injectBuildArgsToDockerfile;
            $this->application->settings->include_source_commit_in_build = $this->includeSourceCommitInBuild;
            $this->application->settings->save();
        } else {
            $this->isForceHttpsEnabled = $this->application->isForceHttpsEnabled();
            $this->isGzipEnabled = $this->application->isGzipEnabled();
            $this->isStripprefixEnabled = $this->application->isStripprefixEnabled();
            $this->isLogDrainEnabled = $this->application->isLogDrainEnabled();

            $this->isGitSubmodulesEnabled = $this->application->settings->is_git_submodules_enabled;
            $this->isGitLfsEnabled = $this->application->settings->is_git_lfs_enabled;
            $this->isGitShallowCloneEnabled = $this->application->settings->is_git_shallow_clone_enabled ?? false;
            $this->isPreviewDeploymentsEnabled = $this->application->settings->is_preview_deployments_enabled;
            $this->isPrDeploymentsPublicEnabled = $this->application->settings->is_pr_deployments_public_enabled ?? false;
            $this->isAutoDeployEnabled = $this->application->settings->is_auto_deploy_enabled;
            $this->isDeploymentOperatorEnabled = $this->application->settings->is_deployment_operator_enabled ?? false;
            $this->isGpuEnabled = $this->application->settings->is_gpu_enabled;
            $this->gpuDriver = $this->application->settings->gpu_driver;
            $this->gpuCount = $this->application->settings->gpu_count;
            $this->gpuDeviceIds = $this->application->settings->gpu_device_ids;
            $this->gpuOptions = $this->application->settings->gpu_options;
            $this->isBuildServerEnabled = $this->application->settings->is_build_server_enabled;
            $this->isConsistentContainerNameEnabled = $this->application->settings->is_consistent_container_name_enabled;
            $this->customInternalName = $this->application->settings->custom_internal_name;
            $this->isRawComposeDeploymentEnabled = $this->application->settings->is_raw_compose_deployment_enabled;
            $this->isConnectToDockerNetworkEnabled = $this->application->settings->connect_to_docker_network;
            $this->disableBuildCache = $this->application->settings->disable_build_cache;
            $this->injectBuildArgsToDockerfile = $this->application->settings->inject_build_args_to_dockerfile ?? true;
            $this->includeSourceCommitInBuild = $this->application->settings->include_source_commit_in_build ?? false;
            $this->maxRestartCount = $this->application->max_restart_count ?? 10;
        }

        // Load stop_grace_period separately since it has its own save handler
        // Convert null to empty string to prevent dirty detection issues
        $this->stopGracePeriod = $this->application->settings->stop_grace_period ?? '';
    }

    #[Computed]
    public function deploymentOperatorExpectedUrl(): ?string
    {
        $this->application->loadMissing('destination.server');

        $server = $this->application->destination?->server;
        if (! $server) {
            return null;
        }

        return generateUrl($server, $this->application->uuid);
    }

    /**
     * @return array{supported: bool, reason: string, expected_url: string|null, mode: string}
     */
    #[Computed]
    public function deploymentOperatorSupport(): array
    {
        $this->application->loadMissing('settings', 'destination.server');

        $buildPack = (string) $this->application->build_pack;

        if ($buildPack === BuildPackTypes::DOCKERCOMPOSE->value) {
            return ['supported' => false, 'reason' => 'Docker Compose apps are not supported for operator mode.', 'expected_url' => $this->deploymentOperatorExpectedUrl, 'mode' => 'unsupported'];
        }

        if (! in_array($buildPack, [BuildPackTypes::NIXPACKS->value, BuildPackTypes::DOCKERFILE->value, BuildPackTypes::STATIC->value, BuildPackTypes::RAILPACK->value], true)) {
            return ['supported' => false, 'reason' => 'This deployment mode is not supported for operator mode.', 'expected_url' => $this->deploymentOperatorExpectedUrl, 'mode' => 'unsupported'];
        }

        if ($this->application->additional_servers()->count() > 0) {
            return ['supported' => false, 'reason' => 'Apps with additional destinations are not supported for operator mode yet.', 'expected_url' => $this->deploymentOperatorExpectedUrl, 'mode' => 'unsupported'];
        }

        $expectedUrl = $this->deploymentOperatorExpectedUrl;
        if (blank($expectedUrl)) {
            return ['supported' => false, 'reason' => 'No generated Coolify URL is available for this app.', 'expected_url' => null, 'mode' => 'unsupported'];
        }

        $configuredDomains = collect($this->application->fqdns)
            ->map(fn (string $fqdn): string => trim($fqdn))
            ->filter();

        $normalizedConfiguredDomains = $configuredDomains
            ->map(fn (string $fqdn): string => $this->normalizeRouteUrl($fqdn))
            ->unique()
            ->values();

        $normalizedExpectedUrl = $this->normalizeRouteUrl($expectedUrl);

        if (! $normalizedConfiguredDomains->contains($normalizedExpectedUrl)) {
            return [
                'supported' => false,
                'reason' => 'Add the generated Coolify URL to Domains to enable operator mode verification.',
                'expected_url' => $expectedUrl,
                'mode' => 'unsupported',
            ];
        }

        if (in_array($buildPack, [BuildPackTypes::STATIC->value, BuildPackTypes::RAILPACK->value], true)) {
            return ['supported' => true, 'reason' => 'supported', 'expected_url' => $expectedUrl, 'mode' => 'verify_only'];
        }

        if ($buildPack === BuildPackTypes::NIXPACKS->value) {
            if ($this->application->settings->is_static && blank($this->application->publish_directory)) {
                return [
                    'supported' => false,
                    'reason' => 'Static Nixpacks apps need a publish directory to be set.',
                    'expected_url' => $expectedUrl,
                    'mode' => 'unsupported',
                ];
            }

            return ['supported' => true, 'reason' => 'supported', 'expected_url' => $expectedUrl, 'mode' => 'full'];
        }

        if ($buildPack === BuildPackTypes::DOCKERFILE->value) {
            if ($this->application->settings->is_static) {
                return [
                    'supported' => false,
                    'reason' => 'Static Dockerfile apps are not supported for operator mode.',
                    'expected_url' => $expectedUrl,
                    'mode' => 'unsupported',
                ];
            }

            if (filled($this->application->dockerfile)) {
                return [
                    'supported' => false,
                    'reason' => 'Inline Dockerfile is not supported for operator mode. Use a Dockerfile in your repository.',
                    'expected_url' => $expectedUrl,
                    'mode' => 'unsupported',
                ];
            }

            return ['supported' => true, 'reason' => 'supported', 'expected_url' => $expectedUrl, 'mode' => 'full'];
        }

        return ['supported' => false, 'reason' => 'This app is not supported for operator mode.', 'expected_url' => $expectedUrl, 'mode' => 'unsupported'];
    }

    private function normalizeRouteUrl(string $url): string
    {
        try {
            $parsedUrl = Url::fromString($url);
            $path = $parsedUrl->getPath();

            return $parsedUrl
                ->withPort(null)
                ->withPath($path === '/' ? '' : rtrim($path, '/'))
                ->__toString();
        } catch (\Throwable) {
            return trim($url);
        }
    }

    private function resetDefaultLabels()
    {
        if ($this->application->settings->is_container_label_readonly_enabled === false) {
            return;
        }
        $customLabels = str(implode('|coolify|', generateLabelsApplication($this->application)))->replace('|coolify|', "\n");
        $this->application->custom_labels = base64_encode($customLabels);
        $this->application->save();
    }

    public function instantSave()
    {
        try {
            $this->authorize('update', $this->application);
            $reset = false;
            if ($this->isLogDrainEnabled) {
                if (! $this->application->destination->server->isLogDrainEnabled()) {
                    $this->isLogDrainEnabled = false;
                    $this->syncData(true);
                    $this->dispatch('error', 'Log drain is not enabled on this server.');

                    return;
                }
            }
            if ($this->application->isForceHttpsEnabled() !== $this->isForceHttpsEnabled ||
                $this->application->isGzipEnabled() !== $this->isGzipEnabled ||
                $this->application->isStripprefixEnabled() !== $this->isStripprefixEnabled
            ) {
                $reset = true;
            }

            if ($this->application->settings->is_raw_compose_deployment_enabled) {
                $this->application->oldRawParser();
            } else {
                $this->application->parse();
            }
            $this->syncData(true);

            if ($reset) {
                $this->resetDefaultLabels();
            }

            $this->dispatch('success', 'Settings saved.');
            $this->dispatch('configurationChanged');
        } catch (\Throwable $e) {
            return handleError($e, $this);
        }
    }

    public function submit()
    {
        try {
            $this->authorize('update', $this->application);
            if ($this->gpuCount && $this->gpuDeviceIds) {
                $this->dispatch('error', 'You cannot set both GPU count and GPU device IDs.');
                $this->gpuCount = null;
                $this->gpuDeviceIds = null;
                $this->syncData(true);

                return;
            }
            $this->syncData(true);
            $this->dispatch('success', 'Settings saved.');
            $this->dispatch('configurationChanged');
        } catch (\Throwable $e) {
            return handleError($e, $this);
        }
    }

    public function saveCustomName()
    {
        try {
            $this->authorize('update', $this->application);

            if (str($this->customInternalName)->isNotEmpty()) {
                $this->customInternalName = str($this->customInternalName)->slug()->value();
            } else {
                $this->customInternalName = null;
            }
            if (is_null($this->customInternalName)) {
                $this->syncData(true);
                $this->dispatch('success', 'Custom name saved.');
                $this->dispatch('configurationChanged');

                return;
            }
            $customInternalName = $this->customInternalName;
            $server = $this->application->destination->server;
            $allApplications = $server->applications();

            $foundSameInternalName = $allApplications->filter(function ($application) {
                return $application->id !== $this->application->id && $application->settings->custom_internal_name === $this->customInternalName;
            });
            if ($foundSameInternalName->isNotEmpty()) {
                $this->dispatch('error', 'This custom container name is already in use by another application on this server.');
                $this->customInternalName = $customInternalName;
                $this->syncData(true);

                return;
            }
            $this->syncData(true);
            $this->dispatch('success', 'Custom name saved.');
            $this->dispatch('configurationChanged');
        } catch (\Throwable $e) {
            return handleError($e, $this);
        }
    }

    public function saveStopGracePeriod()
    {
        try {
            $this->authorize('update', $this->application);

            $validated = Validator::make(
                ['stopGracePeriod' => $this->stopGracePeriod === '' ? null : $this->stopGracePeriod],
                ['stopGracePeriod' => ['nullable', 'integer', 'min:'.MIN_STOP_GRACE_PERIOD_SECONDS, 'max:'.MAX_STOP_GRACE_PERIOD_SECONDS]],
                [],
                ['stopGracePeriod' => 'stop grace period']
            )->validate();

            $this->application->settings->stop_grace_period = $validated['stopGracePeriod'] === null
                ? null
                : (int) $validated['stopGracePeriod'];
            $this->application->settings->save();

            $this->dispatch('success', 'Stop grace period updated.');
        } catch (ValidationException $e) {
            throw $e;
        } catch (\Throwable $e) {
            return handleError($e, $this);
        }
    }

    public function saveMaxRestartCount()
    {
        try {
            $this->authorize('update', $this->application);
            $this->validate([
                'maxRestartCount' => 'integer|min:0',
            ]);
            $this->application->max_restart_count = $this->maxRestartCount;
            $this->application->save();
            $this->dispatch('success', 'Max restart count saved.');
        } catch (\Throwable $e) {
            return handleError($e, $this);
        }
    }

    public function render()
    {
        return view('livewire.project.application.advanced');
    }
}
