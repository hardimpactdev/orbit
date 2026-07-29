<?php

declare(strict_types=1);

namespace App\Commands\Internal;

use App\Services\Apps\LocalAppSourcePathProbe;
use App\Services\Apps\LocalAppSourcePathProbeFailure;

final class AppSourcePathProbeCommand extends InternalExecutorCommand
{
    #[\Override]
    protected $signature = 'internal:app-source-path:probe {path} {--boundary=} {--operation-token=} {--json}';

    #[\Override]
    protected $description = 'Probe whether an app source path exists locally';

    public function handle(LocalAppSourcePathProbe $paths): int
    {
        if (! $this->verifyOperationToken('internal:app-source-path:probe')) {
            return self::FAILURE;
        }

        try {
            $result = $paths->probe($this->argument('path'), $this->option('boundary'));
        } catch (LocalAppSourcePathProbeFailure $failure) {
            return $this->renderFailure($failure->errorCode, $failure->getMessage(), $failure->meta);
        }

        return $this->emitInternalSuccess($result['data'], $result['meta']);
    }
}
