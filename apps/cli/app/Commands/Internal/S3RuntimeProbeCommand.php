<?php

declare(strict_types=1);

namespace App\Commands\Internal;

use App\Services\S3\LocalS3RuntimeProbe;
use App\Services\S3\LocalS3RuntimeProbeFailure;

final class S3RuntimeProbeCommand extends InternalExecutorCommand
{
    #[\Override]
    protected $signature = 'internal:s3-runtime:probe {--operation-token=} {--json}';

    #[\Override]
    protected $description = 'Probe the local S3 runtime container through fixed argv operations';

    public function handle(LocalS3RuntimeProbe $probe): int
    {
        if (! $this->verifyOperationToken('internal:s3-runtime:probe')) {
            return self::FAILURE;
        }

        try {
            return $this->emitInternalSuccess($probe->probe());
        } catch (LocalS3RuntimeProbeFailure $failure) {
            return $this->renderFailure($failure->errorCode, $failure->getMessage(), $failure->meta);
        }
    }
}
