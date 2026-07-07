<?php

declare(strict_types=1);

namespace App\Services\RemoteShell;

use App\Enums\Nodes\NodeRoleStatus;
use App\Models\Node;
use App\Services\RemoteShell\Exceptions\LocalExecutorCommandBuilderException;
use SensitiveParameter;

final readonly class LocalExecutorCommandBuilder
{
    private const string ORBIT_BINARY = '/usr/local/bin/orbit';

    private const string COMMAND_NAME_PATTERN = '/\Ainternal:[a-z0-9:_-]+\z/';

    private const string OPTION_KEY_PATTERN = '/\A[a-z][a-z0-9-]*\z/';

    private const array ALLOWED_COMMAND_ROLES = [
        'internal:executor:verify' => [
            'gateway',
            'vpn',
            'router',
            'app-dev',
            'app-prod',
            'database',
            'agent',
            'ingress',
        ],
        'internal:agent-acl:ensure' => ['agent'],
        'internal:agent-runtime:probe' => ['agent'],
        'internal:agent-user:ensure' => ['agent'],
        'internal:app-cache:clear' => ['app-dev', 'app-prod'],
        'internal:app-introspect:probe' => ['app-dev', 'app-prod'],
        'internal:app-runtime-configs:probe' => ['app-dev', 'app-prod'],
        'internal:app-runtime-container' => ['app-dev', 'app-prod'],
        'internal:app-runtime-containers:probe' => ['app-dev', 'app-prod'],
        'internal:app-runtime-extensions:probe' => ['app-dev', 'app-prod'],
        'internal:app-source:create' => ['app-dev', 'app-prod'],
        'internal:app-source-path:probe' => ['app-dev', 'app-prod'],
        'internal:app-security:repair' => ['app-dev', 'app-prod'],
        'internal:app-worker-readiness:probe' => ['app-dev', 'app-prod'],
        'internal:caddy-config' => ['gateway', 'router', 'app-dev', 'app-prod', 'agent', 'ingress'],
        'internal:codex-app-config' => ['agent'],
        'internal:doctor-self' => [
            'vpn',
            'router',
            'app-dev',
            'app-prod',
            'database',
            'agent',
            'ingress',
            'websocket',
            's3',
            'metrics',
            'analytics',
        ],
        'internal:env-file' => ['app-dev', 'app-prod', 'database'],
        'internal:firewall-rule' => [
            'gateway',
            'vpn',
            'router',
            'app-dev',
            'app-prod',
            'database',
            'agent',
            'ingress',
            'websocket',
            's3',
            'metrics',
            'analytics',
        ],
        'internal:firewall-rule:probe' => [
            'gateway',
            'vpn',
            'router',
            'app-dev',
            'app-prod',
            'database',
            'agent',
            'ingress',
            'websocket',
            's3',
            'metrics',
            'analytics',
        ],
        'internal:fleet-update:verify' => [
            'vpn',
            'router',
            'app-dev',
            'app-prod',
            'database',
            'agent',
            'ingress',
            'websocket',
            's3',
            'metrics',
            'analytics',
        ],
        'internal:fleet-update:install-cli' => [
            'gateway',
            'vpn',
            'router',
            'app-dev',
            'app-prod',
            'database',
            'agent',
            'ingress',
            'websocket',
            's3',
            'metrics',
            'analytics',
        ],
        'internal:gateway-runtime-backend:probe' => ['gateway'],
        'internal:managed-file' => [
            'vpn',
            'router',
            'app-dev',
            'app-prod',
            'database',
            'agent',
            'ingress',
            'websocket',
            's3',
            'metrics',
            'analytics',
        ],
        'internal:node-security-posture:probe' => [
            'gateway',
            'vpn',
            'router',
            'app-dev',
            'app-prod',
            'database',
            'agent',
            'ingress',
            'websocket',
            's3',
            'metrics',
            'analytics',
        ],
        'internal:wg-easy:state' => ['vpn'],
        'internal:database-add-user' => ['app-dev', 'app-prod', 'database'],
        'internal:database-query-local' => ['app-dev', 'app-prod', 'database'],
        'internal:process-docker-container' => ['app-dev', 'app-prod', 'database'],
        'internal:process-docker-swarm-service' => ['app-dev', 'app-prod', 'database', 'metrics'],
        'internal:process-logs' => [
            'gateway',
            'vpn',
            'router',
            'app-dev',
            'app-prod',
            'database',
            'agent',
            'ingress',
            'websocket',
            's3',
            'metrics',
            'analytics',
        ],
        'internal:process-systemd-service' => [
            'gateway',
            'vpn',
            'router',
            'app-dev',
            'app-prod',
            'database',
            'agent',
            'ingress',
            'websocket',
            's3',
            'metrics',
            'analytics',
        ],
        'internal:runtime-backend:probe' => [
            'vpn',
            'router',
            'app-dev',
            'app-prod',
            'database',
            'agent',
            'ingress',
            'websocket',
            's3',
            'metrics',
            'analytics',
        ],
        'internal:s3-runtime:probe' => ['s3'],
        'internal:secret-file' => [
            'vpn',
            'router',
            'app-dev',
            'app-prod',
            'database',
            'agent',
            'ingress',
            'websocket',
            's3',
            'metrics',
            'analytics',
        ],
        'internal:site-certificate:install' => ['app-dev', 'app-prod', 'websocket'],
        'internal:solo-upstream-request' => [
            'gateway',
            'vpn',
            'router',
            'app-dev',
            'app-prod',
            'database',
            'agent',
            'ingress',
            'websocket',
            's3',
            'metrics',
            'analytics',
        ],
        'internal:websocket-runtime' => ['websocket'],
        'internal:unattended-upgrades:apply' => [
            'vpn',
            'router',
            'app-dev',
            'app-prod',
            'database',
            'agent',
            'ingress',
            'websocket',
            's3',
            'metrics',
            'analytics',
        ],
        'internal:unattended-upgrades:probe' => [
            'vpn',
            'router',
            'app-dev',
            'app-prod',
            'database',
            'agent',
            'ingress',
            'websocket',
            's3',
            'metrics',
            'analytics',
        ],
        'internal:wireguard-endpoint:rotate' => [
            'vpn',
            'router',
            'app-dev',
            'app-prod',
            'database',
            'agent',
            'ingress',
            'websocket',
            's3',
            'metrics',
            'analytics',
        ],
        'internal:wireguard-interface-public-key:read' => [
            'gateway',
            'vpn',
            'router',
            'app-dev',
            'app-prod',
            'database',
            'agent',
            'ingress',
            'websocket',
            's3',
            'metrics',
            'analytics',
        ],
        'internal:wireguard-self-route' => [
            'gateway',
            'vpn',
            'router',
            'app-dev',
            'app-prod',
            'database',
            'agent',
            'ingress',
            'websocket',
            's3',
            'metrics',
            'analytics',
        ],
        'internal:workspace-adapter:lookup' => ['app-dev'],
        'internal:workspace-adapter:update' => ['app-dev'],
        'internal:workspace-source:create' => ['app-dev'],
    ];

    /**
     * @return array<string, list<string>>
     */
    public static function allowedCommandRoles(): array
    {
        return self::ALLOWED_COMMAND_ROLES;
    }

    /**
     * @param  array<int|string, mixed>  $arguments
     * @param  array<int|string, mixed>  $options
     */
    public function build(
        Node $targetNode,
        string $commandName,
        array $arguments,
        array $options,
        string $operationToken,
    ): string {
        return $this->compose(
            targetNode: $targetNode,
            commandName: $commandName,
            arguments: $arguments,
            options: $options,
            operationToken: $operationToken,
            redactOperationToken: false,
        );
    }

    /**
     * @param  array<int|string, mixed>  $arguments
     * @param  array<int|string, mixed>  $options
     * @return list<string>
     */
    public function buildArgv(
        Node $targetNode,
        string $commandName,
        array $arguments,
        array $options,
        string $operationToken,
    ): array {
        $this->ensureCommandNameIsValid($commandName);
        $this->ensureCommandIsAllowedForTarget($commandName, $targetNode);
        $this->ensureOperationTokenIsValid($operationToken);

        return [
            $commandName,
            ...$this->argumentValues($arguments),
            ...$this->optionValues($options),
            "--operation-token={$operationToken}",
            '--json',
        ];
    }

    /**
     * @param  array<int|string, mixed>  $arguments
     * @param  array<int|string, mixed>  $options
     */
    public function buildAuditLine(
        Node $targetNode,
        string $commandName,
        array $arguments,
        array $options,
        string $operationToken,
    ): string {
        return $this->compose(
            targetNode: $targetNode,
            commandName: $commandName,
            arguments: $arguments,
            options: $options,
            operationToken: $operationToken,
            redactOperationToken: true,
        );
    }

    /**
     * @param  array<int|string, mixed>  $arguments
     * @param  array<int|string, mixed>  $options
     */
    private function compose(
        Node $targetNode,
        string $commandName,
        array $arguments,
        array $options,
        string $operationToken,
        bool $redactOperationToken,
    ): string {
        $this->ensureCommandNameIsValid($commandName);
        $this->ensureCommandIsAllowedForTarget($commandName, $targetNode);
        $this->ensureOperationTokenIsValid($operationToken);

        $segments = [
            $this->orbitBinarySegment($targetNode),
            $commandName,
            ...array_map(escapeshellarg(...), $this->argumentValues($arguments)),
            ...array_map($this->escapeOptionValue(...), $this->optionValues($options)),
            '--operation-token='.($redactOperationToken ? '<redacted>' : escapeshellarg($operationToken)),
            '--json',
        ];

        return implode(' ', $segments);
    }

    private function orbitBinarySegment(Node $targetNode): string
    {
        $configuredBinary = config('orbit.local_executor_binary');
        $binary = is_string($configuredBinary)
        && trim($configuredBinary) !== ''
        && $targetNode->hasActiveRole('gateway')
            ? trim($configuredBinary)
            : self::ORBIT_BINARY;

        $this->ensureNoNullByte($binary, 'orbit binary');

        if ($binary === self::ORBIT_BINARY) {
            return self::ORBIT_BINARY;
        }

        return escapeshellarg($binary);
    }

    private function ensureCommandNameIsValid(string $commandName): void
    {
        $this->ensureNoNullByte($commandName, 'command name');

        if (preg_match(self::COMMAND_NAME_PATTERN, $commandName) === 1) {
            return;
        }

        throw LocalExecutorCommandBuilderException::invalidCommandName();
    }

    private function ensureCommandIsAllowedForTarget(string $commandName, Node $targetNode): void
    {
        $allowedRoles = self::ALLOWED_COMMAND_ROLES[$commandName] ?? null;

        if ($allowedRoles === null) {
            throw LocalExecutorCommandBuilderException::commandNotAllowed($commandName);
        }

        foreach ($allowedRoles as $role) {
            if ($this->nodeHasEligibleRole($targetNode, $role)) {
                return;
            }
        }

        throw LocalExecutorCommandBuilderException::commandNotAllowed($commandName);
    }

    private function nodeHasEligibleRole(Node $targetNode, string $role): bool
    {
        if ($targetNode->hasActiveRole($role)) {
            return true;
        }

        return $targetNode
            ->roleAssignments()
            ->where('role', $role)
            ->where('status', NodeRoleStatus::Pending->value)
            ->exists();
    }

    private function ensureOperationTokenIsValid(#[SensitiveParameter] string $operationToken): void
    {
        $this->ensureNoNullByte($operationToken, 'operation token');

        if (trim($operationToken) !== '') {
            return;
        }

        throw LocalExecutorCommandBuilderException::invalidOperationToken();
    }

    /**
     * @param  array<int|string, mixed>  $arguments
     * @return list<string>
     */
    private function argumentValues(array $arguments): array
    {
        $values = [];

        foreach ($arguments as $argument) {
            if (! is_scalar($argument)) {
                throw LocalExecutorCommandBuilderException::invalidArgument();
            }

            $value = $this->scalarToString($argument);
            $this->ensureNoNullByte($value, 'argument');

            $values[] = $value;
        }

        return $values;
    }

    /**
     * @param  array<int|string, mixed>  $options
     * @return list<string>
     */
    private function optionValues(array $options): array
    {
        $values = [];

        foreach ($options as $key => $value) {
            $optionKey = $this->validatedOptionKey($key);

            if (! is_scalar($value)) {
                throw LocalExecutorCommandBuilderException::invalidOptionValue($optionKey);
            }

            $optionValue = $this->scalarToString($value);
            $this->ensureNoNullByte($optionValue, 'option value');

            $values[] = "--{$optionKey}={$optionValue}";
        }

        return $values;
    }

    private function escapeOptionValue(string $option): string
    {
        [$key, $value] = explode('=', $option, limit: 2);

        return "{$key}=".escapeshellarg($value);
    }

    private function validatedOptionKey(int|string $key): string
    {
        if (! is_string($key)) {
            throw LocalExecutorCommandBuilderException::invalidOptionKey();
        }

        $this->ensureNoNullByte($key, 'option key');

        if (preg_match(self::OPTION_KEY_PATTERN, $key) === 1) {
            return $key;
        }

        throw LocalExecutorCommandBuilderException::invalidOptionKey();
    }

    private function scalarToString(mixed $value): string
    {
        if (is_bool($value)) {
            return $value ? '1' : '0';
        }

        if (is_int($value) || is_float($value) || is_string($value)) {
            return (string) $value;
        }

        throw LocalExecutorCommandBuilderException::invalidArgument();
    }

    private function ensureNoNullByte(string $value, string $field): void
    {
        if (! str_contains($value, "\0")) {
            return;
        }

        throw LocalExecutorCommandBuilderException::nullByte($field);
    }
}
