<?php

declare(strict_types=1);

namespace App\Commands\App;

use App\Commands\Concerns\WithStepTree;
use App\Exceptions\GatewayApiException;

use function Laravel\Prompts\confirm;

final class AppPruneCommand extends AppGatewayCommand
{
    use WithStepTree;

    #[\Override]
    protected $signature = 'app:prune
        {app? : App name or hostname}
        {--dry-run : Preview stale workspaces without removing}
        {--force : Confirm destructive operation without prompting}
        {--json : Output JSON}';

    #[\Override]
    protected $description = 'Remove stale workspaces for an app.';

    public function handle(): int
    {
        $selector = $this->stringArgument('app');

        if ($selector === null) {
            return $this->failValidation('app', 'App is required.');
        }

        $dryRun = $this->option('dry-run') === true;

        if (! $dryRun && $this->option('force') !== true) {
            $confirmed = $this->confirmPrune($selector);

            if (is_int($confirmed)) {
                return $confirmed;
            }

            if (! $confirmed) {
                return $this->renderFailure('validation_failed', 'Operation cancelled.');
            }
        }

        if ($this->wantsJson()) {
            try {
                $response = $this->pruneWorkspaces($selector, $dryRun);
            } catch (GatewayApiException $exception) {
                return $this->renderGatewayFailure($exception);
            }

            return $this->renderSuccess($response);
        }

        return $this->renderPruneTree($selector, $dryRun);
    }

    private function renderPruneTree(string $selector, bool $dryRun): int
    {
        try {
            $response = $this->pruneWorkspaces($selector, $dryRun);
        } catch (GatewayApiException $exception) {
            return $this->renderGatewayFailure($exception);
        }

        $outcome = $this->runStepOperation(
            $dryRun ? 'Previewing App Workspace Prune' : 'Pruning App Workspaces',
            $this->phases($response, $dryRun),
            work: static fn (): bool => true,
            doneFooter: $dryRun
                ? 'Dry run complete. No side effects performed.'
                : "Stale workspaces pruned for '{$selector}'",
        );

        if (! $outcome->isCompleted()) {
            return self::FAILURE;
        }

        $this->renderPruneNotes($response);

        return self::SUCCESS;
    }

    /**
     * @param  array<string, mixed>  $response
     * @return list<array{label: string, doneLabel: string}>
     */
    private function phases(array $response, bool $dryRun): array
    {
        $verb = $dryRun ? 'Preview' : 'Remove';
        $doneVerb = $dryRun ? 'Previewed' : 'Removed';

        $phases = [[
            'label' => 'Query agent IDE adapters',
            'doneLabel' => 'Queried agent IDE adapters',
        ]];

        foreach ($this->workspaceNames($response) as $name) {
            $phases[] = [
                'label' => "{$verb} workspace `{$name}`",
                'doneLabel' => "{$doneVerb} workspace `{$name}`",
            ];
        }

        return $phases;
    }

    /**
     * @param  array<string, mixed>  $response
     */
    private function renderPruneNotes(array $response): void
    {
        foreach ($this->warningMessages($response) as $message) {
            $this->line("  {$message}");
        }
    }

    private function confirmPrune(string $selector): bool|int
    {
        if ($this->wantsJson() || ! $this->input->isInteractive()) {
            return $this->failValidation('force', 'Use --force to prune stale workspaces.');
        }

        return confirm(
            label: "Pruning will permanently remove all stale workspaces for '{$selector}'. Continue?",
            default: false,
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function pruneWorkspaces(string $selector, bool $dryRun): array
    {
        return $this->gatewayPost('/api/apps/prune', [
            'app' => $selector,
            'dry_run' => $dryRun,
        ]);
    }

    /**
     * @param  array<string, mixed>  $response
     * @return list<string>
     */
    private function workspaceNames(array $response): array
    {
        $workspaces = $this->successData($response)['stale_workspaces'] ?? null;

        if (! is_array($workspaces)) {
            return [];
        }

        $names = [];

        foreach ($workspaces as $workspace) {
            if (is_array($workspace) && is_string($workspace['name'] ?? null) && trim($workspace['name']) !== '') {
                $names[] = trim($workspace['name']);
            }
        }

        return $names;
    }

    /**
     * @param  array<string, mixed>  $response
     * @return list<string>
     */
    private function warningMessages(array $response): array
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
