<?php

namespace App\Services;

use App\Enums\ApplicationDeploymentStatus;
use App\Enums\BuildPackTypes;
use App\Models\Application;
use App\Models\ApplicationDeploymentQueue;
use App\Models\ApplicationPreview;
use App\Models\EnvironmentVariable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;
use Spatie\Url\Url;
use Symfony\Component\Yaml\Yaml;
use Throwable;
use Visus\Cuid2\Cuid2;

class ApplicationDeploymentOperatorService
{
    private const MAX_TOTAL_ATTEMPTS = 2;

    private const CUSTOM_DOMAIN_MAX_ATTEMPTS = 3;

    private const INTERNAL_CUSTOM_DOMAIN_RECONCILE_HTTP_STATUSES = [502, 503, 504];

    private const PROVIDER_EDGE_CUSTOM_DOMAIN_HTTP_STATUSES = [522, 523, 524, 525, 526];

    /**
     * @return array<string, mixed>
     */
    public function runVerification(ApplicationDeploymentQueue $deployment): array
    {
        if (! $this->claimDeployment($deployment)) {
            return ['result' => 'already_claimed'];
        }

        $deployment->refresh();
        $deployment->loadMissing('application.settings', 'application.destination.server');
        $this->initializeAttemptMetadata($deployment);

        if ($this->isPreviewDeployment($deployment)) {
            return $this->runPreviewVerification($deployment);
        }

        return $this->runPrimaryVerification($deployment);
    }

