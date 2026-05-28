<?php

declare(strict_types=1);

namespace App\Commands\App;

use App\Exceptions\GatewayApiException;

use function Laravel\Prompts\confirm;

final class AppRemoveCommand extends AppGatewayCommand
{
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

        try {
            $response = $this->gatewayDelete($this->apiAppPath($selector), [
                'destructive_consent' => true,
                'destructive_consent_source' => 'force',
            ]);
        } catch (GatewayApiException $exception) {
            return $this->renderGatewayFailure($exception);
        }

        return $this->renderSuccess($response);
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
}
