<?php

declare(strict_types=1);

namespace App\Commands\Internal;

use App\Services\Apps\LocalAppWorkerReadinessProbe;
use App\Services\Apps\LocalAppWorkerReadinessProbeFailure;

final class AppWorkerReadinessProbeCommand extends InternalExecutorCommand
{
    #[\Override]
    protected $signature = 'internal:app-worker-readiness:probe {path} {workerFile} {--operation-token=} {--json}';

    #[\Override]
    protected $description = 'Probe app worker-mode readiness on the local node';

    public function handle(LocalAppWorkerReadinessProbe $probe): int
    {
        if (! $this->verifyOperationToken('internal:app-worker-readiness:probe')) {
            return self::FAILURE;
        }

        try {
            $result = $probe->probe($this->argument('path'), $this->argument('workerFile'));
        } catch (LocalAppWorkerReadinessProbeFailure $failure) {
            return $this->renderFailure($failure->errorCode, $failure->getMessage(), $failure->meta);
        }

        return $this->emitInternalSuccess($result['data'], $result['meta']);
    }
}
