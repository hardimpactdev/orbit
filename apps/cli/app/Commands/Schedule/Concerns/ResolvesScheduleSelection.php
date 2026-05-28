<?php

declare(strict_types=1);

namespace App\Commands\Schedule\Concerns;

use App\Exceptions\GatewayApiException;

trait ResolvesScheduleSelection
{
    private ?string $resolvedScheduleApp = null;

    private ?string $resolvedScheduleNode = null;

    protected function resolveScheduleName(string $prompt): string|int
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

        try {
            $response = $this->gatewayGet('/api/schedules', $this->scheduleScopeQuery());
        } catch (GatewayApiException $exception) {
            return $this->renderGatewayFailure($exception);
        }

        $choices = $this->scheduleChoices($response);

        if ($choices === []) {
            return $this->renderFailure('schedule.not_found', 'No schedules found.', $this->scheduleScopeQuery());
        }

        $selected = $this->choice($prompt, array_keys($choices));

        if (! is_string($selected) || ! array_key_exists($selected, $choices)) {
            return $this->renderFailure('validation_failed', 'Operation cancelled.');
        }

        $selection = $choices[$selected];
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

    /**
     * @return array<string, mixed>
     */
    private function scheduleScopeQuery(): array
    {
        return $this->filledQuery([
            'app' => $this->stringOption('app'),
            'node' => $this->stringOption('node'),
        ]);
    }

    /**
     * @param  array<string, mixed>  $response
     * @return array<string, array{name: string, scope: string|null, target: string|null, app: string|null, node: string|null}>
     */
    private function scheduleChoices(array $response): array
    {
        $schedules = $this->schedulePayloads($response);
        $nameCounts = $this->scheduleNameCounts($schedules);
        $choices = [];

        foreach ($schedules as $schedule) {
            $selection = $this->scheduleSelection($schedule);

            if ($selection === null) {
                continue;
            }

            $label = $this->scheduleChoiceLabel($selection, $nameCounts[$selection['name']] > 1);
            $choices[$this->uniqueScheduleChoiceLabel($label, $choices)] = $selection;
        }

        return $choices;
    }

    /**
     * @param  array<string, mixed>  $response
     * @return list<array<string, mixed>>
     */
    private function schedulePayloads(array $response): array
    {
        $success = $response['success'] ?? null;
        $data = is_array($success) && is_array($success['data'] ?? null)
            ? $success['data']
            : $response;
        $schedules = $data['schedules'] ?? [];

        if (! is_array($schedules)) {
            return [];
        }

        return array_values(array_filter($schedules, is_array(...)));
    }

    /**
     * @param  list<array<string, mixed>>  $schedules
     * @return array<string, int>
     */
    private function scheduleNameCounts(array $schedules): array
    {
        $counts = [];

        foreach ($schedules as $schedule) {
            $name = $this->scheduleString($schedule['name'] ?? null);

            if ($name === null) {
                continue;
            }

            $counts[$name] = ($counts[$name] ?? 0) + 1;
        }

        return $counts;
    }

    /**
     * @param  array<string, mixed>  $schedule
     * @return array{name: string, scope: string|null, target: string|null, app: string|null, node: string|null}|null
     */
    private function scheduleSelection(array $schedule): ?array
    {
        $name = $this->scheduleString($schedule['name'] ?? null);

        if ($name === null) {
            return null;
        }

        $target = $schedule['target'] ?? [];
        $target = is_array($target) ? $target : [];
        $scope = $this->scheduleString($schedule['scope'] ?? $target['type'] ?? null);
        $targetName = $this->scheduleString($target['name'] ?? null);

        return [
            'name' => $name,
            'scope' => $scope,
            'target' => $targetName,
            'app' => $scope === 'app' ? $targetName : null,
            'node' => $scope === 'node' ? $targetName : null,
        ];
    }

    /**
     * @param  array{name: string, scope: string|null, target: string|null, app: string|null, node: string|null}  $selection
     */
    private function scheduleChoiceLabel(array $selection, bool $needsScope): string
    {
        if (! $needsScope || $selection['scope'] === null || $selection['target'] === null) {
            return $selection['name'];
        }

        return "{$selection['name']} ({$selection['scope']}: {$selection['target']})";
    }

    /**
     * @param  array<string, array{name: string, scope: string|null, target: string|null, app: string|null, node: string|null}>  $choices
     */
    private function uniqueScheduleChoiceLabel(string $label, array $choices): string
    {
        if (! array_key_exists($label, $choices)) {
            return $label;
        }

        $counter = 2;

        while (array_key_exists("{$label} #{$counter}", $choices)) {
            $counter++;
        }

        return "{$label} #{$counter}";
    }

    private function scheduleString(mixed $value): ?string
    {
        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }
}
