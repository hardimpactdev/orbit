<?php

declare(strict_types=1);

namespace App\Commands\Internal;

use App\Services\Processes\LocalRuntimeDependencies;
use App\Services\Processes\LocalRuntimeDependenciesFailure;
use Orbit\Core\Enums\InternalCommand;

final class RuntimeDependenciesCommand extends InternalExecutorCommand
{
    #[\Override]
    protected $signature = 'internal:runtime-dependencies {action} {path} {family?} {--operation-token=} {--json}';

    #[\Override]
    protected $description = 'Inspect, prune, or restore generated development dependencies on the local node';

    public function handle(LocalRuntimeDependencies $dependencies): int
    {
        if (! $this->verifyOperationToken(InternalCommand::RuntimeDependencies->value)) {
            return self::FAILURE;
        }

        try {
            $result = match ($this->argument('action')) {
                'inspect' => $dependencies->inspect($this->argument('path')),
                'prune' => $dependencies->prune($this->argument('path')),
                'restore' => $dependencies->restore(
                    $this->argument('path'),
                    $this->argument('family'),
                ),
                default => throw new LocalRuntimeDependenciesFailure(
                    errorCode: 'validation_failed',
                    message: 'The runtime dependency action is invalid.',
                    meta: ['field' => 'action'],
                ),
            };

            return $this->emitInternalSuccess($result);
        } catch (LocalRuntimeDependenciesFailure $failure) {
            return $this->renderFailure($failure->errorCode, $failure->getMessage(), $failure->meta);
        }
    }
}
