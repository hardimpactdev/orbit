<?php

declare(strict_types=1);

namespace App\Commands\App;

use App\Commands\Concerns\WithStepTree;
use App\Exceptions\GatewayApiException;
use RuntimeException;

use function Laravel\Prompts\confirm;

final class AppRemoveCommand extends AppGatewayCommand
{
    use WithStepTree;

    #[\Override]
    protected $signature = 'project:remove
        {project? : Project name or hostname}
        {--force : Confirm destructive operation without prompting}
        {--json : Output JSON}';

    #[\Override]
    protected $description = 'Remove a project and its owned artifacts.';

    public function handle(): int
    {
        $selector = $this->stringArgument('project');

        if ($selector === null) {
            return $this->failValidation('project', 'Project name is required.');
        }

        if ($this->option('force') !== true) {
            $confirmed = $this->confirmRemoval($selector);

            if (is_int($confirmed)) {
                return $confirmed;
            }

            if (! $confirmed) {
                return $this->renderFailure('validation_failed', 'Operation cancelled.');
            }
        }

        if ($this->wantsJson()) {
            try {
                $response = $this->removeApp($selector);
            } catch (GatewayApiException $exception) {
                return $this->renderGatewayFailure($exception);
            }

            return $this->renderSuccess($response);
        }

        return $this->renderRemovalTree($selector);
    }

    private function renderRemovalTree(string $selector): int
    {
        $response = [];

        $outcome = $this->runStepOperation(
            'Removing Project',
            [
                ['label' => 'Validate removal', 'doneLabel' => 'Validated removal'],
                ['label' => 'Apply and verify project removal', 'doneLabel' => 'Applied and verified project removal'],
                ['label' => 'Remove project-owned proxy routes', 'doneLabel' => 'Removed project-owned proxy routes'],
                ['label' => 'Remove project-owned schedules', 'doneLabel' => 'Removed project-owned schedules'],
                ['label' => 'Remove project-owned workspaces', 'doneLabel' => 'Removed project-owned workspaces'],
                [
                    'label' => 'Stop and remove project processes',
                    'doneLabel' => 'Stopped and removed project processes',
                ],
                ['label' => 'Clean node-side runtime artifacts', 'doneLabel' => 'Cleaned node-side runtime artifacts'],
            ],
            work: function () use ($selector, &$response): array {
                return $response = $this->removeAppForHuman($selector);
            },
            doneFooter: function () use ($selector, &$response): string {
                return $this->driftIsPresent($response)
                    ? "Project '{$selector}' removed with drift"
                    : "Project '{$selector}' removed";
            },
        );

        if (! $outcome->isCompleted()) {
            return self::FAILURE;
        }

        $this->renderRemovalNotes($response);

        return self::SUCCESS;
    }

    /**
     * @param  array<string, mixed>  $response
     */
    private function renderRemovalNotes(array $response): void
    {
        $messages = $this->driftMessages($response);

        if ($messages === []) {
            return;
        }

        $this->line('  Drift detected:');

        foreach ($messages as $message) {
            $this->line("  - {$message}");
            $this->line('  Run: orbit doctor --family=instance --restore');
        }
    }

    private function confirmRemoval(string $selector): bool|int
    {
        if ($this->wantsJson() || ! $this->input->isInteractive()) {
            return $this->failValidation('force', 'Use --force to remove this project.');
        }

        return confirm(
            label: "Remove project '{$selector}' and all owned artifacts? This cannot be undone.",
            default: false,
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function removeApp(string $selector): array
    {
        return $this->gatewayDelete($this->apiProjectPath($selector), [
            'destructive_consent' => true,
            'destructive_consent_source' => 'force',
        ]);
    }

    /**
     * Run the removal inside the progress tree, re-throwing gateway failures with
     * their operator-facing message so the failed footer renders the documented
     * prose rather than a JSON envelope.
     *
     * @return array<string, mixed>
     */
    private function removeAppForHuman(string $selector): array
    {
        try {
            return $this->removeApp($selector);
        } catch (GatewayApiException $exception) {
            throw new RuntimeException(
                $exception->gatewayErrorMessage() ?? $exception->getMessage(),
                previous: $exception,
            );
        }
    }

    /**
     * @param  array<string, mixed>  $response
     */
    private function driftIsPresent(array $response): bool
    {
        return $this->driftMessages($response) !== [];
    }

    /**
     * @param  array<string, mixed>  $response
     * @return list<string>
     */
    private function driftMessages(array $response): array
    {
        $warnings = $response['success']['meta']['warnings'] ?? null;

        if (! is_array($warnings)) {
            return [];
        }

        $messages = [];

        foreach ($warnings as $entry) {
            if (is_array($entry) && is_string($entry['message'] ?? null) && trim($entry['message']) !== '') {
                $messages[] = trim($entry['message']);
            }
        }

        return $messages;
    }
}