    /**
     * @return array<string, mixed>
     */
    public function runPrimaryVerification(ApplicationDeploymentQueue $deployment): array
    {
        if ($deployment->application?->additional_servers()->count() > 0) {
            return $this->runMultiDestinationCanaryVerification($deployment);
        }

        if ($deployment->application?->build_pack === BuildPackTypes::DOCKERCOMPOSE->value) {
            return $this->runComposePrimaryVerification($deployment);
        }

        $eligibility = $this->eligibility($deployment);
        if (! $eligibility['eligible']) {
            return $this->finalize(
                $deployment,
                'ineligible',
                null,
                [
                    'result' => 'skipped',
                    'reason' => $eligibility['reason'],
                    'attempt' => $this->currentAttempt($deployment),
                ],
            );
        }

        $latestGuard = $this->latestDeploymentGuard($deployment);
        if (! $latestGuard['is_latest']) {
            return $this->finalize(
                $deployment,
                'superseded',
                null,
                [
                    'result' => 'skipped',
                    'reason' => $latestGuard['reason'],
                    'attempt' => $this->currentAttempt($deployment),
                ],
            );
        }

        $verificationTarget = $this->deriveVerificationTarget($deployment);

        if ($deployment->status === ApplicationDeploymentStatus::FINISHED->value) {
            $verification = $this->probeVerificationTarget($verificationTarget);
            if ($verification['pass']) {
                $verification['custom_domains'] = $this->observeCustomDomains($deployment, $verificationTarget);
            }

            return $this->finalize(
                $deployment,
                $verification['pass'] ? 'recorded' : 'verification_failed',
                null,
                $verification,
            );
        }

        $classification = $this->classifyDeployment($deployment);

        if (($classification['action'] ?? 'record') === 'record') {
            return $this->finalize(
                $deployment,
                'recorded',
                $classification['rule'] ?? null,
                [
                    'result' => 'recorded',
                    'classification' => $classification['classification'],
                    'attempt' => $this->currentAttempt($deployment),
                    'target' => $verificationTarget,
                ],
            );
        }

        $remediation = $this->applyRemediation($deployment, (string) $classification['rule']);
        if (! ($remediation['changed'] ?? false)) {
            return $this->finalize(
                $deployment,
                'no_retry',
                $classification['rule'] ?? null,
                [
                    'result' => 'no_retry',
                    'classification' => $classification['classification'],
                    'attempt' => $this->currentAttempt($deployment),
                    'target' => $verificationTarget,
                    'reason' => $remediation['reason'] ?? 'no_config_change',
                ],
            );
        }

        if (! $this->shouldRetry($deployment)) {
            return $this->finalize(
                $deployment,
                'max_attempts_reached',
                $classification['rule'] ?? null,
                [
                    'result' => 'no_retry',
                    'classification' => $classification['classification'],
                    'attempt' => $this->currentAttempt($deployment),
                    'target' => $verificationTarget,
                    'reason' => 'max_attempts_reached',
                ],
            );
        }

        $latestGuard = $this->latestDeploymentGuard($deployment);
        if (! $latestGuard['is_latest']) {
            return $this->finalize(
                $deployment,
                'superseded',
                $classification['rule'] ?? null,
                [
                    'result' => 'skipped',
                    'classification' => $classification['classification'],
                    'attempt' => $this->currentAttempt($deployment),
                    'reason' => $latestGuard['reason'],
                ],
            );
        }

        $retry = $this->queueRetry($deployment, (string) $classification['rule']);

        if (! ($retry['deployment'] ?? null) instanceof ApplicationDeploymentQueue) {
            return $this->finalize(
                $deployment,
                'no_retry',
                $classification['rule'] ?? null,
                [
                    'result' => 'no_retry',
                    'classification' => $classification['classification'],
                    'attempt' => $this->currentAttempt($deployment),
                    'target' => $verificationTarget,
                    'reason' => $retry['reason'] ?? 'retry_not_created',
                ],
            );
        }

        return $this->finalize(
            $deployment,
            'retry_queued',
            $classification['rule'] ?? null,
            [
                'result' => 'retry_queued',
                'classification' => $classification['classification'],
                'attempt' => $this->currentAttempt($deployment),
                'target' => $verificationTarget,
                'retry_deployment_id' => $retry['deployment']->id,
            ],
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function runPreviewVerification(ApplicationDeploymentQueue $deployment): array
    {
        $eligibility = $this->previewEligibility($deployment);
        if (! $eligibility['eligible']) {
            return $this->finalize(
                $deployment,
                'ineligible',
                null,
                $this->previewMetadata($deployment, [
                    'result' => 'skipped',
                    'reason' => $eligibility['reason'],
                    'targets' => [],
                ]),
            );
        }

        $latestGuard = $this->latestDeploymentGuard($deployment);
        if (! $latestGuard['is_latest']) {
            return $this->finalize(
                $deployment,
                'superseded',
                null,
                $this->previewMetadata($deployment, [
                    'result' => 'skipped',
                    'reason' => $latestGuard['reason'],
                    'targets' => [],
                ]),
            );
        }

        $context = $this->previewVerificationContext($deployment);
        if (! $context['eligible']) {
            return $this->finalize(
                $deployment,
                'ineligible',
                null,
                $this->previewMetadata($deployment, [
                    'result' => 'skipped',
                    'reason' => $context['reason'],
                    'targets' => [],
                ]),
            );
        }

        if ($deployment->status !== ApplicationDeploymentStatus::FINISHED->value) {
            return $this->finalize(
                $deployment,
                'recorded',
                null,
                $this->previewMetadata($deployment, [
                    'result' => 'recorded',
                    'reason' => 'deployment_not_finished',
                    'deployment_status' => $deployment->status,
                    'targets' => collect($context['targets'])
                        ->map(fn (string $target): array => [
                            'target' => $target,
                            'result' => 'not_probed',
                            'http_status' => null,
                            'pass' => null,
                            'reason' => 'deployment_not_finished',
                        ])
                        ->all(),
                ], $context),
            );
        }

        $verification = $this->probePreviewVerificationTargets($context['targets']);

        return $this->finalize(
            $deployment,
            $verification['pass'] ? 'recorded' : 'verification_failed',
            null,
            $this->previewMetadata($deployment, $verification, $context),
        );
    }

    public function claimDeployment(ApplicationDeploymentQueue $deployment): bool
    {
        $updated = ApplicationDeploymentQueue::query()
            ->whereKey($deployment->id)
            ->whereNull('operator_status')
            ->update([
                'operator_status' => 'verifying',
            ]);

        return $updated === 1;
    }

    public function initializeAttemptMetadata(ApplicationDeploymentQueue $deployment): void
    {
        $rootDeploymentId = $deployment->operator_root_deployment_id ?? $deployment->id;
        $attempt = $deployment->operator_attempt ?? ($rootDeploymentId === $deployment->id ? 1 : 2);

        $deployment->update([
            'operator_root_deployment_id' => $rootDeploymentId,
            'operator_attempt' => $attempt,
        ]);

        $deployment->refresh();
    }

    /**
     * @return array{eligible: bool, reason: string}
     */
    public function eligibility(ApplicationDeploymentQueue $deployment): array
    {
        $application = $deployment->application;
        if (! $application) {
            return ['eligible' => false, 'reason' => 'missing_application'];
        }

        if ($deployment->pull_request_id !== 0) {
            return ['eligible' => false, 'reason' => 'preview_deployment'];
        }

        if (! data_get($application, 'settings.is_deployment_operator_enabled', false)) {
            return ['eligible' => false, 'reason' => 'operator_disabled'];
        }

        if ($application->additional_servers()->count() > 0) {
            $context = $this->multiDestinationCanaryContext($deployment);

            return [
                'eligible' => $context['eligible'],
                'reason' => $context['reason'],
            ];
        }

        if ($deployment->only_this_server) {
            return ['eligible' => false, 'reason' => 'non_primary_rollout'];
        }

        if ((int) $deployment->destination_id !== (int) $application->destination_id) {
            return ['eligible' => false, 'reason' => 'non_primary_destination'];
        }

        if ($application->additional_servers()->count() > 0) {
            return ['eligible' => false, 'reason' => 'additional_destinations_present'];
        }

        if ($application->build_pack === BuildPackTypes::DOCKERCOMPOSE->value) {
            $context = $this->composeVerificationContext($deployment);

            return [
                'eligible' => $context['eligible'],
                'reason' => $context['reason'],
            ];
        }

        if ($application->build_pack === BuildPackTypes::NIXPACKS->value) {
            if ($this->deriveVerificationTarget($deployment) === null) {
                return ['eligible' => false, 'reason' => 'default_generated_url_only_required'];
            }

            if (! $application->settings->is_static && filled($application->publish_directory)) {
                return ['eligible' => true, 'reason' => 'eligible_nixpacks'];
            }

            if ($application->settings->is_static && blank($application->publish_directory)) {
                return ['eligible' => false, 'reason' => 'missing_publish_directory'];
            }

            return ['eligible' => true, 'reason' => 'eligible_nixpacks'];
        }

        if ($application->build_pack === BuildPackTypes::DOCKERFILE->value) {
            if ($this->deriveVerificationTarget($deployment) === null) {
                return ['eligible' => false, 'reason' => 'default_generated_url_only_required'];
            }

            if ($application->settings->is_static) {
                return ['eligible' => false, 'reason' => 'dockerfile_static_excluded'];
            }

            if (filled($application->dockerfile)) {
                return ['eligible' => false, 'reason' => 'inline_dockerfile_excluded'];
            }

            return ['eligible' => true, 'reason' => 'eligible_dockerfile'];
        }

        if (in_array($application->build_pack, [BuildPackTypes::STATIC->value, BuildPackTypes::RAILPACK->value], true)) {
            if ($this->deriveVerificationTarget($deployment) === null) {
                return ['eligible' => false, 'reason' => 'default_generated_url_only_required'];
            }

            return [
                'eligible' => true,
                'reason' => $application->build_pack === BuildPackTypes::RAILPACK->value
                    ? 'eligible_railpack_verify_only'
                    : 'eligible_static_verify_only',
            ];
        }

        return ['eligible' => false, 'reason' => 'unsupported_build_pack'];
    }

    /**
     * @return array{eligible: bool, reason: string}
     */
    public function previewEligibility(ApplicationDeploymentQueue $deployment): array
    {
        $application = $deployment->application;
        if (! $application) {
            return ['eligible' => false, 'reason' => 'missing_application'];
        }

        if (! $this->isPreviewDeployment($deployment)) {
            return ['eligible' => false, 'reason' => 'primary_deployment'];
        }

        if (! data_get($application, 'settings.is_deployment_operator_enabled', false)) {
            return ['eligible' => false, 'reason' => 'operator_disabled'];
        }

        $context = $this->previewVerificationContext($deployment);
        if (! $context['eligible']) {
            return ['eligible' => false, 'reason' => $context['reason']];
        }

        return match ($application->build_pack) {
            BuildPackTypes::NIXPACKS->value => $application->settings->is_static
                ? ['eligible' => false, 'reason' => 'preview_static_excluded']
                : ['eligible' => true, 'reason' => 'eligible_preview_nixpacks'],
            BuildPackTypes::DOCKERFILE->value => $this->previewDockerfileEligibility($application),
            BuildPackTypes::RAILPACK->value => ['eligible' => true, 'reason' => 'eligible_preview_railpack'],
            default => ['eligible' => false, 'reason' => 'unsupported_preview_build_pack'],
        };
    }

    /**
     * @return array{is_latest: bool, reason: string}
     */
    public function latestDeploymentGuard(ApplicationDeploymentQueue $deployment): array
    {
        $query = ApplicationDeploymentQueue::query()
            ->where('application_id', $deployment->application_id)
            ->where('pull_request_id', $deployment->pull_request_id)
            ->where('id', '>', $deployment->id);

        if ($query->exists()) {
            return ['is_latest' => false, 'reason' => 'newer_deployment_exists'];
        }

        $pendingQuery = ApplicationDeploymentQueue::query()
            ->where('application_id', $deployment->application_id)
            ->where('pull_request_id', $deployment->pull_request_id)
            ->where('id', '>', $deployment->id)
            ->whereIn('status', [
                ApplicationDeploymentStatus::QUEUED->value,
                ApplicationDeploymentStatus::IN_PROGRESS->value,
            ]);

        if ($pendingQuery->exists()) {
            return ['is_latest' => false, 'reason' => 'newer_active_deployment_exists'];
        }

        return ['is_latest' => true, 'reason' => 'latest'];
    }

    public function deriveVerificationTarget(ApplicationDeploymentQueue $deployment): ?string
    {
        $application = $deployment->application;
        $server = $deployment->server;

        if ($application?->build_pack === BuildPackTypes::DOCKERCOMPOSE->value) {
            $context = $this->composeVerificationContext($deployment);

            return $context['eligible'] ? $context['target'] : null;
        }

        if (! $application || ! $server) {
            return null;
        }

        $configuredDomains = collect($application->fqdns)
            ->map(fn (string $fqdn): string => trim($fqdn))
            ->filter();

        if ($configuredDomains->isEmpty()) {
            return null;
        }

        $normalizedConfiguredDomains = $configuredDomains
            ->map(fn (string $fqdn): string => $this->normalizeRouteUrl($fqdn))
            ->unique()
            ->values();
        $expectedDefaultRoute = $this->normalizeRouteUrl($this->expectedDefaultRoute($server, $application->uuid));

        return $normalizedConfiguredDomains->contains($expectedDefaultRoute)
            ? $expectedDefaultRoute
            : null;
    }

    /**
     * @return array{eligible: bool, reason: string, preview: ApplicationPreview|null, targets: array<int, string>, lineage_key: string}
     */
    public function previewVerificationContext(ApplicationDeploymentQueue $deployment): array
    {
        $application = $deployment->application;
        $lineageKey = $this->previewLineageKey($deployment);

        if (! $application) {
            return [
                'eligible' => false,
                'reason' => 'missing_application',
                'preview' => null,
                'targets' => [],
                'lineage_key' => $lineageKey,
            ];
        }

        $previews = ApplicationPreview::query()
            ->where('application_id', $deployment->application_id)
            ->where('pull_request_id', $deployment->pull_request_id)
            ->get();

        if ($previews->count() === 0) {
            return [
                'eligible' => false,
                'reason' => 'missing_preview_deployment',
                'preview' => null,
                'targets' => [],
                'lineage_key' => $lineageKey,
            ];
        }

        if ($previews->count() > 1) {
            return [
                'eligible' => false,
                'reason' => 'ambiguous_preview_deployment',
                'preview' => null,
                'targets' => [],
                'lineage_key' => $lineageKey,
            ];
        }

        /** @var ApplicationPreview $preview */
        $preview = $previews->first();
        $targets = collect(explode(',', (string) $preview->fqdn))
            ->map(fn (string $target): string => trim($target))
            ->filter()
            ->map(fn (string $target): string => $this->normalizeRouteUrl($target))
            ->unique()
            ->values();

        if ($targets->isEmpty()) {
            return [
                'eligible' => false,
                'reason' => 'missing_preview_fqdn_target_set',
                'preview' => $preview,
                'targets' => [],
                'lineage_key' => $lineageKey,
            ];
        }

        $primaryTargets = collect($application->fqdns)
            ->map(fn (string $fqdn): string => trim($fqdn))
            ->filter()
            ->map(fn (string $fqdn): string => $this->normalizeRouteUrl($fqdn));

        if ($deployment->server) {
            $primaryTargets->push($this->normalizeRouteUrl($this->expectedDefaultRoute($deployment->server, $application->uuid)));
        }

        if ($targets->intersect($primaryTargets->unique()->values())->isNotEmpty()) {
            return [
                'eligible' => false,
                'reason' => 'ambiguous_preview_fqdn_target_set',
                'preview' => $preview,
                'targets' => [],
                'lineage_key' => $lineageKey,
            ];
        }

        return [
            'eligible' => true,
            'reason' => 'preview_target_set_derived',
            'preview' => $preview,
            'targets' => $targets->all(),
            'lineage_key' => $lineageKey,
        ];
    }

    /**
     * @return array{action: string, classification: string, rule: string|null}
     */
    public function classifyDeployment(ApplicationDeploymentQueue $deployment): array
    {
        $application = $deployment->application;

        if ($application && $application->build_pack === BuildPackTypes::STATIC->value) {
            return [
                'action' => 'record',
                'classification' => 'standalone_static_verify_only',
                'rule' => null,
            ];
        }

        if ($application && $application->build_pack === BuildPackTypes::RAILPACK->value) {
            return [
                'action' => 'record',
                'classification' => 'railpack_verify_only',
                'rule' => null,
            ];
        }

        $logText = $this->deploymentLogText($deployment);

        if ($application && $application->build_pack === BuildPackTypes::NIXPACKS->value) {
            if ($this->matchesAny($logText, [
                '/@tailwindcss\/oxide/i',
                '/tailwindcss-oxide/i',
                '/native binding/i',
                '/Cannot find module .*oxide/i',
            ])) {
                return [
                    'action' => 'remediate',
                    'classification' => 'tailwind_oxide_missing_native_binding',
                    'rule' => 'set_nixpacks_node22',
                ];
            }

            if ($this->matchesAny($logText, [
                '/NIXPACKS_NODE_VERSION not set/i',
                '/Node\.js 18/i',
                '/Unsupported engine/i',
                '/Expected version .*node/i',
            ])) {
                return [
                    'action' => 'remediate',
                    'classification' => 'node18_mismatch',
                    'rule' => 'set_nixpacks_node22',
                ];
            }
        }

        return [
            'action' => 'record',
            'classification' => 'no_known_remediation',
            'rule' => null,
        ];
    }

    /**
     * @return array{changed: bool, reason: string}
     */
    public function applyRemediation(ApplicationDeploymentQueue $deployment, string $rule): array
    {
        return match ($rule) {
            'set_nixpacks_node22' => $this->applyNixpacksNode22Remediation($deployment->application),
            default => ['changed' => false, 'reason' => 'unknown_rule'],
        };
    }

    public function shouldRetry(ApplicationDeploymentQueue $deployment): bool
    {
        $currentAttempt = $this->currentAttempt($deployment);

        return $currentAttempt < self::MAX_TOTAL_ATTEMPTS;
    }

    public function currentAttempt(ApplicationDeploymentQueue $deployment): int
    {
        return max(1, (int) ($deployment->operator_attempt ?? 1));
    }

    /**
     * @return array{deployment: ApplicationDeploymentQueue|null, reason: string}
     */
    private function queueRetry(ApplicationDeploymentQueue $deployment, string $rule): array
    {
        $application = $deployment->application;
        if (! $application) {
            return ['deployment' => null, 'reason' => 'missing_application'];
        }

        $deploymentUuid = (string) new Cuid2;
        $result = queue_application_deployment(
            application: $application,
            deployment_uuid: $deploymentUuid,
            pull_request_id: 0,
            commit: $deployment->commit,
            force_rebuild: true,
        );

        if (($result['status'] ?? null) !== 'queued') {
            return ['deployment' => null, 'reason' => (string) ($result['status'] ?? 'retry_not_queued')];
        }

        $retryDeployment = ApplicationDeploymentQueue::query()
            ->where('deployment_uuid', $deploymentUuid)
            ->first();

        if (! $retryDeployment) {
            return ['deployment' => null, 'reason' => 'retry_row_not_found'];
        }

        $retryDeployment->update([
            'operator_attempt' => $this->currentAttempt($deployment) + 1,
            'operator_root_deployment_id' => $deployment->operator_root_deployment_id ?? $deployment->id,
            'operator_rule' => $rule,
        ]);

        return ['deployment' => $retryDeployment->fresh(), 'reason' => 'queued'];
    }

    /**
     * @param  array<string, mixed>  $verification
     * @return array<string, mixed>
     */
    private function finalize(ApplicationDeploymentQueue $deployment, string $status, ?string $rule, array $verification): array
    {
        $deployment->update([
            'operator_status' => $status,
            'operator_rule' => $rule,
            'operator_verification' => $verification,
        ]);

        return $deployment->fresh()->only([
            'id',
            'operator_attempt',
            'operator_root_deployment_id',
            'operator_status',
            'operator_rule',
            'operator_verification',
        ]);
    }

    /**
     * @return array{changed: bool, reason: string}
     */
    private function applyNixpacksNode22Remediation(?Application $application): array
    {
        if (! $application || $application->build_pack !== BuildPackTypes::NIXPACKS->value) {
            return ['changed' => false, 'reason' => 'unsupported_application'];
        }

        $changed = $this->upsertNixpacksNodeVersion($application);

        return [
            'changed' => $changed,
            'reason' => $changed ? 'updated_nixpacks_node_version' : 'already_pinned_to_22',
        ];
    }

    private function upsertNixpacksNodeVersion(Application $application): bool
    {
        $changed = false;

        foreach ([false, true] as $isPreview) {
            $environmentVariable = EnvironmentVariable::query()->firstOrNew([
                'resourceable_type' => Application::class,
                'resourceable_id' => $application->id,
                'is_preview' => $isPreview,
                'key' => 'NIXPACKS_NODE_VERSION',
            ]);

            $before = [
                'exists' => $environmentVariable->exists,
                'value' => $environmentVariable->exists ? $environmentVariable->real_value : null,
                'is_buildtime' => $environmentVariable->is_buildtime,
                'is_runtime' => $environmentVariable->is_runtime,
            ];

            $environmentVariable->fill([
                'value' => '22',
                'is_multiline' => false,
                'is_literal' => false,
                'is_buildtime' => true,
                'is_runtime' => false,
            ]);

            $environmentVariable->save();

            if (
                ! $before['exists']
                || $before['value'] !== '22'
                || $before['is_buildtime'] !== true
                || $before['is_runtime'] !== false
            ) {
                $changed = true;
            }
        }

        return $changed;
    }

    private function expectedDefaultRoute(object $server, string $uuid): string
    {
        return generateUrl($server, $uuid);
    }

    private function isPreviewDeployment(ApplicationDeploymentQueue $deployment): bool
    {
        return $deployment->pull_request_id > 0;
    }

    private function previewLineageKey(ApplicationDeploymentQueue $deployment): string
    {
        return $deployment->application_id.':'.$deployment->pull_request_id;
    }

    /**
     * @return array{eligible: bool, reason: string}
     */
    private function previewDockerfileEligibility(Application $application): array
    {
        if ($application->settings->is_static) {
            return ['eligible' => false, 'reason' => 'dockerfile_static_excluded'];
        }

        if (filled($application->dockerfile)) {
            return ['eligible' => false, 'reason' => 'inline_dockerfile_excluded'];
        }

        return ['eligible' => true, 'reason' => 'eligible_preview_dockerfile'];
    }

    /**
     * @param  array<int, string>  $targets
     * @return array{result: string, pass: bool, reason: string, targets: array<int, array<string, mixed>>}
     */
    private function probePreviewVerificationTargets(array $targets): array
    {
        $allowedHosts = collect($targets)
            ->map(function (string $target): string {
                try {
                    return Url::fromString($target)->getHost();
                } catch (Throwable) {
                    return '';
                }
            })
            ->filter()
            ->unique()
            ->values()
            ->all();

        $results = collect($targets)
            ->map(fn (string $target): array => $this->probePreviewVerificationTarget($target, $allowedHosts))
            ->values()
            ->all();

        $firstFailure = collect($results)->first(fn (array $result): bool => ! ($result['pass'] ?? false));

        return [
            'result' => $firstFailure ? 'failed' : 'passed',
            'pass' => $firstFailure === null,
            'reason' => $firstFailure['reason'] ?? 'all_preview_targets_passed',
            'targets' => $results,
        ];
    }

    /**
     * @param  array<int, string>  $allowedHosts
     * @return array{target: string, result: string, http_status: int|null, pass: bool, reason: string, redirect_location?: string|null}
     */
    private function probePreviewVerificationTarget(string $target, array $allowedHosts): array
    {
        try {
            $response = Http::timeout(10)
                ->withoutRedirecting()
                ->get($target);

            $status = $response->status();

            if ($status >= 200 && $status < 300) {
                return [
                    'target' => $target,
                    'result' => 'passed',
                    'http_status' => $status,
                    'pass' => true,
                    'reason' => 'http_ok',
                ];
            }

            if ($status >= 300 && $status < 400) {
                $location = $response->header('Location');
                $redirectPasses = filled($location) && $this->previewRedirectStaysWithinTargetSet($target, $location, $allowedHosts);

                return [
                    'target' => $target,
                    'result' => $redirectPasses ? 'passed' : 'failed',
                    'http_status' => $status,
                    'pass' => $redirectPasses,
                    'reason' => $redirectPasses ? 'http_redirect' : 'redirect_host_mismatch',
                    'redirect_location' => $location,
                ];
            }

            return [
                'target' => $target,
                'result' => 'failed',
                'http_status' => $status,
                'pass' => false,
                'reason' => 'http_error',
            ];
        } catch (Throwable) {
            return [
                'target' => $target,
                'result' => 'failed',
                'http_status' => null,
                'pass' => false,
                'reason' => 'request_exception',
            ];
        }
    }

    /**
     * @param  array<int, string>  $allowedHosts
     */
    private function previewRedirectStaysWithinTargetSet(string $target, string $location, array $allowedHosts): bool
    {
        $trimmedLocation = trim($location);

        if ($trimmedLocation === '') {
            return false;
        }

        if (str($trimmedLocation)->startsWith('//')) {
            try {
                $resolvedLocation = Url::fromString($target)->getScheme().':'.$trimmedLocation;
                $redirectHost = Url::fromString($resolvedLocation)->getHost();

                return $redirectHost !== '' && in_array($redirectHost, $allowedHosts, true);
            } catch (Throwable) {
                return false;
            }
        }

        if (str($trimmedLocation)->startsWith('/')) {
            return $trimmedLocation !== '';
        }

        if (str($trimmedLocation)->startsWith(['?', '#'])) {
            return true;
        }

        try {
            $resolvedLocation = str($trimmedLocation)->startsWith('//')
                ? Url::fromString($target)->getScheme().':'.$trimmedLocation
                : $trimmedLocation;

            $redirectHost = Url::fromString((string) $resolvedLocation)->getHost();

            return $redirectHost !== '' && in_array($redirectHost, $allowedHosts, true);
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * @param  array<string, mixed>  $verification
     * @param  array<string, mixed>  $context
     * @return array<string, mixed>
     */
    private function previewMetadata(ApplicationDeploymentQueue $deployment, array $verification, array $context = []): array
    {
        return array_merge([
            'mode' => 'preview_verify_only',
            'pull_request_id' => $deployment->pull_request_id,
            'lineage_key' => $context['lineage_key'] ?? $this->previewLineageKey($deployment),
            'preview_deployment_id' => data_get($context, 'preview.id'),
        ], $verification);
    }

    /**
     * @return array{result: string, target: string|null, http_status: int|null, pass: bool, reason: string}
     */
    private function probeVerificationTarget(?string $target): array
    {
        if (blank($target)) {
            return [
                'result' => 'failed',
                'target' => null,
                'http_status' => null,
                'pass' => false,
                'reason' => 'missing_verification_target',
            ];
        }

        try {
            $response = Http::timeout(10)
                ->withoutRedirecting()
                ->get($target);

            $status = $response->status();
            $passed = $status >= 200 && $status < 400;

            return [
                'result' => $passed ? 'passed' : 'failed',
                'target' => $target,
                'http_status' => $status,
                'pass' => $passed,
                'reason' => $status >= 300 && $status < 400
                    ? 'http_redirect'
                    : ($passed ? 'http_ok' : 'http_error'),
            ];
        } catch (Throwable) {
            return [
                'result' => 'failed',
                'target' => $target,
                'http_status' => null,
                'pass' => false,
                'reason' => 'request_exception',
            ];
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function runComposePrimaryVerification(ApplicationDeploymentQueue $deployment): array
    {
        $context = $this->composeVerificationContext($deployment);

        if (! $context['eligible']) {
            return $this->finalize(
                $deployment,
                'ineligible',
                null,
                $this->composeMetadata([
                    'result' => 'skipped',
                    'reason' => $context['reason'],
                    'pass' => false,
                    'http_status' => null,
                ], $context),
            );
        }

        $latestGuard = $this->latestDeploymentGuard($deployment);
        if (! $latestGuard['is_latest']) {
            return $this->finalize(
                $deployment,
                'superseded',
                null,
                $this->composeMetadata([
                    'result' => 'skipped',
                    'reason' => $latestGuard['reason'],
                    'pass' => false,
                    'http_status' => null,
                ], $context),
            );
        }

        $verification = $this->probeComposeVerificationTarget($context['target']);

        return $this->finalize(
            $deployment,
            $verification['pass'] ? 'recorded' : 'verification_failed',
            null,
            $this->composeMetadata($verification, $context),
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function runMultiDestinationCanaryVerification(ApplicationDeploymentQueue $deployment): array
    {
        $context = $this->multiDestinationCanaryContext($deployment);

        if (! $context['eligible']) {
            return $this->finalize(
                $deployment,
                'ineligible',
                null,
                $this->multiDestinationCanaryMetadata([
                    'result' => 'skipped',
                    'reason' => $context['reason'],
                    'pass' => false,
                    'http_status' => null,
                ], $context),
            );
        }

        $latestGuard = $this->multiDestinationCanaryLatestDeploymentGuard($deployment);
        if (! $latestGuard['is_latest']) {
            return $this->finalize(
                $deployment,
                'superseded',
                null,
                $this->multiDestinationCanaryMetadata([
                    'result' => 'skipped',
                    'reason' => $latestGuard['reason'],
                    'pass' => false,
                    'http_status' => null,
                ], $context),
            );
        }

        $verification = $this->probeComposeVerificationTarget($context['target']);

        return $this->finalize(
            $deployment,
            $verification['pass'] ? 'recorded' : 'verification_failed',
            null,
            $this->multiDestinationCanaryMetadata($verification, $context),
        );
    }

    /**
     * @return array{eligible: bool, reason: string, treated_as_canary: bool, primary_destination_id: int|null, deployment_destination_id: int|null, additional_destination_count: int, target: string|null, service_name: string|null}
     */
    private function multiDestinationCanaryContext(ApplicationDeploymentQueue $deployment): array
    {
        $application = $deployment->application;

        if (! $application) {
            return [
                'eligible' => false,
                'reason' => 'missing_application',
                'treated_as_canary' => false,
                'primary_destination_id' => null,
                'deployment_destination_id' => (int) $deployment->destination_id,
                'additional_destination_count' => 0,
                'target' => null,
                'service_name' => null,
            ];
        }

        $additionalDestinationCount = $application->additional_servers()->count();
        $primaryDestinationId = (int) $application->destination_id;
        $deploymentDestinationId = (int) $deployment->destination_id;

        if ($deployment->pull_request_id !== 0) {
            return [
                'eligible' => false,
                'reason' => 'preview_deployment',
                'treated_as_canary' => false,
                'primary_destination_id' => $primaryDestinationId,
                'deployment_destination_id' => $deploymentDestinationId,
                'additional_destination_count' => $additionalDestinationCount,
                'target' => null,
                'service_name' => null,
            ];
        }

        if (! data_get($application, 'settings.is_deployment_operator_enabled', false)) {
            return [
                'eligible' => false,
                'reason' => 'operator_disabled',
                'treated_as_canary' => false,
                'primary_destination_id' => $primaryDestinationId,
                'deployment_destination_id' => $deploymentDestinationId,
                'additional_destination_count' => $additionalDestinationCount,
                'target' => null,
                'service_name' => null,
            ];
        }

        if ($additionalDestinationCount === 0) {
            return [
                'eligible' => false,
                'reason' => 'single_destination_app',
                'treated_as_canary' => false,
                'primary_destination_id' => $primaryDestinationId,
                'deployment_destination_id' => $deploymentDestinationId,
                'additional_destination_count' => 0,
                'target' => null,
                'service_name' => null,
            ];
        }

        if ($deploymentDestinationId !== $primaryDestinationId) {
            return [
                'eligible' => false,
                'reason' => 'non_primary_destination_row',
                'treated_as_canary' => false,
                'primary_destination_id' => $primaryDestinationId,
                'deployment_destination_id' => $deploymentDestinationId,
                'additional_destination_count' => $additionalDestinationCount,
                'target' => null,
                'service_name' => null,
            ];
        }

        if ((bool) $deployment->only_this_server !== true) {
            return [
                'eligible' => false,
                'reason' => 'full_rollout_row',
                'treated_as_canary' => false,
                'primary_destination_id' => $primaryDestinationId,
                'deployment_destination_id' => $deploymentDestinationId,
                'additional_destination_count' => $additionalDestinationCount,
                'target' => null,
                'service_name' => null,
            ];
        }

        $buildPackSupport = $this->singleDestinationVerifyOnlySupport($deployment);

        return [
            'eligible' => $buildPackSupport['eligible'],
            'reason' => $buildPackSupport['eligible'] ? 'eligible_multi_destination_canary_verify_only' : $buildPackSupport['reason'],
            'treated_as_canary' => true,
            'primary_destination_id' => $primaryDestinationId,
            'deployment_destination_id' => $deploymentDestinationId,
            'additional_destination_count' => $additionalDestinationCount,
            'target' => $buildPackSupport['target'],
            'service_name' => $buildPackSupport['service_name'],
        ];
    }

    /**
     * @return array{is_latest: bool, reason: string}
     */
    private function multiDestinationCanaryLatestDeploymentGuard(ApplicationDeploymentQueue $deployment): array
    {
        $primaryDestinationId = (int) $deployment->application?->destination_id;

        $newerPrimaryDeploymentExists = ApplicationDeploymentQueue::query()
            ->where('application_id', $deployment->application_id)
            ->where('pull_request_id', 0)
            ->where('destination_id', $primaryDestinationId)
            ->where('id', '>', $deployment->id)
            ->exists();

        if ($newerPrimaryDeploymentExists) {
            return ['is_latest' => false, 'reason' => 'newer_primary_deployment_exists'];
        }

        return ['is_latest' => true, 'reason' => 'latest'];
    }

    /**
     * @return array{eligible: bool, reason: string, service_name: string|null, target: string|null}
     */
    private function composeVerificationContext(ApplicationDeploymentQueue $deployment): array
    {
        $application = $deployment->application;

        if (! $application) {
            return [
                'eligible' => false,
                'reason' => 'missing_application',
                'service_name' => null,
                'target' => null,
            ];
        }

        if ($deployment->pull_request_id !== 0) {
            return [
                'eligible' => false,
                'reason' => 'preview_deployment',
                'service_name' => null,
                'target' => null,
            ];
        }

        if ((int) $application->compose_parsing_version < 3) {
            return [
                'eligible' => false,
                'reason' => 'raw_compose_mode',
                'service_name' => null,
                'target' => null,
            ];
        }

        if (filled($application->docker_compose_custom_build_command) || filled($application->docker_compose_custom_start_command)) {
            return [
                'eligible' => false,
                'reason' => 'custom_compose_commands_present',
                'service_name' => null,
                'target' => null,
            ];
        }

        if ((bool) data_get($application, 'settings.connect_to_docker_network', data_get($application, 'connect_to_docker_network', false))) {
            return [
                'eligible' => false,
                'reason' => 'connect_to_docker_network_enabled',
                'service_name' => null,
                'target' => null,
            ];
        }

        $services = $this->parsedComposeServices($application);
        if ($services === null) {
            return [
                'eligible' => false,
                'reason' => 'compose_parse_failed',
                'service_name' => null,
                'target' => null,
            ];
        }

        $dockerComposeDomains = $this->decodedComposeDomains($application);
        $routedServices = [];

        foreach ($services as $serviceName => $service) {
            if ($this->isComposeDatabaseService($service)) {
                continue;
            }

            $normalizedServiceName = $this->normalizeComposeServiceName((string) $serviceName);
            $configuredDomain = trim((string) data_get($dockerComposeDomains, $normalizedServiceName.'.domain', ''));

            if ($configuredDomain === '') {
                continue;
            }

            $targets = collect(explode(',', $configuredDomain))
                ->map(fn (string $target): string => trim($target))
                ->filter()
                ->unique()
                ->values();

            if ($targets->count() !== 1) {
                return [
                    'eligible' => false,
                    'reason' => 'multiple_domains_per_routed_service',
                    'service_name' => (string) $serviceName,
                    'target' => null,
                ];
            }

            $targetValidation = $this->validateComposeVerificationTarget((string) $targets->first());
            if (! $targetValidation['valid']) {
                return [
                    'eligible' => false,
                    'reason' => $targetValidation['reason'],
                    'service_name' => (string) $serviceName,
                    'target' => null,
                ];
            }

            $routedServices[] = [
                'service_name' => (string) $serviceName,
                'target' => $targetValidation['target'],
            ];
        }

        if (count($routedServices) === 0) {
            return [
                'eligible' => false,
                'reason' => 'no_resolvable_public_service',
                'service_name' => null,
                'target' => null,
            ];
        }

        if (count($routedServices) > 1) {
            return [
                'eligible' => false,
                'reason' => 'multiple_public_routed_services',
                'service_name' => null,
                'target' => null,
            ];
        }

        return [
            'eligible' => true,
            'reason' => 'eligible_compose_verify_only',
            'service_name' => $routedServices[0]['service_name'],
            'target' => $routedServices[0]['target'],
        ];
    }

    /**
     * @return array{result: string, target: string|null, http_status: int|null, pass: bool, reason: string, redirect_location?: string|null}
     */
    private function probeComposeVerificationTarget(?string $target): array
    {
        if (blank($target)) {
            return [
                'result' => 'failed',
                'target' => null,
                'http_status' => null,
                'pass' => false,
                'reason' => 'missing_verification_target',
            ];
        }

        try {
            $response = Http::timeout(10)
                ->withoutRedirecting()
                ->get($target);

            $status = $response->status();

            if ($status >= 200 && $status < 300) {
                return [
                    'result' => 'passed',
                    'target' => $target,
                    'http_status' => $status,
                    'pass' => true,
                    'reason' => 'http_ok',
                ];
            }

            if ($status >= 300 && $status < 400) {
                $location = $response->header('Location');
                $allowedHost = Url::fromString($target)->getHost();
                $redirectPasses = filled($location) && $this->previewRedirectStaysWithinTargetSet($target, $location, [$allowedHost]);

                return [
                    'result' => $redirectPasses ? 'passed' : 'failed',
                    'target' => $target,
                    'http_status' => $status,
                    'pass' => $redirectPasses,
                    'reason' => $redirectPasses ? 'http_redirect' : 'redirect_host_mismatch',
                    'redirect_location' => $location,
                ];
            }

            return [
                'result' => 'failed',
                'target' => $target,
                'http_status' => $status,
                'pass' => false,
                'reason' => 'http_error',
            ];
        } catch (Throwable) {
            return [
                'result' => 'failed',
                'target' => $target,
                'http_status' => null,
                'pass' => false,
                'reason' => 'request_exception',
            ];
        }
    }

    /**
     * @param  array<string, mixed>  $verification
     * @param  array{service_name: string|null, target: string|null}  $context
     * @return array<string, mixed>
     */
    private function composeMetadata(array $verification, array $context): array
    {
        return array_merge([
            'mode' => 'compose_verify_only',
            'selected_service_name' => $context['service_name'],
            'target' => $context['target'],
        ], $verification);
    }

    /**
     * @param  array<string, mixed>  $verification
     * @return array<string, mixed>
     */
    private function multiDestinationCanaryMetadata(array $verification, array $context): array
    {
        return array_merge([
            'mode' => 'multi_destination_canary_verify_only',
            'treated_as_canary' => $context['treated_as_canary'],
            'primary_destination_id' => $context['primary_destination_id'],
            'deployment_destination_id' => $context['deployment_destination_id'],
            'additional_destination_count' => $context['additional_destination_count'],
            'selected_service_name' => $context['service_name'],
            'target' => $context['target'],
        ], $verification);
    }

    /**
     * @return array{eligible: bool, reason: string, target: string|null, service_name: string|null}
     */
    private function singleDestinationVerifyOnlySupport(ApplicationDeploymentQueue $deployment): array
    {
        $application = $deployment->application;

        if (! $application) {
            return [
                'eligible' => false,
                'reason' => 'missing_application',
                'target' => null,
                'service_name' => null,
            ];
        }

        if ($application->build_pack === BuildPackTypes::DOCKERCOMPOSE->value) {
            $context = $this->composeVerificationContext($deployment);

            return [
                'eligible' => $context['eligible'],
                'reason' => $context['reason'],
                'target' => $context['target'],
                'service_name' => $context['service_name'],
            ];
        }

        if ($application->build_pack === BuildPackTypes::NIXPACKS->value) {
            if ($this->deriveVerificationTarget($deployment) === null) {
                return ['eligible' => false, 'reason' => 'default_generated_url_only_required', 'target' => null, 'service_name' => null];
            }

            if ($application->settings->is_static && blank($application->publish_directory)) {
                return ['eligible' => false, 'reason' => 'missing_publish_directory', 'target' => null, 'service_name' => null];
            }

            return ['eligible' => true, 'reason' => 'eligible_nixpacks', 'target' => $this->deriveVerificationTarget($deployment), 'service_name' => null];
        }

        if ($application->build_pack === BuildPackTypes::DOCKERFILE->value) {
            if ($this->deriveVerificationTarget($deployment) === null) {
                return ['eligible' => false, 'reason' => 'default_generated_url_only_required', 'target' => null, 'service_name' => null];
            }

            if ($application->settings->is_static) {
                return ['eligible' => false, 'reason' => 'dockerfile_static_excluded', 'target' => null, 'service_name' => null];
            }

            if (filled($application->dockerfile)) {
                return ['eligible' => false, 'reason' => 'inline_dockerfile_excluded', 'target' => null, 'service_name' => null];
            }

            return ['eligible' => true, 'reason' => 'eligible_dockerfile', 'target' => $this->deriveVerificationTarget($deployment), 'service_name' => null];
        }

        if (in_array($application->build_pack, [BuildPackTypes::STATIC->value, BuildPackTypes::RAILPACK->value], true)) {
            if ($this->deriveVerificationTarget($deployment) === null) {
                return ['eligible' => false, 'reason' => 'default_generated_url_only_required', 'target' => null, 'service_name' => null];
            }

            return [
                'eligible' => true,
                'reason' => $application->build_pack === BuildPackTypes::RAILPACK->value
                    ? 'eligible_railpack_verify_only'
                    : 'eligible_static_verify_only',
                'target' => $this->deriveVerificationTarget($deployment),
                'service_name' => null,
            ];
        }

        return [
            'eligible' => false,
            'reason' => 'unsupported_build_pack',
            'target' => null,
            'service_name' => null,
        ];
    }

    private function parsedComposeServices(Application $application): ?array
    {
        $compose = $application->docker_compose_raw;
        if (blank($compose)) {
            return null;
        }

        try {
            $parsed = Yaml::parse((string) $compose);
        } catch (Throwable) {
            return null;
        }

        $services = data_get($parsed, 'services');

        return is_array($services) ? $services : null;
    }

    /**
     * @return array<string, mixed>
     */
    private function decodedComposeDomains(Application $application): array
    {
        $decoded = json_decode((string) $application->docker_compose_domains, true);

        return is_array($decoded) ? $decoded : [];
    }

    /**
     * @param  array<string, mixed>  $service
     */
    private function isComposeDatabaseService(array $service): bool
    {
        return (bool) data_get($service, 'is_database', isDatabaseImage(data_get($service, 'image'), $service));
    }

    private function normalizeComposeServiceName(string $serviceName): string
    {
        return (string) str($serviceName)->replace('-', '_')->replace('.', '_');
    }

    /**
     * @return array{valid: bool, reason: string, target: string|null}
     */
    private function validateComposeVerificationTarget(string $target): array
    {
        $trimmedTarget = trim($target);
        if ($trimmedTarget === '') {
            return [
                'valid' => false,
                'reason' => 'invalid_compose_target',
                'target' => null,
            ];
        }

        try {
            $url = Url::fromString($trimmedTarget);
        } catch (Throwable) {
            return [
                'valid' => false,
                'reason' => 'invalid_compose_target',
                'target' => null,
            ];
        }

        $scheme = $url->getScheme();
        $host = $url->getHost();
        $parsed = parse_url($trimmedTarget);
        $path = is_array($parsed) ? (string) ($parsed['path'] ?? '') : '';
        $query = is_array($parsed) ? (string) ($parsed['query'] ?? '') : '';
        $fragment = is_array($parsed) ? (string) ($parsed['fragment'] ?? '') : '';
        $user = is_array($parsed) ? (string) ($parsed['user'] ?? '') : '';
        $pass = is_array($parsed) ? (string) ($parsed['pass'] ?? '') : '';
        $port = is_array($parsed) ? ($parsed['port'] ?? null) : null;

        if (! in_array($scheme, ['http', 'https'], true) || $host === '') {
            return [
                'valid' => false,
                'reason' => 'compose_target_must_be_plain_absolute_host_url',
                'target' => null,
            ];
        }

        if ($port !== null || ! in_array($path, ['', '/'], true) || $query !== '' || $fragment !== '' || $user !== '' || $pass !== '') {
            return [
                'valid' => false,
                'reason' => 'compose_target_must_be_plain_absolute_host_url',
                'target' => null,
            ];
        }

        return [
            'valid' => true,
            'reason' => 'valid',
            'target' => $url->withPort(null)->withPath('')->__toString(),
        ];
    }

    private function normalizeRouteUrl(string $url): string
    {
        $parsedUrl = Url::fromString($url);
        $path = $parsedUrl->getPath();

        return $parsedUrl
            ->withPort(null)
            ->withPath($path === '/' ? '' : rtrim($path, '/'))
            ->__toString();
    }

    /**
     * @return array{summary: array<string, int>, domains: array<int, array<string, mixed>>}
     */
    private function observeCustomDomains(ApplicationDeploymentQueue $deployment, string $defaultTarget): array
    {
        $application = $deployment->application;
        $server = $deployment->server;

        if (! $application || ! $server) {
            return [
                'summary' => ['total' => 0, 'passed' => 0, 'pending' => 0, 'failed' => 0, 'internal_reconcile_attempted' => 0, 'internal_reconcile_succeeded' => 0],
                'domains' => [],
            ];
        }

        $domains = collect($application->fqdns)
            ->map(fn (string $fqdn): string => $this->normalizeRouteUrl(trim($fqdn)))
            ->filter()
            ->reject(fn (string $fqdn): bool => $fqdn === $this->normalizeRouteUrl($defaultTarget))
            ->unique()
            ->values();

        $reconcileContext = $this->customDomainInternalReconcileContext($deployment, $domains);

        $results = $domains
            ->map(fn (string $domain): array => $this->observeCustomDomain($deployment, $domain, $server, $reconcileContext))
            ->all();

        return [
            'summary' => [
                'total' => count($results),
                'passed' => count(array_filter($results, fn (array $result): bool => $result['result'] === 'passed')),
                'pending' => count(array_filter($results, fn (array $result): bool => $result['result'] === 'pending')),
                'failed' => count(array_filter($results, fn (array $result): bool => $result['result'] === 'failed')),
                'internal_reconcile_attempted' => count(array_filter($results, fn (array $result): bool => (bool) data_get($result, 'internal_reconcile.attempted', false))),
                'internal_reconcile_succeeded' => count(array_filter($results, fn (array $result): bool => (bool) data_get($result, 'internal_reconcile.succeeded', false))),
            ],
            'domains' => $results,
        ];
    }

    /**
     * @return array{domain: string, result: string, http_status: int|null, reason: string}
     */
    private function observeCustomDomain(ApplicationDeploymentQueue $deployment, string $domain, object $server, array $reconcileContext): array
    {
        $preResult = $this->probeCustomDomainOnce($domain, $server);
        $preResult['attempts'] = 1;
        $decision = $this->customDomainInternalReconcileDecision($reconcileContext, $domain, $preResult);

        if ($decision['attempt']) {
            $reconcile = $this->performInternalCustomDomainReconcile($deployment);
            $postResult = $this->probeCustomDomainOnce($domain, $server);
            $postResult['attempts'] = 2;

            return $this->withCustomDomainReconcileMetadata($postResult, $reconcile, $preResult, $postResult);
        }

        $result = $this->completeCustomDomainObservation($domain, $server, $preResult);

        return $this->withCustomDomainReconcileMetadata($result, [
            'attempted' => false,
            'actions' => [],
            'reason' => $decision['reason'],
        ], $preResult);
    }

    /**
     * @return array{domain: string, result: string, http_status: int|null, reason: string}
     */
    private function completeCustomDomainObservation(string $domain, object $server, array $firstResult): array
    {
        $lastResult = $firstResult;

        if (! $this->shouldRetryCustomDomainResult($firstResult)) {
            return $firstResult;
        }

        for ($attempt = 2; $attempt <= self::CUSTOM_DOMAIN_MAX_ATTEMPTS; $attempt++) {
            $result = $this->probeCustomDomainOnce($domain, $server);
            $result['attempts'] = $attempt;
            $lastResult = $result;

            if (! $this->shouldRetryCustomDomainResult($result)) {
                return $result;
            }
        }

        return $lastResult;
    }

    /**
     * @return array{domain: string, result: string, http_status: int|null, reason: string}
     */
    private function probeCustomDomainOnce(string $domain, object $server): array
    {
        if (! validateDNSEntry($domain, $server)) {
            return [
                'domain' => $domain,
                'result' => 'pending',
                'http_status' => null,
                'reason' => 'dns_not_propagated',
            ];
        }

        try {
            $response = Http::timeout(10)
                ->withoutRedirecting()
                ->get($domain);

            $status = $response->status();
            $expectedHost = Url::fromString($domain)->getHost();

            if ($status >= 200 && $status < 300) {
                return [
                    'domain' => $domain,
                    'result' => 'passed',
                    'http_status' => $status,
                    'reason' => 'http_ok',
                ];
            }

            if ($status >= 300 && $status < 400) {
                $location = $response->header('Location');
                if (filled($location)) {
                    $redirectHost = Url::fromString($location)->getHost();
                    if ($redirectHost !== '' && $redirectHost !== $expectedHost) {
                        return [
                            'domain' => $domain,
                            'result' => 'failed',
                            'http_status' => $status,
                            'reason' => 'redirect_host_mismatch',
                        ];
                    }
                }

                return [
                    'domain' => $domain,
                    'result' => 'passed',
                    'http_status' => $status,
                    'reason' => 'http_redirect',
                ];
            }

            if (in_array($status, [502, 503, 504, 522, 523, 524, 525, 526], true)) {
                return [
                    'domain' => $domain,
                    'result' => 'pending',
                    'http_status' => $status,
                    'reason' => 'proxy_warmup',
                ];
            }

            return [
                'domain' => $domain,
                'result' => 'failed',
                'http_status' => $status,
                'reason' => 'http_error',
            ];
        } catch (Throwable) {
            return [
                'domain' => $domain,
                'result' => 'pending',
                'http_status' => null,
                'reason' => 'request_exception',
            ];
        }
    }

    /**
     * @param  array<string, mixed>  $result
     */
    private function shouldRetryCustomDomainResult(array $result): bool
    {
        return ($result['result'] ?? null) === 'pending'
            && in_array($result['reason'] ?? null, ['request_exception', 'proxy_warmup', 'dns_not_propagated'], true);
    }

    /**
     * @param  Collection<int, string>  $domains
     * @return array{eligible: bool, reason: string, domain: string|null}
     */
    private function customDomainInternalReconcileContext(ApplicationDeploymentQueue $deployment, Collection $domains): array
    {
        $application = $deployment->application;

        if (! $application || ! $deployment->server) {
            return ['eligible' => false, 'reason' => 'missing_application_or_server', 'domain' => null];
        }

        if ($deployment->pull_request_id !== 0) {
            return ['eligible' => false, 'reason' => 'preview_deployment', 'domain' => null];
        }

        if ($deployment->only_this_server || $application->additional_servers()->count() > 0) {
            return ['eligible' => false, 'reason' => 'multi_destination_complexity', 'domain' => null];
        }

        if ((bool) data_get($application, 'settings.connect_to_docker_network', data_get($application, 'connect_to_docker_network', false))) {
            return ['eligible' => false, 'reason' => 'connect_to_docker_network_enabled', 'domain' => null];
        }

        $support = $this->singleDestinationVerifyOnlySupport($deployment);
        if (! $support['eligible']) {
            return ['eligible' => false, 'reason' => $support['reason'], 'domain' => null];
        }

        if ($domains->count() !== 1) {
            return ['eligible' => false, 'reason' => 'custom_domain_bundle_ambiguous', 'domain' => null];
        }

        $validatedDomain = $this->validateOwnedCustomDomainTarget((string) $domains->first());
        if (! $validatedDomain['valid']) {
            return ['eligible' => false, 'reason' => $validatedDomain['reason'], 'domain' => null];
        }

        if (! $this->hasCoolifyManagedCustomDomainRouting($application)) {
            return ['eligible' => false, 'reason' => 'application_routing_not_coolify_managed', 'domain' => null];
        }

        return ['eligible' => true, 'reason' => 'eligible_internal_proxy_reconcile', 'domain' => $validatedDomain['target']];
    }

    /**
     * @return array{attempt: bool, reason: string}
     */
    private function customDomainInternalReconcileDecision(array $context, string $domain, array $result): array
    {
        if (! ($context['eligible'] ?? false)) {
            return ['attempt' => false, 'reason' => (string) ($context['reason'] ?? 'custom_domain_reconcile_not_eligible')];
        }

        if (($context['domain'] ?? null) !== $domain) {
            return ['attempt' => false, 'reason' => 'domain_not_selected'];
        }

        if (($result['result'] ?? null) === 'passed') {
            return ['attempt' => false, 'reason' => 'custom_domain_passed'];
        }

        if (($result['reason'] ?? null) === 'dns_not_propagated') {
            return ['attempt' => false, 'reason' => 'dns_validation_not_ready'];
        }

        $status = $result['http_status'] ?? null;
        if (($result['reason'] ?? null) === 'proxy_warmup' && in_array($status, self::INTERNAL_CUSTOM_DOMAIN_RECONCILE_HTTP_STATUSES, true)) {
            return ['attempt' => true, 'reason' => 'owned_internal_proxy_drift'];
        }

        if (($result['reason'] ?? null) === 'proxy_warmup' && in_array($status, self::PROVIDER_EDGE_CUSTOM_DOMAIN_HTTP_STATUSES, true)) {
            return ['attempt' => false, 'reason' => 'provider_edge_failure'];
        }

        return ['attempt' => false, 'reason' => 'failure_signature_not_safe'];
    }

    /**
     * @return array{valid: bool, reason: string, target: string|null}
     */
    private function validateOwnedCustomDomainTarget(string $target): array
    {
        $validatedTarget = $this->validateComposeVerificationTarget($target);
        if (! $validatedTarget['valid']) {
            return $validatedTarget;
        }

        $host = Url::fromString((string) $validatedTarget['target'])->getHost();
        if (str($host)->startsWith('*.')) {
            return [
                'valid' => false,
                'reason' => 'wildcard_custom_domain_not_supported',
                'target' => null,
            ];
        }

        return $validatedTarget;
    }

    private function hasCoolifyManagedCustomDomainRouting(Application $application): bool
    {
        $existingLabels = $this->normalizedApplicationCustomLabels($application);
        if ($existingLabels->isEmpty()) {
            return true;
        }

        return $existingLabels->values()->all() === $this->generatedApplicationCustomLabels($application)->values()->all();
    }

    /**
     * @return Collection<int, string>
     */
    private function normalizedApplicationCustomLabels(Application $application): Collection
    {
        $labels = (string) ($application->custom_labels ?? '');
        if ($labels === '') {
            return collect();
        }

        $decoded = base64_decode($labels, true);
        if ($decoded !== false && base64_encode($decoded) === $labels) {
            $labels = $decoded;
        }

        return collect(preg_split("/\r\n|\n|\r/", $labels) ?: [])
            ->map(fn ($label): string => trim((string) $label))
            ->filter()
            ->sort()
            ->values();
    }

    /**
     * @return Collection<int, string>
     */
    private function generatedApplicationCustomLabels(Application $application): Collection
    {
        return collect(generateLabelsApplication($application))
            ->map(fn ($label): string => trim((string) $label))
            ->filter()
            ->sort()
            ->values();
    }

    /**
     * @return array{attempted: bool, actions: array<int, string>, reason: string, error?: string|null}
     */
    protected function performInternalCustomDomainReconcile(ApplicationDeploymentQueue $deployment): array
    {
        $server = $deployment->server;
        if (! $server) {
            return [
                'attempted' => false,
                'actions' => [],
                'reason' => 'missing_server',
            ];
        }

        $actions = [];

        try {
            $commands = [];

            $ensureNetworkCommands = ensureProxyNetworksExist($server);
            if ($ensureNetworkCommands->isNotEmpty()) {
                $commands = array_merge($commands, $ensureNetworkCommands->toArray());
                $actions[] = 'ensure_proxy_networks_exist';
            }

            $connectNetworkCommands = connectProxyToNetworks($server);
            if ($connectNetworkCommands->isNotEmpty()) {
                $commands = array_merge($commands, $connectNetworkCommands->toArray());
                $actions[] = 'connect_proxy_to_networks';
            }

            if ($commands !== []) {
                instant_remote_process($commands, $server, false);
            }

            $server->setupDynamicProxyConfiguration();
            $actions[] = 'regenerate_proxy_dynamic_config';

            if ($server->proxyType() === 'CADDY') {
                $actions[] = 'hot_reload_proxy';
            }

            return [
                'attempted' => true,
                'actions' => array_values(array_unique($actions)),
                'reason' => 'internal_proxy_reconciled',
            ];
        } catch (Throwable $exception) {
            return [
                'attempted' => true,
                'actions' => array_values(array_unique($actions)),
                'reason' => 'internal_proxy_reconcile_error',
                'error' => $exception->getMessage(),
            ];
        }
    }

    /**
     * @param  array{attempted: bool, actions: array<int, string>, reason: string, error?: string|null}  $reconcile
     * @return array<string, mixed>
     */
    private function withCustomDomainReconcileMetadata(array $result, array $reconcile, array $preResult, ?array $postResult = null): array
    {
        $result['internal_reconcile'] = [
            'attempted' => (bool) ($reconcile['attempted'] ?? false),
            'actions' => array_values(array_unique($reconcile['actions'] ?? [])),
            'reason' => $reconcile['reason'] ?? 'not_attempted',
            'pre_result' => $this->customDomainResultSnapshot($preResult),
            'post_result' => $postResult ? $this->customDomainResultSnapshot($postResult) : null,
            'succeeded' => (bool) ($reconcile['attempted'] ?? false) && (($postResult['result'] ?? null) === 'passed'),
        ];

        if (array_key_exists('error', $reconcile)) {
            $result['internal_reconcile']['error'] = $reconcile['error'];
        }

        return $result;
    }

    /**
     * @return array{result: string|null, http_status: int|null, reason: string|null}
     */
    private function customDomainResultSnapshot(array $result): array
    {
        return [
            'result' => $result['result'] ?? null,
            'http_status' => $result['http_status'] ?? null,
            'reason' => $result['reason'] ?? null,
        ];
    }

    private function deploymentLogText(ApplicationDeploymentQueue $deployment): string
    {
        if (blank($deployment->logs)) {
            return '';
        }

        $decoded = json_decode((string) $deployment->logs, true);
        if (! is_array($decoded)) {
            return (string) $deployment->logs;
        }

        return collect($decoded)
            ->pluck('output')
            ->filter(fn ($output): bool => is_string($output) && $output !== '')
            ->implode("\n");
    }

    /**
     * @param  array<int, string>  $patterns
     */
    private function matchesAny(string $value, array $patterns): bool
    {
        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $value) === 1) {
                return true;
            }
        }

        return false;
    }
}
