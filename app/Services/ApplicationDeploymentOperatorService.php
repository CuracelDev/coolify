<?php

namespace App\Services;

use App\Enums\ApplicationDeploymentStatus;
use App\Enums\BuildPackTypes;
use App\Models\Application;
use App\Models\ApplicationDeploymentQueue;
use App\Models\EnvironmentVariable;
use Illuminate\Support\Facades\Http;
use Spatie\Url\Url;
use Throwable;
use Visus\Cuid2\Cuid2;

class ApplicationDeploymentOperatorService
{
    private const MAX_TOTAL_ATTEMPTS = 2;

    private const CUSTOM_DOMAIN_MAX_ATTEMPTS = 3;

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

        if ($deployment->only_this_server) {
            return ['eligible' => false, 'reason' => 'non_primary_rollout'];
        }

        if ((int) $deployment->destination_id !== (int) $application->destination_id) {
            return ['eligible' => false, 'reason' => 'non_primary_destination'];
        }

        if ($application->additional_servers()->count() > 0) {
            return ['eligible' => false, 'reason' => 'additional_destinations_present'];
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

        return ['eligible' => false, 'reason' => 'unsupported_build_pack'];
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
     * @return array{action: string, classification: string, rule: string|null}
     */
    public function classifyDeployment(ApplicationDeploymentQueue $deployment): array
    {
        $application = $deployment->application;

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
                'summary' => ['total' => 0, 'passed' => 0, 'pending' => 0, 'failed' => 0],
                'domains' => [],
            ];
        }

        $domains = collect($application->fqdns)
            ->map(fn (string $fqdn): string => $this->normalizeRouteUrl(trim($fqdn)))
            ->filter()
            ->reject(fn (string $fqdn): bool => $fqdn === $this->normalizeRouteUrl($defaultTarget))
            ->unique()
            ->values();

        $results = $domains
            ->map(fn (string $domain): array => $this->probeCustomDomain($domain, $server))
            ->all();

        return [
            'summary' => [
                'total' => count($results),
                'passed' => count(array_filter($results, fn (array $result): bool => $result['result'] === 'passed')),
                'pending' => count(array_filter($results, fn (array $result): bool => $result['result'] === 'pending')),
                'failed' => count(array_filter($results, fn (array $result): bool => $result['result'] === 'failed')),
            ],
            'domains' => $results,
        ];
    }

    /**
     * @return array{domain: string, result: string, http_status: int|null, reason: string}
     */
    private function probeCustomDomain(string $domain, object $server): array
    {
        $lastResult = null;

        for ($attempt = 1; $attempt <= self::CUSTOM_DOMAIN_MAX_ATTEMPTS; $attempt++) {
            $result = $this->probeCustomDomainOnce($domain, $server);
            $result['attempts'] = $attempt;
            $lastResult = $result;

            if (! $this->shouldRetryCustomDomainResult($result)) {
                return $result;
            }
        }

        return $lastResult ?? [
            'domain' => $domain,
            'result' => 'pending',
            'http_status' => null,
            'reason' => 'request_exception',
            'attempts' => self::CUSTOM_DOMAIN_MAX_ATTEMPTS,
        ];
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
