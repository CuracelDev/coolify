<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('application_settings', 'is_deployment_operator_enabled')) {
            Schema::table('application_settings', function (Blueprint $table) {
                $table->boolean('is_deployment_operator_enabled')->default(false)->after('is_static');
            });
        }

        if (! Schema::hasColumn('application_settings', 'deployment_operator_max_attempts')) {
            Schema::table('application_settings', function (Blueprint $table) {
                $table->unsignedInteger('deployment_operator_max_attempts')->default(2)->after('is_deployment_operator_enabled');
            });
        }

        if (! Schema::hasColumn('application_deployment_queues', 'operator_attempt')) {
            Schema::table('application_deployment_queues', function (Blueprint $table) {
                $table->unsignedInteger('operator_attempt')->nullable()->after('horizon_job_worker');
                $table->unsignedBigInteger('operator_root_deployment_id')->nullable()->after('operator_attempt');
                $table->string('operator_status')->nullable()->after('operator_root_deployment_id');
                $table->string('operator_rule')->nullable()->after('operator_status');
                $table->json('operator_verification')->nullable()->after('operator_rule');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('application_deployment_queues', 'operator_verification')) {
            Schema::table('application_deployment_queues', function (Blueprint $table) {
                $table->dropColumn([
                    'operator_attempt',
                    'operator_root_deployment_id',
                    'operator_status',
                    'operator_rule',
                    'operator_verification',
                ]);
            });
        }

        if (Schema::hasColumn('application_settings', 'deployment_operator_max_attempts')) {
            Schema::table('application_settings', function (Blueprint $table) {
                $table->dropColumn(['is_deployment_operator_enabled', 'deployment_operator_max_attempts']);
            });
        }
    }
};
