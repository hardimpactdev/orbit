<?php

declare(strict_types=1);

namespace App\Commands\Schedule;

use App\Exceptions\GatewayApiException;
use App\Exceptions\OrbitConfigStoreException;
use App\Services\OrbitConfigStore;

use function Laravel\Prompts\select;
use function Laravel\Prompts\text;

final class ScheduleAddCommand extends ScheduleGatewayCommand
{
    #[\Override]
    protected $signature = 'schedule:add
        {name? : Schedule name}
        {--command= : Command to execute}
        {--script= : Managed script path to execute}
        {--interval= : Portable interval expression}
        {--app= : Target app scope}
        {--node= : Target node scope}
        {--timezone=UTC : IANA timezone}
        {--json : Output JSON}';

    #[\Override]
    protected $description = 'Add a recurring schedule.';

    public function handle(): int
    {
        $name = $this->resolveName();

        if ($name === null) {
            return $this->failValidation('name', 'The schedule name is required.');
        }

        $nameValidation = $this->validateName($name);

        if ($nameValidation !== null) {
            return $nameValidation;
        }

        $target = $this->resolveTarget();

        if (is_int($target)) {
            return $target;
        }

        $execution = $this->resolveExecutionSource();

        if (is_int($execution)) {
            return $execution;
        }

        $interval = $this->resolveInterval();

        if ($interval === null) {
            return $this->renderFailure('schedule.interval_invalid', 'The schedule interval is required.', ['field' => 'interval']);
        }

        $timezone = $this->stringOption('timezone') ?? 'UTC';

        if (! in_array($timezone, timezone_identifiers_list(), true)) {
            return $this->failValidation('timezone', 'The schedule timezone must be a valid IANA timezone.', ['value' => $timezone]);
        }

        try {
            $response = $this->gatewayPost('/api/schedules', $this->filledQuery([
                'name' => $name,
                'app' => $target['app'],
                'node' => $target['node'],
                'interval' => $interval,
                'timezone' => $timezone,
                'command' => $execution['command'],
                'script' => $execution['script'],
            ]));
        } catch (GatewayApiException $exception) {
            return $this->renderGatewayFailure($exception);
        }

        return $this->renderSuccess($response);
    }

    private function resolveName(): ?string
    {
        $name = $this->stringArgument('name');

        if ($name !== null) {
            return $name;
        }

        if ($this->isInteractiveInput()) {
            return trim(text(label: 'Schedule name', required: true));
        }

        return null;
    }

    private function validateName(string $name): ?int
    {
        if (preg_match('/^[a-z0-9](?:[a-z0-9-]{0,62}[a-z0-9])?$/', $name)) {
            return null;
        }

        return $this->failValidation('name', 'The schedule name must contain only lowercase letters, digits, and hyphens, cannot start or end with a hyphen, and may not exceed 64 characters.', [
            'value' => $name,
        ]);
    }

    /**
     * @return array{app: ?string, node: ?string}|int
     */
    private function resolveTarget(): array|int
    {
        $app = $this->stringOption('app');
        $node = $this->stringOption('node');

        if ($app !== null && $node !== null) {
            return $this->failValidation('target', 'Exactly one schedule target is required.', ['fields' => ['app', 'node']]);
        }

        if ($app !== null || $node !== null) {
            return [
                'app' => $app,
                'node' => $node,
            ];
        }

        try {
            $defaultNode = app(OrbitConfigStore::class)->defaultNode();
        } catch (OrbitConfigStoreException $exception) {
            return $this->renderFailure($exception->orbitCode, $exception->getMessage());
        }

        if ($defaultNode !== null) {
            return [
                'app' => null,
                'node' => $defaultNode,
            ];
        }

        if (! $this->isInteractiveInput()) {
            return $this->failValidation('target', 'Exactly one schedule target is required.', ['fields' => ['app', 'node']]);
        }

        $targetType = (string) select(
            label: 'Scope',
            options: ['app' => 'App', 'node' => 'Node'],
            default: 'app',
        );

        if ($targetType === 'app') {
            return [
                'app' => trim(text(label: 'Target app', required: true)),
                'node' => null,
            ];
        }

        return [
            'app' => null,
            'node' => trim(text(label: 'Target node', required: true)),
        ];
    }

    /**
     * @return array{command: ?string, script: ?string}|int
     */
    private function resolveExecutionSource(): array|int
    {
        $command = $this->stringOption('command');
        $script = $this->stringOption('script');

        if ($command !== null && $script !== null) {
            return $this->failValidation('execution_source', 'Exactly one schedule execution source is required.', ['fields' => ['command', 'script']]);
        }

        if ($command !== null || $script !== null) {
            return [
                'command' => $command,
                'script' => $script,
            ];
        }

        if (! $this->isInteractiveInput()) {
            return $this->failValidation('execution_source', 'Exactly one schedule execution source is required.', ['fields' => ['command', 'script']]);
        }

        $source = (string) select(
            label: 'Source',
            options: ['command' => 'Inline command', 'script' => 'Repo script'],
            default: 'command',
        );

        if ($source === 'command') {
            return [
                'command' => trim(text(label: 'Command', required: true)),
                'script' => null,
            ];
        }

        return [
            'command' => null,
            'script' => trim(text(label: 'Script path', required: true)),
        ];
    }

    private function resolveInterval(): ?string
    {
        $interval = $this->stringOption('interval');

        if ($interval !== null) {
            return $interval;
        }

        if ($this->isInteractiveInput()) {
            return trim(text(label: 'Interval (cron expression)', required: true));
        }

        return null;
    }
}
