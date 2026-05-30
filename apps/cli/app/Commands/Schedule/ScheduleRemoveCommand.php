<?php

declare(strict_types=1);

namespace App\Commands\Schedule;

use App\Commands\Schedule\Concerns\ResolvesScheduleSelection;
use App\Exceptions\GatewayApiException;

use function Laravel\Prompts\confirm;

final class ScheduleRemoveCommand extends ScheduleGatewayCommand
{
    use ResolvesScheduleSelection;

    #[\Override]
    protected $signature = 'schedule:remove
        {name? : Schedule name}
        {--app= : Filter by app scope}
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

        try {
            $response = $this->gatewayDelete($this->scheduleScopePath('/api/schedules/'.rawurlencode($name)), [
                'destructive_consent' => true,
                'destructive_consent_source' => 'force',
            ]);
        } catch (GatewayApiException $exception) {
            return $this->renderGatewayFailure($exception);
        }

        return $this->renderSuccess($response);
    }

    private function confirmRemoval(string $name): ?int
    {
        if ($this->option('force') === true) {
            return null;
        }

        if ($this->wantsJson() || ! $this->input->isInteractive()) {
            return $this->renderFailure('destructive_consent_required', 'Use --force to remove this schedule.', [
                'field' => 'force',
            ]);
        }

        if (confirm(label: "Remove schedule '{$name}'?", default: false)) {
            return null;
        }

        return $this->renderFailure('destructive_consent_required', 'No schedule was removed.', [
            'field' => 'force',
        ]);
    }
}
