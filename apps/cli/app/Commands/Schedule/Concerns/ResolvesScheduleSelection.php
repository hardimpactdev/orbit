<?php

declare(strict_types=1);

namespace App\Commands\Schedule\Concerns;

trait ResolvesScheduleSelection
{
    private ?string $resolvedScheduleInstance = null;

    private ?string $resolvedScheduleNode = null;

    protected function resolveScheduleName(): string|int
    {
        $name = $this->stringArgument('name');

        if ($name !== null) {
            return $name;
        }

        if ($this->wantsJson() || ! $this->input->isInteractive()) {
            return $this->renderFailure('validation_failed', 'The schedule name is required.', [
                'field' => 'name',
                'reason' => 'missing',
            ]);
        }

        $selection = $this->promptForVisibleSchedule(
            instance: $this->stringOption('instance'),
            node: $this->stringOption('node'),
        );

        if (is_int($selection)) {
            return $selection;
        }

        $this->resolvedScheduleInstance = is_string($selection['instance'] ?? null) ? $selection['instance'] : null;
        $this->resolvedScheduleNode = is_string($selection['node'] ?? null) ? $selection['node'] : null;

        return $selection['name'];
    }

    protected function resolvedScheduleInstance(): ?string
    {
        $instance = $this->resolvedScheduleInstance ?? $this->stringOption('instance');

        return is_string($instance) ? $instance : null;
    }

    protected function resolvedScheduleNode(): ?string
    {
        return $this->resolvedScheduleNode ?? $this->stringOption('node');
    }
}
