<?php

declare(strict_types=1);

namespace App\Commands\Schedule\Concerns;

trait ResolvesScheduleSelection
{
    private ?string $resolvedScheduleApp = null;

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
            app: $this->stringOption('app'),
            node: $this->stringOption('node'),
        );

        if (is_int($selection)) {
            return $selection;
        }

        $this->resolvedScheduleApp = $selection['app'];
        $this->resolvedScheduleNode = $selection['node'];

        return $selection['name'];
    }

    protected function resolvedScheduleApp(): ?string
    {
        return $this->resolvedScheduleApp ?? $this->stringOption('app');
    }

    protected function resolvedScheduleNode(): ?string
    {
        return $this->resolvedScheduleNode ?? $this->stringOption('node');
    }
}
