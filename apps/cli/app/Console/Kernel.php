<?php

declare(strict_types=1);

namespace App\Console;

use App\Commands\Solo\SoloCommandSignature;
use App\Commands\Solo\SoloCommandTargetPayload;
use App\Commands\Solo\SoloExtensionPlaceholderCommand;
use App\Commands\Solo\SoloMutatingCommand;
use App\Commands\Solo\SoloMutatingOperationCatalog;
use App\Commands\Solo\SoloMutatingOperationDefinition;
use App\Commands\Solo\SoloReadOnlyCommand;
use App\Commands\Solo\SoloReadOperationCatalog;
use App\Commands\Solo\SoloReadOperationDefinition;
use Illuminate\Console\Application as Artisan;
use LaravelZero\Framework\Kernel as LaravelZeroKernel;
use Orbit\Core\Extensions\OrbitExtensionRegistry;

final class Kernel extends LaravelZeroKernel
{
    #[\Override]
    protected function commands(): void
    {
        parent::commands();

        Artisan::starting(static function ($artisan): void {
            foreach (app(OrbitExtensionRegistry::class)->require('solo')->commands as $commandName) {
                $readOperation = SoloReadOperationCatalog::find($commandName);
                $mutatingOperation = SoloMutatingOperationCatalog::find($commandName);

                $artisan->add(
                    match (true) {
                        $readOperation instanceof SoloReadOperationDefinition => new SoloReadOnlyCommand(
                            $readOperation,
                            app(SoloCommandSignature::class),
                            app(SoloCommandTargetPayload::class),
                        ),
                        $mutatingOperation instanceof SoloMutatingOperationDefinition => new SoloMutatingCommand(
                            $mutatingOperation,
                            app(SoloCommandSignature::class),
                            app(SoloCommandTargetPayload::class),
                        ),
                        default => new SoloExtensionPlaceholderCommand($commandName),
                    },
                );
            }
        });
    }

    /**
     * @param  array<string, mixed>  $parameters
     */
    #[\Override]
    public function call($command, array $parameters = [], $outputBuffer = null)
    {
        return parent::call($command, $this->normalizeParameters($command, $parameters), $outputBuffer);
    }

    /**
     * @param  array<string, mixed>  $parameters
     * @return array<string, mixed>
     */
    private function normalizeParameters(mixed $command, array $parameters): array
    {
        if ($command === 'analytics:update' && array_key_exists('--version', $parameters)) {
            $parameters['--requested-version'] = $parameters['--version'];
            unset($parameters['--version']);

            return $parameters;
        }

        if ($command === 'process:add' && array_key_exists('--version', $parameters)) {
            $parameters['--service-version'] = $parameters['--version'];
            unset($parameters['--version']);

            return $parameters;
        }

        if ($command !== 'tool:install') {
            return $parameters;
        }

        if (! array_key_exists('--version', $parameters)) {
            return $parameters;
        }

        $parameters['--tool-version'] = $parameters['--version'];
        unset($parameters['--version']);

        return $parameters;
    }
}
