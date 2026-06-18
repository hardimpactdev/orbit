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
        {--node= : Owning node name}
        {--app= : Parent app slug}
        {--workspace= : Workspace name}
        {--force : Confirm destructive operation without prompting}
        {--json : Output JSON}';

    #[\Override]
    protected $description = 'Remove an app process definition.';

    public function handle(): int
    {
        $node = $this->nodeContext();
        $app = $node === null ? $this->appContext() : $this->stringOption('app');
        $workspace = $this->workspaceContext();
        $name = $this->stringArgument('name');

        if ($node !== null && ($app !== null || $workspace !== null)) {
            return $this->failValidation('context', 'A node context cannot be combined with app or workspace context.', [
                'node' => $node,
                'app' => $app,
                'workspace' => $workspace,
            ]);
        }

        if ($node === null && $app === null && $workspace === null) {
            return $this->failValidation('app', 'A node, app, or workspace context is required.');
        }

        $validation = $this->validateProcessName($name)
            ?? $this->confirmRemoval((string) $name);

        if ($validation !== null) {
            return $validation;
        }

        $path = '/api/processes/'.rawurlencode((string) $name);
        $payload = $this->filledQuery([
            'node' => $node,
            'app' => $app,
            'workspace' => $workspace,
            'destructive_consent' => true,
            'destructive_consent_source' => 'force',
        ]);

        if ($this->wantsJson()) {
            try {
                $response = $this->gatewayDelete($path, $payload);
            } catch (GatewayApiException $exception) {
                return $this->renderGatewayFailure($exception);
            }

            return $this->renderSuccess($response);
        }

        return $this->renderRemoveTree($path, $payload, (string) $name, $this->contextLabel($node, $app, $workspace));
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function renderRemoveTree(string $path, array $payload, string $name, string $label): int
    {
        $response = [];

        $outcome = $this->runStepOperation(
            'Removing Process',
            [
                ['label' => 'Validate process', 'doneLabel' => 'Validated process'],
                ['label' => 'Remove runtime units', 'doneLabel' => 'Removed runtime units'],
                ['label' => 'Apply and verify process removal', 'doneLabel' => 'Applied and verified process removal'],
            ],
            work: function () use ($path, $payload, &$response): array {
                return $response = $this->gatewayCallForHuman(fn (): array => $this->gatewayDelete($path, $payload));
            },
            doneFooter: fn (): string => "Process '{$name}' removed from {$label}",
        );

        if (! $outcome->isCompleted()) {
            return self::FAILURE;
        }

        $this->renderDriftNotes($response);

        return self::SUCCESS;
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
