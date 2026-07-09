<?php

declare(strict_types=1);

namespace App\Services\RemoteShell;

use App\Enums\Nodes\NodeRoleName;
use App\Enums\Nodes\NodeRoleStatus;
use App\Models\Node;
use App\Services\Nodes\NodeHostPaths;
use App\Services\RemoteShell\Exceptions\LocalExecutorCommandBuilderException;
use Orbit\Core\Enums\InternalCommand;
use SensitiveParameter;

final readonly class LocalExecutorCommandBuilder implements LocalExecutorCommandComposer
{
    private const string ORBIT_BINARY = '/usr/local/bin/orbit';

    private const string COMMAND_NAME_PATTERN = '/\Ainternal:[a-z0-9:_-]+\z/';

    private const string OPTION_KEY_PATTERN = '/\A[a-z][a-z0-9-]*\z/';

    private const array ALLOWED_COMMAND_ROLES = [
        InternalCommand::ExecutorVerify->value => [
            NodeRoleName::Gateway,
            NodeRoleName::Vpn,
            NodeRoleName::Router,
            NodeRoleName::AppDevelopment,
            NodeRoleName::AppProduction,
            NodeRoleName::Database,
            NodeRoleName::Agent,
            NodeRoleName::Ingress,
        ],
        InternalCommand::AgentAclEnsure->value => [NodeRoleName::Agent],
        InternalCommand::AgentRuntimeProbe->value => [NodeRoleName::Agent],
        InternalCommand::AgentUserEnsure->value => [NodeRoleName::Agent],
        InternalCommand::AppCacheClear->value => [NodeRoleName::AppDevelopment, NodeRoleName::AppProduction],
        InternalCommand::AppIntrospectProbe->value => [NodeRoleName::AppDevelopment, NodeRoleName::AppProduction],
        InternalCommand::AppRuntimeConfigsProbe->value => [NodeRoleName::AppDevelopment, NodeRoleName::AppProduction],
        InternalCommand::AppRuntimeContainer->value => [NodeRoleName::AppDevelopment, NodeRoleName::AppProduction],
        InternalCommand::AppRuntimeContainersProbe->value => [
            NodeRoleName::AppDevelopment,
            NodeRoleName::AppProduction,
        ],
        InternalCommand::AppRuntimeExtensionsProbe->value => [
            NodeRoleName::AppDevelopment,
            NodeRoleName::AppProduction,
        ],
        InternalCommand::AppSourceCreate->value => [NodeRoleName::AppDevelopment, NodeRoleName::AppProduction],
        InternalCommand::AppSourcePathProbe->value => [NodeRoleName::AppDevelopment, NodeRoleName::AppProduction],
        InternalCommand::AppSecurityRepair->value => [NodeRoleName::AppDevelopment, NodeRoleName::AppProduction],
        InternalCommand::AppSetupStep->value => [NodeRoleName::AppDevelopment, NodeRoleName::AppProduction],
        InternalCommand::AppWorkerReadinessProbe->value => [NodeRoleName::AppDevelopment, NodeRoleName::AppProduction],
        InternalCommand::CaddyConfig->value => [
            NodeRoleName::Gateway,
            NodeRoleName::Router,
            NodeRoleName::AppDevelopment,
            NodeRoleName::AppProduction,
            NodeRoleName::Agent,
            NodeRoleName::Ingress,
        ],
        InternalCommand::CodexAppConfig->value => [NodeRoleName::Agent],
        InternalCommand::DoctorSelf->value => [
            NodeRoleName::Vpn,
            NodeRoleName::Router,
            NodeRoleName::AppDevelopment,
            NodeRoleName::AppProduction,
            NodeRoleName::Database,
            NodeRoleName::Agent,
            NodeRoleName::Ingress,
            NodeRoleName::WebSocket,
            NodeRoleName::S3,
            NodeRoleName::Metrics,
            NodeRoleName::Analytics,
        ],
        InternalCommand::EnvFile->value => [
            NodeRoleName::AppDevelopment,
            NodeRoleName::AppProduction,
            NodeRoleName::Database,
        ],
        InternalCommand::FirewallRule->value => [
            NodeRoleName::Gateway,
            NodeRoleName::Vpn,
            NodeRoleName::Router,
            NodeRoleName::AppDevelopment,
            NodeRoleName::AppProduction,
            NodeRoleName::Database,
            NodeRoleName::Agent,
            NodeRoleName::Ingress,
            NodeRoleName::WebSocket,
            NodeRoleName::S3,
            NodeRoleName::Metrics,
            NodeRoleName::Analytics,
        ],
        InternalCommand::FirewallRuleProbe->value => [
            NodeRoleName::Gateway,
            NodeRoleName::Vpn,
            NodeRoleName::Router,
            NodeRoleName::AppDevelopment,
            NodeRoleName::AppProduction,
            NodeRoleName::Database,
            NodeRoleName::Agent,
            NodeRoleName::Ingress,
            NodeRoleName::WebSocket,
            NodeRoleName::S3,
            NodeRoleName::Metrics,
            NodeRoleName::Analytics,
        ],
        InternalCommand::FleetUpdateVerify->value => [
            NodeRoleName::Gateway,
            NodeRoleName::Vpn,
            NodeRoleName::Router,
            NodeRoleName::AppDevelopment,
            NodeRoleName::AppProduction,
            NodeRoleName::Database,
            NodeRoleName::Agent,
            NodeRoleName::Ingress,
            NodeRoleName::WebSocket,
            NodeRoleName::S3,
            NodeRoleName::Metrics,
            NodeRoleName::Analytics,
        ],
        InternalCommand::FleetUpdateInstallCli->value => [
            NodeRoleName::Gateway,
            NodeRoleName::Vpn,
            NodeRoleName::Router,
            NodeRoleName::AppDevelopment,
            NodeRoleName::AppProduction,
            NodeRoleName::Database,
            NodeRoleName::Agent,
            NodeRoleName::Ingress,
            NodeRoleName::WebSocket,
            NodeRoleName::S3,
            NodeRoleName::Metrics,
            NodeRoleName::Analytics,
        ],
        InternalCommand::GatewayRuntimeBackendProbe->value => [NodeRoleName::Gateway],
        InternalCommand::ManagedFile->value => [
            NodeRoleName::Vpn,
            NodeRoleName::Router,
            NodeRoleName::AppDevelopment,
            NodeRoleName::AppProduction,
            NodeRoleName::Database,
            NodeRoleName::Agent,
            NodeRoleName::Ingress,
            NodeRoleName::WebSocket,
            NodeRoleName::S3,
            NodeRoleName::Metrics,
            NodeRoleName::Analytics,
        ],
        InternalCommand::NodeSecurityPostureProbe->value => [
            NodeRoleName::Gateway,
            NodeRoleName::Vpn,
            NodeRoleName::Router,
            NodeRoleName::AppDevelopment,
            NodeRoleName::AppProduction,
            NodeRoleName::Database,
            NodeRoleName::Agent,
            NodeRoleName::Ingress,
            NodeRoleName::WebSocket,
            NodeRoleName::S3,
            NodeRoleName::Metrics,
            NodeRoleName::Analytics,
        ],
        InternalCommand::WgEasyState->value => [NodeRoleName::Vpn],
        InternalCommand::DatabaseAddUser->value => [
            NodeRoleName::AppDevelopment,
            NodeRoleName::AppProduction,
            NodeRoleName::Database,
        ],
        InternalCommand::DatabaseQueryLocal->value => [
            NodeRoleName::AppDevelopment,
            NodeRoleName::AppProduction,
            NodeRoleName::Database,
        ],
        InternalCommand::ProcessDockerContainer->value => [
            NodeRoleName::AppDevelopment,
            NodeRoleName::AppProduction,
            NodeRoleName::Database,
        ],
        InternalCommand::ProcessDockerSwarmService->value => [
            NodeRoleName::AppDevelopment,
            NodeRoleName::AppProduction,
            NodeRoleName::Database,
            NodeRoleName::Metrics,
        ],
        InternalCommand::ProcessLogs->value => [
            NodeRoleName::Gateway,
            NodeRoleName::Vpn,
            NodeRoleName::Router,
            NodeRoleName::AppDevelopment,
            NodeRoleName::AppProduction,
            NodeRoleName::Database,
            NodeRoleName::Agent,
            NodeRoleName::Ingress,
            NodeRoleName::WebSocket,
            NodeRoleName::S3,
            NodeRoleName::Metrics,
            NodeRoleName::Analytics,
        ],
        InternalCommand::ProcessSystemdService->value => [
            NodeRoleName::Gateway,
            NodeRoleName::Vpn,
            NodeRoleName::Router,
            NodeRoleName::AppDevelopment,
            NodeRoleName::AppProduction,
            NodeRoleName::Database,
            NodeRoleName::Agent,
            NodeRoleName::Ingress,
            NodeRoleName::WebSocket,
            NodeRoleName::S3,
            NodeRoleName::Metrics,
            NodeRoleName::Analytics,
        ],
        InternalCommand::ProcessLaunchdService->value => [
            NodeRoleName::Gateway,
            NodeRoleName::Vpn,
            NodeRoleName::Router,
            NodeRoleName::AppDevelopment,
            NodeRoleName::AppProduction,
            NodeRoleName::Database,
            NodeRoleName::Agent,
            NodeRoleName::Ingress,
            NodeRoleName::WebSocket,
            NodeRoleName::S3,
            NodeRoleName::Metrics,
            NodeRoleName::Analytics,
        ],
        InternalCommand::RuntimeBackendProbe->value => [
            NodeRoleName::Vpn,
            NodeRoleName::Router,
            NodeRoleName::AppDevelopment,
            NodeRoleName::AppProduction,
            NodeRoleName::Database,
            NodeRoleName::Agent,
            NodeRoleName::Ingress,
            NodeRoleName::WebSocket,
            NodeRoleName::S3,
            NodeRoleName::Metrics,
            NodeRoleName::Analytics,
        ],
        InternalCommand::S3RuntimeProbe->value => [NodeRoleName::S3],
        InternalCommand::ScheduleRun->value => [
            NodeRoleName::Gateway,
            NodeRoleName::Vpn,
            NodeRoleName::Router,
            NodeRoleName::AppDevelopment,
            NodeRoleName::AppProduction,
            NodeRoleName::Database,
            NodeRoleName::Agent,
            NodeRoleName::Ingress,
            NodeRoleName::WebSocket,
            NodeRoleName::S3,
            NodeRoleName::Metrics,
            NodeRoleName::Analytics,
        ],
        InternalCommand::ToolRunScript->value => [
            NodeRoleName::Vpn,
            NodeRoleName::Router,
            NodeRoleName::AppDevelopment,
            NodeRoleName::AppProduction,
            NodeRoleName::Database,
            NodeRoleName::Agent,
            NodeRoleName::Ingress,
            NodeRoleName::WebSocket,
            NodeRoleName::S3,
            NodeRoleName::Metrics,
            NodeRoleName::Analytics,
        ],
        InternalCommand::SecretFile->value => [
            NodeRoleName::Vpn,
            NodeRoleName::Router,
            NodeRoleName::AppDevelopment,
            NodeRoleName::AppProduction,
            NodeRoleName::Database,
            NodeRoleName::Agent,
            NodeRoleName::Ingress,
            NodeRoleName::WebSocket,
            NodeRoleName::S3,
            NodeRoleName::Metrics,
            NodeRoleName::Analytics,
        ],
        InternalCommand::SiteCertificateInstall->value => [
            NodeRoleName::AppDevelopment,
            NodeRoleName::AppProduction,
            NodeRoleName::WebSocket,
        ],
        InternalCommand::SoloUpstreamRequest->value => [
            NodeRoleName::Gateway,
            NodeRoleName::Vpn,
            NodeRoleName::Router,
            NodeRoleName::AppDevelopment,
            NodeRoleName::AppProduction,
            NodeRoleName::Database,
            NodeRoleName::Agent,
            NodeRoleName::Ingress,
            NodeRoleName::WebSocket,
            NodeRoleName::S3,
            NodeRoleName::Metrics,
            NodeRoleName::Analytics,
        ],
        InternalCommand::WebsocketRuntime->value => [NodeRoleName::WebSocket],
        InternalCommand::UnattendedUpgradesApply->value => [
            NodeRoleName::Vpn,
            NodeRoleName::Router,
            NodeRoleName::AppDevelopment,
            NodeRoleName::AppProduction,
            NodeRoleName::Database,
            NodeRoleName::Agent,
            NodeRoleName::Ingress,
            NodeRoleName::WebSocket,
            NodeRoleName::S3,
            NodeRoleName::Metrics,
            NodeRoleName::Analytics,
        ],
        InternalCommand::UnattendedUpgradesProbe->value => [
            NodeRoleName::Vpn,
            NodeRoleName::Router,
            NodeRoleName::AppDevelopment,
            NodeRoleName::AppProduction,
            NodeRoleName::Database,
            NodeRoleName::Agent,
            NodeRoleName::Ingress,
            NodeRoleName::WebSocket,
            NodeRoleName::S3,
            NodeRoleName::Metrics,
            NodeRoleName::Analytics,
        ],
        InternalCommand::WireguardEndpointRotate->value => [
            NodeRoleName::Vpn,
            NodeRoleName::Router,
            NodeRoleName::AppDevelopment,
            NodeRoleName::AppProduction,
            NodeRoleName::Database,
            NodeRoleName::Agent,
            NodeRoleName::Ingress,
            NodeRoleName::WebSocket,
            NodeRoleName::S3,
            NodeRoleName::Metrics,
            NodeRoleName::Analytics,
        ],
        InternalCommand::WireguardInterfacePublicKeyRead->value => [
            NodeRoleName::Gateway,
            NodeRoleName::Vpn,
            NodeRoleName::Router,
            NodeRoleName::AppDevelopment,
            NodeRoleName::AppProduction,
            NodeRoleName::Database,
            NodeRoleName::Agent,
            NodeRoleName::Ingress,
            NodeRoleName::WebSocket,
            NodeRoleName::S3,
            NodeRoleName::Metrics,
            NodeRoleName::Analytics,
        ],
        InternalCommand::WireguardSelfRoute->value => [
            NodeRoleName::Gateway,
            NodeRoleName::Vpn,
            NodeRoleName::Router,
            NodeRoleName::AppDevelopment,
            NodeRoleName::AppProduction,
            NodeRoleName::Database,
            NodeRoleName::Agent,
            NodeRoleName::Ingress,
            NodeRoleName::WebSocket,
            NodeRoleName::S3,
            NodeRoleName::Metrics,
            NodeRoleName::Analytics,
        ],
        InternalCommand::WorkspaceAdapterLookup->value => [NodeRoleName::AppDevelopment],
        InternalCommand::WorkspaceAdapterUpdate->value => [NodeRoleName::AppDevelopment],
        InternalCommand::WorkspaceSetupStep->value => [NodeRoleName::AppDevelopment, NodeRoleName::AppProduction],
        InternalCommand::WorkspaceSourceCreate->value => [NodeRoleName::AppDevelopment],
    ];

    /**
     * @return array<string, list<string>>
     */
    public static function allowedCommandRoles(): array
    {
        // Public shape preserved as array<string, list<string>>.
        // Source const uses InternalCommand::Case->value strings as keys and NodeRoleName objects as values.
        /** @var array<string, list<string>> $result */
        $result = [];

        foreach (self::ALLOWED_COMMAND_ROLES as $commandValue => $roles) {
            $result[(string) $commandValue] = array_map(
                static fn (NodeRoleName $role): string => $role->value,
                $roles,
            );
        }

        return $result;
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
        $binary = $this->orbitBinaryPath($targetNode, $configuredBinary);

        $this->ensureNoNullByte($binary, 'orbit binary');

        if ($binary === self::ORBIT_BINARY) {
            return self::ORBIT_BINARY;
        }

        return escapeshellarg($binary);
    }

    private function orbitBinaryPath(Node $targetNode, mixed $configuredBinary): string
    {
        if (
            is_string($configuredBinary)
            && trim($configuredBinary) !== ''
            && $targetNode->hasActiveRole('gateway')
        ) {
            return trim($configuredBinary);
        }

        if (NodeHostPaths::isMacosPlatform($targetNode->platform)) {
            return NodeHostPaths::homeDirectoryFor($targetNode->platform, $targetNode->user).'/.local/bin/orbit';
        }

        return self::ORBIT_BINARY;
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
        $command = InternalCommand::tryFrom($commandName);
        if ($command === null) {
            throw LocalExecutorCommandBuilderException::commandNotAllowed($commandName);
        }

        $allowedRoles = self::ALLOWED_COMMAND_ROLES[$command->value] ?? null;

        if ($allowedRoles === null) {
            throw LocalExecutorCommandBuilderException::commandNotAllowed($commandName);
        }

        foreach ($allowedRoles as $role) {
            if ($this->nodeHasEligibleRole($targetNode, $role->value)) {
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
