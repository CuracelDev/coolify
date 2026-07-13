<?php

namespace App\Jobs;

use App\Models\ApplicationDeploymentQueue;
use App\Services\ApplicationDeploymentOperatorService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Throwable;

class ApplicationDeploymentVerificationJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $tries = 1;

    public $timeout = 120;

    public function __construct(public int $application_deployment_queue_id) {}

    public function handle(ApplicationDeploymentOperatorService $operator): void
    {
        $deployment = ApplicationDeploymentQueue::query()->find($this->application_deployment_queue_id);
        if (! $deployment) {
            return;
        }

        $operator->runVerification($deployment);
    }

    public function failed(?Throwable $exception): void
    {
        $deployment = ApplicationDeploymentQueue::query()->find($this->application_deployment_queue_id);
        if (! $deployment) {
            return;
        }

        $deployment->update([
            'operator_status' => 'verification_error',
            'operator_verification' => [
                'result' => 'error',
                'message' => $exception?->getMessage(),
            ],
        ]);
    }
}
