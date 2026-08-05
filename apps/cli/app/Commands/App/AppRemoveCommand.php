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
    protected $signature = 'app:remove
        {app? : App name or hostname}
        {--force : Confirm destructive operation without prompting}
        {--json : Output JSON}';

    #[\Override]
    protected $description = 'Remove an app and its owned artifacts.';

    public function handle(): int
    {
        $selector = $this->stringArgument('app');

        if ($selector === null) {
            return $this->failValidation('app', 'App name is required.');
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
            'Removing App',
            [
                ['label' => 'Validate removal', 'doneLabel' => 'Validated removal'],
                ['label' => 'Apply and verify app removal', 'doneLabel' => 'Applied and verified app removal'],
                ['label' => 'Remove app-owned proxy routes', 'doneLabel' => 'Removed app-owned proxy routes'],
                ['label' => 'Remove app-owned schedules', 'doneLabel' => 'Removed app-owned schedules'],
                ['label' => 'Remove app-owned workspaces', 'doneLabel' => 'Removed app-owned workspaces'],
                [
                    'label' => 'Stop and remove app processes',
                    'doneLabel' => 'Stopped and removed app processes',
                ],
                ['label' => 'Clean node-side runtime artifacts', 'doneLabel' => 'Cleaned node-side runtime artifacts'],
            ],
            work: function () use ($selector, &$response): array {
                return $response = $this->removeAppForHuman($selector);
            },
            doneFooter: function () use ($selector, &$response): string {
                return $this->driftIsPresent($response)
                    ? "App '{$selector}' removed with drift"
                    : "App '{$selector}' removed";
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
            return $this->failValidation('force', 'Use --force to remove this app.');
        }

        return confirm(
            label: "Remove app '{$selector}' and all owned artifacts? This cannot be undone.",
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
