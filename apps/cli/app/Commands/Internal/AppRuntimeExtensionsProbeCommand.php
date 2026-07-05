<?php

declare(strict_types=1);

namespace App\Commands\Internal;

use App\Services\Apps\LocalAppRuntimeExtensionsProbe;
use App\Services\Apps\LocalAppRuntimeExtensionsProbeFailure;

final class AppRuntimeExtensionsProbeCommand extends InternalExecutorCommand
{
    #[\Override]
    protected $signature = 'internal:app-runtime-extensions:probe {container} {--operation-token=} {--json}';

    #[\Override]
    protected $description = 'Probe PHP extensions inside an app runtime container';

    public function handle(LocalAppRuntimeExtensionsProbe $probe): int
    {
        if (! $this->verifyOperationToken('internal:app-runtime-extensions:probe')) {
            return self::FAILURE;
        }

        try {
            $result = $probe->probe($this->argument('container'));
        } catch (LocalAppRuntimeExtensionsProbeFailure $failure) {
            return $this->renderFailure($failure->errorCode, $failure->getMessage(), $failure->meta);
        }

        return $this->emitInternalSuccess($result['data'], $result['meta']);
    }
}
