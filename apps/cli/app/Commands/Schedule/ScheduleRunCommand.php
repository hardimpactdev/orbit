<?php

declare(strict_types=1);

namespace App\Commands\Schedule;

use App\Commands\Concerns\WithStepTree;
use App\Commands\Schedule\Concerns\ResolvesScheduleSelection;
use App\Exceptions\GatewayApiException;
use RuntimeException;

final class ScheduleRunCommand extends ScheduleGatewayCommand
{
    use ResolvesScheduleSelection;
    use WithStepTree;

    #[\Override]
    protected $signature = 'schedule:run
        {name? : Schedule name}
        {--instance= : Filter by instance (app.instance; bare project only when unambiguous)}
        {--node= : Filter by node scope}
        {--json : Output JSON}';

    #[\Override]
    protected $description = 'Run one configured schedule immediately.';

    public function handle(): int
    {
        $scopeValidation = $this->validateScopeFilters();

        if ($scopeValidation !== null) {
            return $scopeValidation;
        }

        $name = $this->resolveScheduleName();

        if (is_int($name)) {
            return $name;
        }

        if ($this->wantsJson()) {
            try {
                $response = $this->runSchedule($name);
            } catch (GatewayApiException $exception) {
                return $this->renderGatewayFailure($exception);
            }

            return $this->renderSuccess($response);
        }

        return $this->renderRunTree($name);
    }

    private function renderRunTree(string $name): int
    {
        $response = [];

        $outcome = $this->runStepOperation(
            'Running Schedule',
            [
                ['label' => 'Resolve schedule'],
                ['label' => 'Open gateway execution'],
                ['label' => 'Run scheduled command'],
                ['label' => 'Record run history'],
            ],
            work: function () use ($name, &$response): array {
                return $response = $this->runScheduleForHuman($name);
            },
            doneFooter: function () use ($name, &$response): string {
                return "Schedule '{$name}' ".($this->runStatus($response) === 'completed' ? 'completed' : 'finished');
            },
        );

        if (! $outcome->isCompleted()) {
            return self::FAILURE;
        }

        $this->renderRunDetail($response);

        return self::SUCCESS;
    }

    /**
     * @return array<string, mixed>
     */
    private function runSchedule(string $name): array
    {
        return $this->gatewayPost($this->scheduleScopePath('/api/schedules/'.rawurlencode($name).'/run'));
    }

    /**
     * Run the gateway execution inside the progress tree, re-throwing gateway
     * failures with their operator-facing message so the failed footer renders
     * the documented prose rather than a JSON envelope.
     *
     * @return array<string, mixed>
     */
    private function runScheduleForHuman(string $name): array
    {
        try {
            return $this->runSchedule($name);
        } catch (GatewayApiException $exception) {
            throw new RuntimeException(
                $exception->gatewayErrorMessage() ?? $exception->getMessage(),
                previous: $exception,
            );
        }
    }

    /**
     * Render the documented run detail block: schedule name, scope, target, run
     * id, status, exit code, started and finished timestamps, and the captured
     * command output.
     *
     * @param  array<string, mixed>  $response
     */
    private function renderRunDetail(array $response): void
    {
        $data = $this->successData($response);
        $run = is_array($data['run'] ?? null) ? $data['run'] : [];

        if ($run !== []) {
            $this->line('  Schedule: '.$this->stringField($run, 'schedule'));
            $this->line('  Scope: '.$this->stringField($run, 'scope'));
            $this->line('  Target: '.$this->targetSummary($run));
            $this->line('  Run id: '.$this->scalarField($run, 'id'));
            $this->line('  Status: '.$this->stringField($run, 'status'));
            $this->line('  Exit code: '.$this->scalarField($run, 'exit_code'));
            $this->line('  Started at: '.$this->stringField($run, 'started_at'));
            $this->line('  Finished at: '.$this->stringField($run, 'finished_at'));
        }

        $this->renderCapturedOutput($data['output'] ?? null);
    }

    private function renderCapturedOutput(mixed $output): void
    {
        if (! is_array($output)) {
            return;
        }

        $stdout = is_string($output['stdout'] ?? null) ? trim($output['stdout']) : '';
        $stderr = is_string($output['stderr'] ?? null) ? trim($output['stderr']) : '';

        if ($stdout !== '') {
            $this->line('  Output:');
            $this->line($stdout);
        }

        if ($stderr !== '') {
            $this->line('  Errors:');
            $this->line($stderr);
        }
    }

    /**
     * @param  array<string, mixed>  $run
     */
    private function targetSummary(array $run): string
    {
        $target = $run['target'] ?? null;

        if (! is_array($target)) {
            return '-';
        }

        $type = is_string($target['type'] ?? null) ? $target['type'] : '-';
        $name = is_string($target['name'] ?? null) ? $target['name'] : '-';
        $node = is_string($target['node'] ?? null) && $target['node'] !== '' ? $target['node'] : null;

        $summary = "{$type} {$name}";

        return $node === null ? $summary : "{$summary} on {$node}";
    }

    /**
     * @param  array<string, mixed>  $run
     */
    private function stringField(array $run, string $key): string
    {
        return is_string($run[$key] ?? null) && $run[$key] !== '' ? $run[$key] : '-';
    }

    /**
     * @param  array<string, mixed>  $run
     */
    private function scalarField(array $run, string $key): string
    {
        $value = $run[$key] ?? null;

        return is_scalar($value) ? (string) $value : '-';
    }

    /**
     * @param  array<string, mixed>  $response
     */
    private function runStatus(array $response): ?string
    {
        $data = $this->successData($response);
        $run = is_array($data['run'] ?? null) ? $data['run'] : [];
        $status = $run['status'] ?? null;

        return is_string($status) ? $status : null;
    }

    /**
     * @param  array<string, mixed>  $response
     * @return array<string, mixed>
     */
    private function successData(array $response): array
    {
        $data = $response['success']['data'] ?? null;

        return is_array($data) ? $data : [];
    }
}
