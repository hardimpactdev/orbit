<?php

declare(strict_types=1);

namespace App\Commands\Process;

use App\Exceptions\GatewayApiException;

use function Laravel\Prompts\confirm;

final class ProcessRemoveCommand extends ProcessGatewayCommand
{
    #[\Override]
    protected $signature = 'process:remove
        {name? : Existing process name}
        {--app= : Parent app slug}
        {--force : Confirm destructive operation without prompting}
        {--json : Output JSON}';

    #[\Override]
    protected $description = 'Remove an app process definition.';

    public function handle(): int
    {
        $app = $this->appContext();
        $name = $this->stringArgument('name');

        if ($app === null) {
            return $this->failValidation('app', 'An app context is required.');
        }

        $validation = $this->validateProcessName($name)
            ?? $this->confirmRemoval((string) $name);

        if ($validation !== null) {
            return $validation;
        }

        try {
            $response = $this->gatewayDelete('/api/processes/'.rawurlencode((string) $name), [
                'app' => $app,
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
            return $this->failValidation('force', 'Use --force to remove this process.');
        }

        if (confirm(label: "Remove process '{$name}'?", default: false)) {
            return null;
        }

        return $this->renderFailure('validation_failed', 'Operation cancelled.', ['field' => 'force']);
    }
}
