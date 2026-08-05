<?php

declare(strict_types=1);

namespace App\Commands\Schedule;

use App\Commands\Concerns\WithStepTree;
use App\Commands\Schedule\Concerns\ResolvesScheduleSelection;
use App\Exceptions\GatewayApiException;
use RuntimeException;

use function Laravel\Prompts\confirm;

final class ScheduleRemoveCommand extends ScheduleGatewayCommand
{
    use ResolvesScheduleSelection;
    use WithStepTree;

    #[\Override]
    protected $signature = 'schedule:remove
        {name? : Schedule name}
        {--instance= : Filter by instance (app.instance; bare project only when unambiguous)}
        {--node= : Filter by node scope}
        {--force : Confirm destructive operation without prompting}
        {--json : Output JSON}';

    #[\Override]
    protected $description = 'Remove a recurring schedule.';

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

        $confirmation = $this->confirmRemoval($name);

        if ($confirmation !== null) {
            return $confirmation;
        }

        if ($this->wantsJson()) {
            try {
                $response = $this->removeSchedule($name);
            } catch (GatewayApiException $exception) {
                return $this->renderGatewayFailure($exception);
            }

            return $this->renderSuccess($response);
        }

        return $this->renderRemoveTree($name);
    }

    private function renderRemoveTree(string $name): int
    {
        $response = [];

        $outcome = $this->runStepOperation(
            'Removing Schedule',
            [
                ['label' => 'Resolve schedule'],
                ['label' => 'Delete gateway schedule row'],
            ],
            work: function () use ($name, &$response): array {
                return $response = $this->removeScheduleForHuman($name);
            },
            doneFooter: "Schedule '{$name}' removed",
        );

        if (! $outcome->isCompleted()) {
            return self::FAILURE;
        }

        $this->renderRemovalDetail($response);

        return self::SUCCESS;
    }

    /**
     * @return array<string, mixed>
     */
    private function removeSchedule(string $name): array
    {
        return $this->gatewayDelete($this->scheduleScopePath('/api/schedules/'.rawurlencode($name)), [
            'destructive_consent' => true,
            'destructive_consent_source' => 'force',
        ]);
    }

    /**
     * Run the destructive call inside the progress tree, re-throwing gateway
     * failures with their operator-facing message so the failed footer renders
     * the documented prose rather than a JSON envelope.
     *
     * @return array<string, mixed>
     */
    private function removeScheduleForHuman(string $name): array
    {
        try {
            return $this->removeSchedule($name);
        } catch (GatewayApiException $exception) {
            throw new RuntimeException(
                $exception->gatewayErrorMessage() ?? $exception->getMessage(),
                previous: $exception,
            );
        }
    }

    /**
     * Render the documented removal detail block: schedule name, scope, and target.
     *
     * @param  array<string, mixed>  $response
     */
    private function renderRemovalDetail(array $response): void
    {
        $data = $this->successData($response);
        $schedule = is_array($data['schedule'] ?? null) ? $data['schedule'] : [];

        if ($schedule !== []) {
            $this->line('  Name: '.$this->stringField($schedule, 'name'));
            $this->line('  Scope: '.$this->stringField($schedule, 'scope'));
            $this->line('  Target: '.$this->targetSummary($schedule));
        }
    }

    /**
     * @param  array<string, mixed>  $schedule
     */
    private function targetSummary(array $schedule): string
    {
        $target = $schedule['target'] ?? null;

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
     * @param  array<string, mixed>  $source
     */
    private function stringField(array $source, string $key): string
    {
        return is_string($source[$key] ?? null) && $source[$key] !== '' ? $source[$key] : '-';
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

    private function confirmRemoval(string $name): ?int
    {
        if ($this->option('force') === true) {
            return null;
        }

        if ($this->wantsJson() || ! $this->input->isInteractive()) {
            return $this->renderFailure('validation_failed', 'Use --force to remove this schedule.', [
                'field' => 'force',
                'reason' => 'destructive_consent_required',
            ]);
        }

        if (confirm(label: "Remove schedule '{$name}'?", default: false)) {
            return null;
        }

        return $this->renderFailure('validation_failed', 'No schedule was removed.', [
            'field' => 'force',
            'reason' => 'destructive_consent_required',
        ]);
    }
}
