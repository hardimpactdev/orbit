<?php

declare(strict_types=1);

namespace App\Commands\Process;

use App\Exceptions\GatewayApiException;

use function Laravel\Prompts\confirm;

final class ProcessAddCommand extends ProcessGatewayCommand
{
    #[\Override]
    protected $signature = 'process:add
        {name? : Process name}
        {process_command? : Command to run}
        {--node= : Owning node name}
        {--node-transport= : Node command transport preference (auto|agent-push|transitional-ssh-fallback)}
        {--app= : Parent app slug}
        {--workspace= : Workspace name}
        {--tool= : Tool capability this process uses}
        {--service= : Managed service identifier to materialize}
        {--service-version= : Managed service version selector}
        {--image= : Explicit Docker image override}
        {--restart-policy=never : Restart policy (never|on_failure|always)}
        {--crash-notification=none : Crash notification policy (none|agent_ide)}
        {--runtime= : Process runtime (docker|docker-swarm|systemd); defaults to docker for managed services and systemd for host commands}
        {--replace-container=* : Remove an explicitly named Docker container on the target node before adding a Docker managed service}
        {--force : Confirm destructive replacement-container cleanup without prompting}
        {--start : Redundant backward-compatible flag; processes start by default}
        {--no-start : Skip starting rendered runtime units after creation}
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
        $service = $this->stringOption('service');
        $version = $this->stringOption('service-version');
        $image = $this->stringOption('image');
        $replaceContainers = $this->replaceContainers();
        $noStart = $this->option('no-start') === true;
        $startExplicit = $this->option('start') === true;

        if ($node !== null && ($app !== null || $workspace !== null)) {
            return $this->failValidation(
                'context',
                'A node context cannot be combined with app or workspace context.',
                [
                    'node' => $node,
                    'app' => $app,
                    'workspace' => $workspace,
                ],
            );
        }

        if ($node === null && $app === null && $workspace === null) {
            return $this->failValidation('app', 'A node, app, or workspace context is required.');
        }

        $validation =
            $this->validateProcessName($name) ?? (
                $command === null && $service === null
                    ? $this->failValidation('command', 'The process command is required.')
                    : null
            ) ?? $this->validateRestartPolicy($restartPolicy) ?? $this->validateCrashNotification(
                $crashNotification,
            ) ?? $this->validateRuntime($runtime) ?? $this->validateAppWorkspaceCommandRuntime(
                $runtime,
                $node,
                $service,
            ) ?? $this->validateTool($tool) ?? $this->validateService($service) ?? (
                $service === null && $version !== null
                    ? $this->failValidation('version', 'Process service version requires --service.', [
                        'value' => $version,
                        'reason' => 'process_service_version_requires_service',
                    ]) : null
            ) ?? (
                $service === null && $image !== null
                    ? $this->failValidation('image', 'Process service image requires --service.', [
                        'value' => $image,
                        'reason' => 'process_service_image_requires_service',
                    ]) : null
            ) ?? (
                $image !== null && $runtime === 'systemd'
                    ? $this->failValidation('image', 'Process service image overrides require a Docker runtime.', [
                        'value' => $image,
                        'reason' => 'process_service_image_requires_docker_runtime',
                    ]) : null
            ) ?? (
                $service !== null && $node === null
                    ? $this->failValidation(
                        'service',
                        'Managed services are only valid for node-owned service processes.',
                        [
                            'value' => $service,
                            'reason' => 'process_service_requires_node_owned_process',
                        ],
                    ) : null
            ) ?? (
                $service !== null && $tool !== null
                    ? $this->failValidation('tool', 'Managed services do not use tool dependencies.', [
                        'value' => $tool,
                        'reason' => 'process_service_cannot_reference_tool',
                    ]) : null
            ) ?? $this->validateReplaceContainers(
                $replaceContainers,
                $node,
                $service,
                $runtime,
            ) ?? $this->confirmReplaceContainers($replaceContainers, (string) $name)
                ?? (
                    $noStart && $startExplicit
                        ? $this->failValidation('start', 'The start and no-start flags cannot be used together.', [
                            'reason' => 'start_and_no_start_conflict',
                        ]) : null
                );

        if ($validation !== null) {
            return $validation;
        }

        $start = ! $noStart;

        $payload = $this->filledQuery([
            'node' => $node,
            'app' => $app,
            'workspace' => $workspace,
            'name' => $name,
            'command' => $command,
            'restart_policy' => $restartPolicy,
            'crash_notification' => $crashNotification,
            'start' => $start,
            'runtime' => $runtime,
            'tool' => $tool,
            'service' => $service,
            'version' => $version,
            'image' => $image,
            'replace_containers' => $replaceContainers === [] ? null : $replaceContainers,
            'destructive_consent' => $replaceContainers === [] ? null : true,
            'destructive_consent_source' => $replaceContainers === [] ? null : $this->replaceContainerConsentSource(),
        ]);

