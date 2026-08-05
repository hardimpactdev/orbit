<?php

declare(strict_types=1);

namespace App\Commands\Schedule;

use App\Commands\Concerns\PromptsForGatewayRegistryEntities;
use App\Commands\Concerns\ResolvesHostContext;
use App\Commands\GatewayCommand;
use App\Commands\Schedule\Concerns\ResolvesScheduleSelection;
use App\Exceptions\GatewayApiException;

final class ScheduleLogsCommand extends GatewayCommand
{
    use PromptsForGatewayRegistryEntities;
    use ResolvesHostContext;
    use ResolvesScheduleSelection;

    #[\Override]
    protected $signature = 'schedule:logs
        {name? : Schedule name}
        {--instance= : Filter by instance (app.instance; bare project only when unambiguous)}
        {--node= : Filter by node scope}
        {--run= : Run history id}
        {--lines=100 : Number of stdout/stderr lines}
        {--json}';

    #[\Override]
    protected $description = 'Show captured output for a schedule run.';

    public function handle(): int
    {
        if ($this->hasMutuallyExclusiveOptions('instance', 'node')) {
            return $this->renderFailure(
                'validation_failed',
                'The schedule filters are mutually exclusive.',
                ['fields' => ['instance', 'node']],
            );
        }

        $name = $this->resolveScheduleName();

        if (is_int($name)) {
            return $name;
        }

        $run = $this->positiveIntegerOption('run');

        if ($run === false) {
            return $this->renderFailure('validation_failed', 'The --run value must be a positive integer.', [
                'field' => 'run',
            ]);
        }

        $lines = $this->positiveIntegerOption('lines');

        if ($lines === false || $lines === null) {
            return $this->renderFailure('validation_failed', 'The --lines value must be a positive integer.', [
                'field' => 'lines',
            ]);
        }

        try {
            $response = $this->gatewayGet('/api/schedules/'.rawurlencode($name).'/logs', $this->filledQuery([
                'instance' => $this->resolvedScheduleInstance(),
                'node' => $this->resolvedScheduleNode(),
                'run' => $run,
                'lines' => $lines,
            ]));
        } catch (GatewayApiException $exception) {
            return $this->renderGatewayFailure($exception);
        }

        if ($this->wantsJson()) {
            return $this->renderSuccess($response);
        }

        $this->renderCapturedOutput($response);

        return self::SUCCESS;
    }

    private function positiveIntegerOption(string $key): int|false|null
    {
        $value = $this->option($key);

        if ($value === null || $value === '') {
            return null;
        }

        if (! is_numeric($value)) {
            return false;
        }

        $integer = (int) $value;

        return $integer > 0 ? $integer : false;
    }

    /**
     * @param  array<string, mixed>  $response
     */
    private function renderCapturedOutput(array $response): void
    {
        $data = $this->successData($response);
        $output = is_array($data['output'] ?? null) ? $data['output'] : [];
        $stdout = is_string($output['stdout'] ?? null) ? trim($output['stdout']) : '';
        $stderr = is_string($output['stderr'] ?? null) ? trim($output['stderr']) : '';

        if ($stdout === '' && $stderr === '') {
            $this->line('No captured schedule output.');

            return;
        }

        if ($stdout !== '') {
            $this->line('stdout:');
            $this->line($stdout);
        }

        if ($stderr !== '') {
            $this->line('stderr:');
            $this->line($stderr);
        }
    }

    /**
     * @param  array<string, mixed>  $response
     * @return array<string, mixed>
     */
    private function successData(array $response): array
    {
        $success = $response['success'] ?? null;

        if (is_array($success) && is_array($success['data'] ?? null)) {
            return $success['data'];
        }

        return $response;
    }
}
