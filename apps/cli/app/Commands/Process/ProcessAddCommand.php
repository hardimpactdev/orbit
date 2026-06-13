<?php

declare(strict_types=1);

namespace App\Commands\Process;

use App\Exceptions\GatewayApiException;

final class ProcessAddCommand extends ProcessGatewayCommand
{
    #[\Override]
    protected $signature = 'process:add
        {name? : Process name}
        {process_command? : Command to run}
        {--node= : Owning node name}
        {--app= : Parent app slug}
        {--workspace= : Workspace name}
        {--tool= : Tool capability this process uses}
        {--definition= : Service process definition to materialize}
        {--definition-version= : Service process definition version or version family}
        {--restart-policy=never : Restart policy (never|on_failure|always)}
        {--crash-notification=none : Crash notification policy (none|agent_ide)}
        {--runtime= : Process runtime (docker|docker-swarm|systemd); defaults to docker for service definitions and systemd for host commands}
        {--start : Start rendered runtime units after creation}
        {--json : Output JSON}';

    #[\Override]
    protected $description = 'Add a process definition.';

    public function handle(): int
    {
        $node = $this->nodeContext();
        $app = $node === null ? $this->appContext() : $this->stringOption('app');
        $workspace = $this->workspaceContext();
        $name = $this->stringArgument('name');
        $command = $this->stringArgument('process_command');
        $restartPolicy = $this->stringOption('restart-policy') ?? 'never';
        $crashNotification = $this->stringOption('crash-notification') ?? 'none';
        $runtime = $this->stringOption('runtime');
        $tool = $this->stringOption('tool');
        $definition = $this->stringOption('definition');
        $version = $this->stringOption('definition-version');

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
            ?? ($command === null && $definition === null ? $this->failValidation('command', 'The process command is required.') : null)
            ?? $this->validateRestartPolicy($restartPolicy)
            ?? $this->validateCrashNotification($crashNotification)
            ?? $this->validateRuntime($runtime)
            ?? $this->validateAppWorkspaceCommandRuntime($runtime, $node, $definition)
            ?? $this->validateTool($tool)
            ?? $this->validateDefinition($definition)
            ?? ($definition === null && $version !== null ? $this->failValidation('definition_version', 'Process definition version requires --definition.', [
                'value' => $version,
                'reason' => 'process_definition_version_requires_definition',
            ]) : null)
            ?? ($definition !== null && $node === null ? $this->failValidation('definition', 'Process definitions are only valid for node-owned service processes.', [
                'value' => $definition,
                'reason' => 'process_definition_requires_node_owned_process',
            ]) : null)
            ?? ($definition !== null && $tool !== null ? $this->failValidation('tool', 'Service process definitions do not use tool dependencies.', [
                'value' => $tool,
                'reason' => 'process_definition_cannot_reference_tool',
            ]) : null);

        if ($validation !== null) {
            return $validation;
        }

        try {
            $response = $this->gatewayPost('/api/processes', $this->filledQuery([
                'node' => $node,
                'app' => $app,
                'workspace' => $workspace,
                'name' => $name,
                'command' => $command,
                'restart_policy' => $restartPolicy,
                'crash_notification' => $crashNotification,
                'start' => $this->option('start') === true,
                'runtime' => $runtime,
                'tool' => $tool,
                'definition' => $definition,
                'version' => $version,
            ]));
        } catch (GatewayApiException $exception) {
            return $this->renderGatewayFailure($exception);
        }

        return $this->renderSuccess($response);
    }

    private function validateTool(?string $tool): ?int
    {
        if ($tool === null) {
            return null;
        }

        if (preg_match('/^[a-z0-9](?:[a-z0-9-]{0,62}[a-z0-9])?$/', $tool)) {
            return null;
        }

        return $this->failValidation('tool', 'The process tool must contain only lowercase letters, digits, and hyphens, cannot start or end with a hyphen, and may not exceed 64 characters.', [
            'value' => $tool,
        ]);
    }

    private function validateDefinition(?string $definition): ?int
    {
        if ($definition === null) {
            return null;
        }

        if (preg_match('/^[a-z0-9](?:[a-z0-9-]{0,62}[a-z0-9])?$/', $definition)) {
            return null;
        }

        return $this->failValidation('definition', 'The process definition must contain only lowercase letters, digits, and hyphens, cannot start or end with a hyphen, and may not exceed 64 characters.', [
            'value' => $definition,
        ]);
    }
}
