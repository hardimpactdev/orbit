<?php

declare(strict_types=1);

namespace App\Commands\Process;

use App\Exceptions\GatewayApiException;

use function Laravel\Prompts\confirm;

/**
 * @mago-expect lint:too-many-methods
 */
final class ProcessAddCommand extends ProcessGatewayCommand
{
    #[\Override]
    protected $signature = 'process:add
        {name? : Process name}
        {process_command? : Command to run}
        {--node= : Owning node name}
        {--instance= : Instance selector}
        {--workspace= : Workspace name}
        {--label= : Human display label (defaults to process name)}
        {--tool= : Tool capability this process uses}
        {--service= : Managed service identifier to materialize}
        {--service-version= : Managed service version selector}
        {--database= : PostgreSQL database name}
        {--username= : PostgreSQL username}
        {--published-port= : Published host port for a single-port managed service}
        {--image= : Explicit Docker image override}
        {--bind=* : Publish host for node-owned Docker managed services (wireguard|loopback); repeatable}
        {--restart-policy=never : Restart policy (never|on_failure|always)}
        {--crash-notification=none : Crash notification policy (none)}
        {--runtime= : Process runtime (docker|docker-swarm|systemd|launchd); defaults to docker for managed services, systemd for Linux host commands, and launchd for macOS host commands}
        {--replace-container=* : Remove an explicitly named Docker container on the target node before adding a Docker managed service}
        {--force : Confirm destructive replacement-container cleanup without prompting}
        {--no-start : Skip starting rendered runtime units after creation}
        {--json : Output JSON}';

    #[\Override]
    protected $description = 'Add a process definition.';

    public function handle(): int
    {
        $node = $this->nodeContext();
        $app = $node === null ? $this->appContext() : $this->stringOption('instance');
        $workspace = $this->workspaceContext();
        $name = $this->stringArgument('name');
        $label = $this->resolveProcessLabelOption();

        if (is_int($label)) {
            return $label;
        }

        $command = $this->stringArgument('process_command');
        $restartPolicy = $this->stringOption('restart-policy') ?? 'never';
        $crashNotification = $this->stringOption('crash-notification') ?? 'none';
        $runtime = $this->stringOption('runtime');
        $tool = $this->stringOption('tool');
        $service = $this->stringOption('service');
        $version = $this->stringOption('service-version');
        $image = $this->stringOption('image');
        $database = $this->stringOption('database');
        $username = $this->stringOption('username');
        $publishedPort = $this->stringOption('published-port');
        $binds = ProcessBindOption::fromOption($this->option('bind'));
        $replaceContainers = $this->replaceContainers();
        $noStart = $this->option('no-start') === true;

        if ($node !== null && ($app !== null || $workspace !== null)) {
            return $this->failValidation(
                'context',
                'A node context cannot be combined with instance or workspace context.',
                [
                    'node' => $node,
                    'instance' => $app,
                    'workspace' => $workspace,
                ],
            );
        }

        if ($node === null && $app === null && $workspace === null) {
            return $this->failValidation('instance', 'A node, instance, or workspace context is required.');
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
            ) ?? $this->validatePostgresServiceOptions(
                $service,
                $database,
                $username,
                $publishedPort,
            ) ?? $this->failBindValidation(
                ProcessBindOption::validate($binds, $node, $service, $runtime),
            ) ?? $this->validateReplaceContainers(
                $replaceContainers,
                $node,
                $service,
                $runtime,
            ) ?? $this->confirmReplaceContainers($replaceContainers, (string) $name);

        if ($validation !== null) {
            return $validation;
        }

        $start = ! $noStart;
        $serviceOptions = match (true) {
            $service === 'postgres' => [
                'database' => $database,
                'username' => $username,
                'published_port' => (int) $publishedPort,
            ],
            $service !== null && $publishedPort !== null => ['published_port' => (int) $publishedPort],
            default => null,
        };
        $normalizedBinds = $binds === [] ? null : ProcessBindOption::normalize($binds);

        $payload = $this->filledQuery([
            'node' => $node,
            'instance' => $app,
            'workspace' => $workspace,
            'name' => $name,
            'label' => $label,
            'command' => $command,
            'restart_policy' => $restartPolicy,
            'crash_notification' => $crashNotification,
            'start' => $start,
            'runtime' => $runtime,
            'tool' => $tool,
            'service' => $service,
            'version' => $version,
            'image' => $image,
            'service_options' => $serviceOptions,
            'binds' => $normalizedBinds,
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

    private function validatePostgresServiceOptions(
        ?string $service,
        ?string $database,
        ?string $username,
        ?string $publishedPort,
    ): ?int {
        if ($service !== 'postgres') {
            if ($database !== null || $username !== null) {
                return $this->failValidation(
                    'service_options',
                    'PostgreSQL identifier options require --service=postgres.',
                    ['reason' => 'process_service_options_unsupported'],
                );
            }

            if ($publishedPort === null) {
                return null;
            }

            if ($service === null) {
                return $this->failValidation(
                    'service_options',
                    'The --published-port option requires a managed --service.',
                    ['reason' => 'process_service_options_unsupported'],
                );
            }

            return $this->validatePublishedPort($publishedPort);
        }

        if ($database === null) {
            return $this->failValidation('database', 'PostgreSQL requires --database.', ['reason' => 'required']);
        }

        if (preg_match('/^[a-z_][a-z0-9_]{0,62}$/', $database) !== 1) {
            return $this->failValidation('database', 'PostgreSQL database is not a valid identifier.', [
                'value' => $database,
                'reason' => 'invalid_postgres_identifier',
            ]);
        }

        if ($username === null) {
            return $this->failValidation('username', 'PostgreSQL requires --username.', ['reason' => 'required']);
        }

        if (preg_match('/^[a-z_][a-z0-9_]{0,62}$/', $username) !== 1) {
            return $this->failValidation('username', 'PostgreSQL username is not a valid identifier.', [
                'value' => $username,
                'reason' => 'invalid_postgres_identifier',
            ]);
        }

        if ($publishedPort === null) {
            return $this->failValidation('published_port', 'PostgreSQL requires --published-port.', [
                'reason' => 'required',
            ]);
        }

        return $this->validatePublishedPort($publishedPort);
    }

    private function validatePublishedPort(string $publishedPort): ?int
    {
        if (preg_match('/^\d+$/', $publishedPort) !== 1 || (int) $publishedPort < 1 || (int) $publishedPort > 65535) {
            return $this->failValidation('published_port', 'Published port must be between 1 and 65535.', [
                'value' => $publishedPort,
                'reason' => 'out_of_range',
            ]);
        }

        return null;
    }

    private function failBindValidation(?ProcessBindValidationFailure $failure): ?int
    {
        if ($failure === null) {
            return null;
        }

        return $this->failValidation($failure->field, $failure->message, $failure->meta);
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