        if ($this->wantsJson()) {
            try {
                $response = $this->gatewayPost('/api/processes', $payload);
            } catch (GatewayApiException $exception) {
                return $this->renderGatewayFailure($exception);
            }

            return $this->renderSuccess($response);
        }

        return $this->renderAddTree($payload, (string) $name, $this->contextLabel($node, $app, $workspace), $start);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function renderAddTree(array $payload, string $name, string $label, bool $start): int
    {
        $response = [];

        $phases = [
            ['label' => 'Validate process', 'doneLabel' => 'Validated process'],
        ];

        if (($payload['replace_containers'] ?? []) !== []) {
            $phases[] = ['label' => 'Remove replacement containers', 'doneLabel' => 'Removed replacement containers'];
        }

        $phases[] = ['label' => 'Create process configuration', 'doneLabel' => 'Created process configuration'];
        $phases[] = ['label' => 'Render runtime units', 'doneLabel' => 'Rendered runtime units'];

        if ($start) {
            $phases[] = ['label' => 'Start runtime units', 'doneLabel' => 'Started runtime units'];
        }

        $outcome = $this->runStepOperation(
            'Adding Process',
            $phases,
            work: function () use ($payload, &$response): array {
                return $response = $this->gatewayCallForHuman(
                    fn (): array => $this->gatewayPost('/api/processes', $payload),
                );
            },
            doneFooter: fn (): string => "Process '{$name}' added for {$label}",
        );

        if (! $outcome->isCompleted()) {
            return self::FAILURE;
        }

        $this->renderDriftNotes($response);

        return self::SUCCESS;
    }

    private function validateTool(?string $tool): ?int
    {
        if ($tool === null) {
            return null;
        }

        if (preg_match('/^[a-z0-9](?:[a-z0-9-]{0,62}[a-z0-9])?$/', $tool)) {
            return null;
        }

        return $this->failValidation(
            'tool',
            'The process tool must contain only lowercase letters, digits, and hyphens, cannot start or end with a hyphen, and may not exceed 64 characters.',
            [
                'value' => $tool,
            ],
        );
    }

    private function validateService(?string $service): ?int
    {
        if ($service === null) {
            return null;
        }

        if (preg_match('/^[a-z0-9](?:[a-z0-9-]{0,62}[a-z0-9])?$/', $service)) {
            return null;
        }

        return $this->failValidation(
            'service',
            'The managed service must contain only lowercase letters, digits, and hyphens, cannot start or end with a hyphen, and may not exceed 64 characters.',
            [
                'value' => $service,
            ],
        );
    }

    /**
     * @return list<string>
     */
    private function replaceContainers(): array
    {
        $raw = $this->option('replace-container');
        $values = is_array($raw) ? $raw : ($raw === null ? [] : [$raw]);
        $containers = [];

        foreach ($values as $value) {
            $containers[] = is_string($value) ? trim($value) : '';
        }

        return array_values(array_unique($containers));
    }

    /**
     * @param  list<string>  $replaceContainers
     */
    private function validateReplaceContainers(
        array $replaceContainers,
        ?string $node,
        ?string $service,
        ?string $runtime,
    ): ?int {
        if ($replaceContainers === []) {
            return null;
        }

        if ($node === null || $service === null || $runtime !== null && $runtime !== 'docker') {
            return $this->failValidation(
                'replace_containers',
                'Replacement containers are only supported for node-owned Docker managed services.',
                [
                    'reason' => 'replace_container_requires_node_docker_service',
                ],
            );
        }

        foreach ($replaceContainers as $container) {
            if ($this->isValidDockerContainerName($container)) {
                continue;
            }

            return $this->failValidation(
                'replace_containers',
                'Replacement container names must be valid Docker container names.',
                [
                    'value' => $container,
                ],
            );
        }

        return null;
    }

    /**
     * @param  list<string>  $replaceContainers
     */
    private function confirmReplaceContainers(array $replaceContainers, string $name): ?int
    {
        if ($replaceContainers === [] || $this->option('force') === true) {
            return null;
        }

        if ($this->wantsJson() || ! $this->input->isInteractive()) {
            return $this->failValidation('force', 'Use --force to remove replacement containers.', [
                'reason' => 'destructive_consent_required',
                'containers' => $replaceContainers,
            ]);
        }

        $containerList = implode(', ', $replaceContainers);

        if (confirm(
            label: "Remove Docker container(s) {$containerList} before adding process '{$name}'?",
            default: false,
        )) {
            return null;
        }

        return $this->renderFailure('validation_failed', 'Operation cancelled.', [
            'field' => 'force',
            'reason' => 'destructive_consent_required',
        ]);
    }

    private function replaceContainerConsentSource(): string
    {
        return $this->option('force') === true ? 'force' : 'prompt';
    }

    private function isValidDockerContainerName(string $container): bool
    {
        return preg_match('/^[A-Za-z0-9][A-Za-z0-9_.-]{0,127}$/', $container) === 1;
    }
}
