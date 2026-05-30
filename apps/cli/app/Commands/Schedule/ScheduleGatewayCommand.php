<?php

declare(strict_types=1);

namespace App\Commands\Schedule;

use App\Commands\Concerns\PromptsForGatewayRegistryEntities;
use App\Commands\Concerns\ResolvesHostContext;
use App\Commands\GatewayCommand;

abstract class ScheduleGatewayCommand extends GatewayCommand
{
    use PromptsForGatewayRegistryEntities;
    use ResolvesHostContext;

    /**
     * @param  array<string, mixed>  $extraMeta
     */
    protected function failValidation(string $field, string $message, array $extraMeta = []): int
    {
        return $this->renderFailure('validation_failed', $message, array_merge(['field' => $field], $extraMeta));
    }

    protected function validateScopeFilters(): ?int
    {
        if (! $this->hasMutuallyExclusiveOptions('app', 'node')) {
            return null;
        }

        return $this->renderFailure('validation_failed', 'The schedule filters are mutually exclusive.', [
            'fields' => ['app', 'node'],
        ]);
    }

    protected function isInteractiveInput(): bool
    {
        return ! $this->wantsJson() && $this->input->isInteractive();
    }

    protected function scheduleScopePath(string $path): string
    {
        $query = $this->filledQuery([
            'app' => $this->resolvedScheduleApp(),
            'node' => $this->resolvedScheduleNode(),
        ]);

        if ($query === []) {
            return $path;
        }

        return $path.'?'.http_build_query($query, '', '&', PHP_QUERY_RFC3986);
    }

    protected function resolvedScheduleApp(): ?string
    {
        return $this->stringOption('app');
    }

    protected function resolvedScheduleNode(): ?string
    {
        return $this->stringOption('node');
    }
}
