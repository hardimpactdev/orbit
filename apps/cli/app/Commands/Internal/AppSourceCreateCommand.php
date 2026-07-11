<?php

declare(strict_types=1);

namespace App\Commands\Internal;

use App\Services\Apps\LocalAppSourceCreateAction;
use App\Services\Apps\LocalAppSourceCreateFailure;

final class AppSourceCreateCommand extends InternalExecutorCommand
{
    #[\Override]
    protected $signature = 'internal:app-source:create {user} {path} {--repository=} {--template-repository=} {--new-repository=} {--operation-token=} {--json}';

    #[\Override]
    protected $description = 'Clone an app repository or create one from a template on the local node';

    public function handle(LocalAppSourceCreateAction $action): int
    {
        if (! $this->verifyOperationToken('internal:app-source:create')) {
            return self::FAILURE;
        }

        try {
            return $this->emitInternalSuccess($action->create(
                user: $this->argument('user'),
                path: $this->argument('path'),
                repository: $this->option('repository'),
                templateRepository: $this->option('template-repository'),
                newRepository: $this->option('new-repository'),
            ));
        } catch (LocalAppSourceCreateFailure $failure) {
            return $this->renderFailure($failure->errorCode, $failure->getMessage(), $failure->meta);
        }
    }
}
