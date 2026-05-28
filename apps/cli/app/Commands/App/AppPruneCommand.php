<?php

declare(strict_types=1);

namespace App\Commands\App;

use App\Exceptions\GatewayApiException;

use function Laravel\Prompts\confirm;

final class AppPruneCommand extends AppGatewayCommand
{
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

        try {
            $response = $this->gatewayPost('/api/apps/prune', [
                'app' => $selector,
                'dry_run' => $dryRun,
            ]);
        } catch (GatewayApiException $exception) {
            return $this->renderGatewayFailure($exception);
        }

        return $this->renderSuccess($response);
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
}
