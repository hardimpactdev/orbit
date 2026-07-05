<?php

declare(strict_types=1);

namespace App\Commands\Internal;

use App\Services\Apps\LocalAppCacheClearAction;
use App\Services\Apps\LocalAppCacheClearFailure;

final class AppCacheClearCommand extends InternalExecutorCommand
{
    #[\Override]
    protected $signature = 'internal:app-cache:clear {path} {php-version} {runtime-user} {--operation-token=} {--json}';

    #[\Override]
    protected $description = 'Clear Laravel app caches on the local node through fixed argv operations';

    public function handle(LocalAppCacheClearAction $action): int
    {
        if (! $this->verifyOperationToken('internal:app-cache:clear')) {
            return self::FAILURE;
        }

        try {
            return $this->emitInternalSuccess($action->clear(
                path: $this->argument('path'),
                phpVersion: $this->argument('php-version'),
                runtimeUser: $this->argument('runtime-user'),
            ));
        } catch (LocalAppCacheClearFailure $failure) {
            return $this->renderFailure($failure->errorCode, $failure->getMessage(), $failure->meta);
        }
    }
}
