<?php

declare(strict_types=1);

namespace App\Commands\Process;

use App\Exceptions\GatewayApiException;

final class ProcessEditCommand extends ProcessGatewayCommand
{
    #[\Override]
    protected $signature = 'process:edit
        {name? : Existing process name}
        {--node= : Owning node name}
        {--app= : Parent app slug}
        {--workspace= : Workspace name}
        {--command= : New command}
        {--restart-policy= : Restart policy (never|on_failure|always)}
        {--crash-notification= : Crash notification policy (none|agent_ide)}
        {--runtime= : Process runtime (docker|docker-swarm|systemd)}
        {--restart : Restart affected runtime units after update}
        {--json : Output JSON}';

    #[\Override]
    protected $description = 'Edit an app process definition.';

    public function handle(): int
    {
        $node = $this->nodeContext();
        $app = $node === null ? $this->appContext() : $this->stringOption('app');
        $workspace = $this->workspaceContext();
        $name = $this->stringArgument('name');
        $command = $this->stringOption('command');
        $restartPolicy = $this->stringOption('restart-policy');
        $crashNotification = $this->stringOption('crash-notification');
        $runtime = $this->stringOption('runtime');

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
            ?? $this->validateEditableFields($command, $restartPolicy, $crashNotification, $runtime)
            ?? $this->validateRestartPolicy($restartPolicy)
            ?? $this->validateCrashNotification($crashNotification)
            ?? $this->validateRuntime($runtime)
            ?? $this->validateAppWorkspaceCommandRuntime($runtime, $node);

        if ($validation !== null) {
            return $validation;
        }

        $path = '/api/processes/'.rawurlencode((string) $name);
        $payload = $this->filledQuery([
            'node' => $node,
            'app' => $app,
            'workspace' => $workspace,
            'command' => $command,
            'restart_policy' => $restartPolicy,
            'crash_notification' => $crashNotification,
            'runtime' => $runtime,
            'restart' => $this->option('restart') === true,
        ]);

        if ($this->wantsJson()) {
            try {
                $response = $this->gatewayPatch($path, $payload);
            } catch (GatewayApiException $exception) {
                return $this->renderGatewayFailure($exception);
            }

            return $this->renderSuccess($response);
        }

        return $this->renderEditTree($path, $payload, (string) $name, $this->contextLabel($node, $app, $workspace));
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function renderEditTree(string $path, array $payload, string $name, string $label): int
    {
        $response = [];

        $phases = [
            ['label' => 'Validate process', 'doneLabel' => 'Validated process'],
            ['label' => 'Apply and verify process change', 'doneLabel' => 'Applied and verified process change'],
            ['label' => 'Render runtime units', 'doneLabel' => 'Rendered runtime units'],
        ];

        if ($this->option('restart') === true) {
            $phases[] = ['label' => 'Restart runtime units', 'doneLabel' => 'Restarted runtime units'];
        }

        $outcome = $this->runStepOperation(
            'Editing Process',
            $phases,
            work: function () use ($path, $payload, &$response): array {
                return $response = $this->gatewayCallForHuman(fn (): array => $this->gatewayPatch($path, $payload));
            },
            doneFooter: fn (): string => "Process '{$name}' updated for {$label}",
        );

        if (! $outcome->isCompleted()) {
            return self::FAILURE;
        }

        $this->renderDriftNotes($response);

        return self::SUCCESS;
    }

    private function validateEditableFields(?string $command, ?string $restartPolicy, ?string $crashNotification, ?string $runtime): ?int
    {
        if ($command !== null || $restartPolicy !== null || $crashNotification !== null || $runtime !== null) {
            return null;
        }

        return $this->failValidation('editable_fields', 'At least one editable field is required.');
    }
}
