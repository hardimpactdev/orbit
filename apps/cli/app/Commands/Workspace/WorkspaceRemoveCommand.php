<?php

declare(strict_types=1);

namespace App\Commands\Workspace;

use App\Commands\Concerns\WithStepTree;
use App\Exceptions\GatewayApiException;
use RuntimeException;

use function Laravel\Prompts\confirm;
use function Laravel\Prompts\text;

final class WorkspaceRemoveCommand extends WorkspaceGatewayCommand
{
    use WithStepTree;

    #[\Override]
    protected $signature = 'workspace:remove
        {name? : Workspace name}
        {--app= : Parent app slug}
        {--keep-files : Preserve workspace files on the app node}
        {--force : Confirm destructive operation without prompting}
        {--json : Output JSON}';

    #[\Override]
    protected $description = 'Remove a workspace and its owned artifacts.';

    public function handle(): int
    {
        $name = $this->resolveName();

        if ($name === null) {
            return $this->failValidation('name', 'Workspace name is required.');
        }

        $confirmation = $this->confirmRemoval($name);

        if ($confirmation !== null) {
            return $confirmation;
        }

        if ($this->wantsJson()) {
            try {
                $response = $this->removeWorkspace($name);
            } catch (GatewayApiException $exception) {
                return $this->renderGatewayFailure($exception);
            }

            return $this->renderSuccess($response);
        }

        return $this->renderRemovalTree($name);
    }

    private function renderRemovalTree(string $name): int
    {
        $response = [];

        $outcome = $this->runStepOperation(
            'Removing Workspace',
            [
                ['label' => 'Apply and verify workspace removal'],
                ['label' => 'Stopping traffic for workspace hostname'],
                ['label' => 'Stopping inherited processes'],
                ['label' => 'Running teardown steps'],
                ['label' => 'Cleaning workspace runtime container'],
                ['label' => 'Removing worktree'],
            ],
            work: function () use ($name, &$response): array {
                return $response = $this->removeWorkspaceForHuman($name);
            },
            doneFooter: function () use (&$response, $name): string {
                return $this->driftIsPresent($response)
                    ? "Workspace '{$name}' removed with drift"
                    : "Workspace '{$name}' removed";
            },
        );

        if (! $outcome->isCompleted()) {
            return self::FAILURE;
        }

        $this->renderRemovalNotes($response);

        return self::SUCCESS;
    }

    /**
     * @return array<string, mixed>
     */
    private function removeWorkspace(string $name): array
    {
        return $this->gatewayDelete($this->pathWithQuery(
            '/api/workspaces/'.rawurlencode($name),
            ['app' => $this->stringOption('app') ?? $this->appFromOrbitMarker()],
        ), [
            'keep_files' => $this->option('keep-files') === true,
            'destructive_consent' => true,
            'destructive_consent_source' => 'force',
        ]);
    }

    /**
     * Run the destructive call inside the progress tree, re-throwing gateway
     * failures with their operator-facing message so the failed step and footer
     * render the documented prose rather than a JSON envelope.
     *
     * @return array<string, mixed>
     */
    private function removeWorkspaceForHuman(string $name): array
    {
        try {
            return $this->removeWorkspace($name);
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
    private function renderRemovalNotes(array $response): void
    {
        $drift = $this->driftMessages($response);

        if ($drift === []) {
            return;
        }

        $this->line('  Drift detected:');

        foreach ($drift as $message) {
            $this->line("  - {$message}");
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

        foreach ($warnings as $warning) {
            if (! is_array($warning)) {
                continue;
            }

            $message = is_string($warning['message'] ?? null) ? trim($warning['message']) : '';

            if ($message === '') {
                continue;
            }

            $family = is_string($warning['family'] ?? null) && $warning['family'] !== ''
                ? $warning['family']
                : 'workspace';
            $nextCommand = is_string($warning['next_command'] ?? null) ? trim($warning['next_command']) : '';

            $messages[] = $nextCommand !== ''
                ? "{$family}: {$message} (run `{$nextCommand}`)"
                : "{$family}: {$message}";
        }

        return $messages;
    }

    private function resolveName(): ?string
    {
        $name = $this->stringArgument('name');

        if ($name !== null) {
            return $name;
        }

        if (! $this->isInteractiveInput()) {
            return null;
        }

        $name = trim(text(label: 'Workspace name', required: true));

        return $name !== '' ? $name : null;
    }

    private function confirmRemoval(string $name): ?int
    {
        if ($this->option('force') === true) {
            return null;
        }

        if ($this->wantsJson() || ! $this->input->isInteractive()) {
            return $this->failValidation('force', 'Use --force to remove this workspace.');
        }

        if (confirm(label: "Remove workspace '{$name}'?", default: false)) {
            return null;
        }

        return $this->renderFailure('validation_failed', 'Operation cancelled.', ['field' => 'force']);
    }

    private function isInteractiveInput(): bool
    {
        return ! $this->wantsJson() && $this->input->isInteractive();
    }
}
