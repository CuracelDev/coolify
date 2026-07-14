<?php

namespace App\Services;

use App\Enums\ApplicationDeploymentStatus;
use App\Models\ApplicationDeploymentQueue;

class RepositoryDeploymentResultWaiter
{
    /**
     * @var array<int, string>
     */
    private const TERMINAL_OPERATOR_STATUSES = [
        'recorded',
        'verification_failed',
        'retry_queued',
        'no_retry',
        'max_attempts_reached',
        'ineligible',
        'superseded',
    ];

    public function wait(ApplicationDeploymentQueue $deployment, int $timeoutSeconds = 45, int $pollMilliseconds = 250): ApplicationDeploymentQueue
    {
        $deadline = microtime(true) + $timeoutSeconds;
        $current = $deployment->fresh(['application.settings']);

        while ($current && microtime(true) < $deadline) {
            if ($this->isTerminal($current)) {
                return $current;
            }

            usleep($pollMilliseconds * 1000);
            $current = $current->fresh(['application.settings']);
        }

        return $current ?? $deployment->fresh(['application.settings']);
    }

    private function isTerminal(ApplicationDeploymentQueue $deployment): bool
    {
        if (in_array($deployment->status, [ApplicationDeploymentStatus::FAILED->value, ApplicationDeploymentStatus::CANCELLED_BY_USER->value], true)) {
            return true;
        }

        if ($deployment->status !== ApplicationDeploymentStatus::FINISHED->value) {
            return false;
        }

        $operatorEnabled = (bool) data_get($deployment, 'application.settings.is_deployment_operator_enabled', false);
        if (! $operatorEnabled) {
            return true;
        }

        return in_array((string) $deployment->operator_status, self::TERMINAL_OPERATOR_STATUSES, true);
    }
}
