<?php

declare(strict_types=1);

namespace App\Console;

use LaravelZero\Framework\Kernel as LaravelZeroKernel;

final class Kernel extends LaravelZeroKernel
{
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
