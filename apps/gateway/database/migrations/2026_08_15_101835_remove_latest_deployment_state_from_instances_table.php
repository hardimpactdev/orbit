<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        $hasStatus = Schema::hasColumn('instances', 'latest_deployment_status');
        $hasRunId = Schema::hasColumn('instances', 'latest_deployment_run_id');

        if (! $hasStatus && ! $hasRunId) {
            return;
        }

        if (! $hasStatus || ! $hasRunId) {
            throw new RuntimeException('Instance deployment shadow columns must be removed together.');
        }

        DB::table('instances')
            ->select(['id', 'latest_deployment_status', 'latest_deployment_run_id'])
            ->orderBy('id')
            ->chunkById(500, static function (Collection $instances): void {
                $instanceIds = $instances->pluck('id');
                $latestRuns = DB::table('deployment_runs')
                    ->select(['id', 'instance_id', 'status'])
                    ->whereIn('instance_id', $instanceIds)
                    ->orderByDesc('id')
                    ->get()
                    ->unique('instance_id')
                    ->keyBy('instance_id');

                foreach ($instances as $instance) {
                    /** @var object{id: int, latest_deployment_status: ?string, latest_deployment_run_id: ?int} $instance */
                    $latestRun = $latestRuns->get($instance->id);
                    /** @var object{id: int, instance_id: int, status: string}|null $latestRun */

                    if (
                        $instance->latest_deployment_run_id === $latestRun?->id
                        && $instance->latest_deployment_status === $latestRun?->status
                    ) {
                        continue;
                    }

                    throw new RuntimeException(
                        "Instance {$instance->id} deployment shadow state does not match its latest deployment run.",
                    );
                }
            });

        Schema::table('instances', static function (Blueprint $table): void {
            $table->dropColumn(['latest_deployment_status', 'latest_deployment_run_id']);
        });
    }
};
