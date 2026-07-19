<?php

declare(strict_types=1);

namespace App\Services\Node;

use App\Exceptions\NodeWriteInputException;
use Orbit\Core\Nodes\NodeTld;

class NodeNewPayloadBuilder
{
    private const array TEMPLATES = [
        'operator',
        'app-development',
        'app-production',
        'gateway',
        'ingress',
        'database',
        's3',
        'metrics',
        'websocket',
        'analytics',
        'agent',
    ];

    private const array ROLES = [
        'app-dev',
        'app-prod',
        'database',
        'agent',
        'ingress',
        'metrics',
        'websocket',
        's3',
        'analytics',
    ];

    /**
     * @param  list<string>  $grantTo
     * @param  list<string>  $grantFrom
     * @param  list<string>  $agentTools
     * @return array<string, mixed>
     */
    public function build(
        ?string $name,
        ?string $template,
        bool $operator,
        ?string $roles,
        ?string $host,
        ?string $operatorName,
        ?string $operatorTld,
        bool $firstGatewayBootstrap,
        ?string $tld,
        ?string $user,
        ?string $gatewayEndpoint,
        ?string $ingressNode,
        ?string $valkeyNode,
        ?string $postgresNode,
        ?string $postgresProcess,
        ?string $clickhouseNode,
        ?string $s3DataPath,
        ?string $hostKeyFingerprint,
        ?string $selfGrant,
        ?string $selfGrantPermissions,
        array $grantTo,
        ?string $grantToPreset,
        ?string $grantToPermissions,
        array $grantFrom,
        ?string $grantFromPreset,
        ?string $grantFromPermissions,
        array $agentTools,
    ): array {
        if ($name === null) {
            throw new NodeWriteInputException('validation_failed', 'Node name is required.', ['field' => 'name']);
        }

        $template = $this->normalizeTemplate($template);
        $rolesList = $this->parseRoles($roles);

        if ($template !== null && $rolesList !== []) {
            throw new NodeWriteInputException(
                'validation_failed',
                '--template and --roles cannot be used together.',
                ['fields' => ['template', 'roles']],
            );
        }

        if ($operator && $rolesList !== []) {
            throw new NodeWriteInputException(
                'validation_failed',
                '--operator and --roles cannot be used together.',
                ['fields' => ['operator', 'roles']],
            );
        }

        if ($operator && $template !== null && $template !== 'operator') {
            throw new NodeWriteInputException(
                'validation_failed',
                '--operator can only be combined with --template=operator.',
                ['fields' => ['operator', 'template']],
            );
        }

        if ($tld === null) {
            throw new NodeWriteInputException('validation_failed', 'Node TLD is required.', ['field' => 'tld']);
        }

        if (! NodeTld::isValid($tld)) {
            throw new NodeWriteInputException(
                'validation_failed',
                'Node TLD must be a non-reserved lowercase DNS label without a leading dot.',
                ['field' => 'tld', 'value' => $tld],
            );
        }

        if ($firstGatewayBootstrap) {
            if ($operatorName === null) {
                throw new NodeWriteInputException(
                    'validation_failed',
                    'Initiating operator node name is required for first-gateway bootstrap.',
                    ['field' => 'operator_name'],
                );
            }

            if ($operatorTld === null) {
                throw new NodeWriteInputException(
                    'validation_failed',
                    'Initiating operator node TLD is required for first-gateway bootstrap.',
                    ['field' => 'operator_tld'],
                );
            }

            if (! NodeTld::isValid($operatorTld)) {
                throw new NodeWriteInputException(
                    'validation_failed',
                    'Initiating operator node TLD must be a non-reserved lowercase DNS label without a leading dot.',
                    ['field' => 'operator_tld', 'value' => $operatorTld],
                );
            }

            if ($operatorTld === $tld) {
                throw new NodeWriteInputException(
                    'validation_failed',
                    'Gateway and initiating operator node TLDs must be unique.',
                    ['fields' => ['tld', 'operator_tld']],
                );
            }
        } elseif ($operatorTld !== null) {
            throw new NodeWriteInputException(
                'validation_failed',
                '--operator-tld is only valid for first-gateway bootstrap.',
                ['field' => 'operator_tld'],
            );
        }

        $payload = [
            'name' => $name,
        ];

        $this->putString($payload, 'template', $template);
        $this->putString($payload, 'host', $host);
        $this->putString($payload, 'operator_name', $operatorName);
        $this->putString($payload, 'operator_tld', $operatorTld);
        $this->putString($payload, 'tld', $tld);
        $this->putString($payload, 'user', $user);
        $this->putString($payload, 'gateway_endpoint', $gatewayEndpoint);
        $this->putString($payload, 'ingress_node', $ingressNode);
        $this->putString($payload, 'valkey_node', $valkeyNode);
        $this->putString($payload, 'postgres_node', $postgresNode);
        $this->putString($payload, 'postgres_process', $postgresProcess);
        $this->putString($payload, 'clickhouse_node', $clickhouseNode);
        $this->putString($payload, 's3_data_path', $s3DataPath);
        $this->putString($payload, 'host_key_fingerprint', $hostKeyFingerprint);
        $this->putString($payload, 'self_grant', $selfGrant);
        $this->putString($payload, 'self_grant_permissions', $selfGrantPermissions);
        $this->putString($payload, 'grant_to_preset', $grantToPreset);
        $this->putString($payload, 'grant_to_permissions', $grantToPermissions);
        $this->putString($payload, 'grant_from_preset', $grantFromPreset);
        $this->putString($payload, 'grant_from_permissions', $grantFromPermissions);

        if ($operator) {
            $payload['operator'] = true;
        }

        if ($rolesList !== []) {
            $payload['roles'] = $rolesList;
        }

        if ($grantTo !== []) {
            $payload['grant_to'] = $grantTo;
        }

        if ($grantFrom !== []) {
            $payload['grant_from'] = $grantFrom;
        }

        if ($agentTools !== []) {
            $payload['agent_tools'] = $agentTools;
        }

        return $this->withRoleSpecificValidation($payload, $rolesList);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return list<string>
     */
    public function toGatewayArtisanArguments(array $payload, bool $json): array
    {
        $arguments = ['node:new'];
        $name = $payload['name'] ?? null;

        if (is_string($name) && $name !== '') {
            $arguments[] = $name;
        }

        $stringOptions = [
            'template' => 'template',
            'host' => 'host',
            'operator_name' => 'operator-name',
            'operator_tld' => 'operator-tld',
            'tld' => 'tld',
            'user' => 'user',
            'gateway_endpoint' => 'gateway-endpoint',
            'ingress_node' => 'ingress',
            'valkey_node' => 'valkey-node',
            'postgres_node' => 'postgres-node',
            'postgres_process' => 'postgres-process',
            'clickhouse_node' => 'clickhouse-node',
            's3_data_path' => 's3-data-path',
            'host_key_fingerprint' => 'host-key-fingerprint',
            'self_grant' => 'self-grant',
            'self_grant_permissions' => 'self-grant-permissions',
            'grant_to_preset' => 'grant-to-preset',
            'grant_to_permissions' => 'grant-to-permissions',
            'grant_from_preset' => 'grant-from-preset',
            'grant_from_permissions' => 'grant-from-permissions',
        ];

        foreach ($stringOptions as $key => $option) {
            $value = $payload[$key] ?? null;

            if (is_string($value) && $value !== '') {
                $arguments[] = "--{$option}={$value}";
            }
        }

        if (($payload['operator'] ?? false) === true) {
            $arguments[] = '--operator';
        }

        $roles = $payload['roles'] ?? [];
        if (is_array($roles) && $roles !== []) {
            $arguments[] = '--roles='.implode(',', $roles);
        }

        $this->appendRepeatableOption($arguments, 'grant-to', $payload['grant_to'] ?? []);
        $this->appendRepeatableOption($arguments, 'grant-from', $payload['grant_from'] ?? []);
        $this->appendRepeatableOption($arguments, 'agent-tool', $payload['agent_tools'] ?? []);

        if ($json) {
            $arguments[] = '--json';
        }

        return $arguments;
    }

    private function normalizeTemplate(?string $template): ?string
    {
        if ($template === null) {
            return null;
        }

        $template = trim($template);

        if ($template === '') {
            throw new NodeWriteInputException(
                'validation_failed',
                'Node template is required when --template is supplied.',
                ['field' => 'template'],
            );
        }

        if (! in_array($template, self::TEMPLATES, true)) {
            throw new NodeWriteInputException(
                'validation_failed',
                'Node template must be one of operator, app-development, app-production, gateway, ingress, database, s3, metrics, websocket, analytics, or agent.',
                ['field' => 'template'],
            );
        }

        return $template;
    }

    /**
     * @return list<string>
     */
    private function parseRoles(?string $roles): array
    {
        if ($roles === null) {
            return [];
        }

        $parsed = array_values(array_filter(
            array_map(trim(...), explode(',', $roles)),
            static fn (string $role): bool => $role !== '',
        ));

        if ($parsed === []) {
            throw new NodeWriteInputException(
                'validation_failed',
                'At least one role is required when --roles is supplied.',
                ['field' => 'roles'],
            );
        }

        $parsed = array_values(array_unique($parsed));

        foreach ($parsed as $role) {
            if (! in_array($role, self::ROLES, true)) {
                throw new NodeWriteInputException(
                    'validation_failed',
                    'Node roles must be one or more of app-dev, app-prod, database, agent, ingress, metrics, websocket, s3, or analytics.',
                    ['field' => 'roles'],
                );
            }
        }

        return $parsed;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  list<string>  $roles
     * @return array<string, mixed>
     */
    private function withRoleSpecificValidation(array $payload, array $roles): array
    {
        $hasAnalytics = in_array('analytics', $roles, true) || ($payload['template'] ?? null) === 'analytics';
        $postgresNode = $payload['postgres_node'] ?? null;
        $postgresProcess = $payload['postgres_process'] ?? null;
        $clickhouseNode = $payload['clickhouse_node'] ?? null;

        if ($hasAnalytics) {
            if (! is_string($postgresNode) || $postgresNode === '') {
                throw new NodeWriteInputException('validation_failed', 'The analytics role requires --postgres-node.', [
                    'field' => 'postgres_node',
                ]);
            }

            if (! is_string($clickhouseNode) || $clickhouseNode === '') {
                throw new NodeWriteInputException(
                    'validation_failed',
                    'The analytics role requires --clickhouse-node.',
                    ['field' => 'clickhouse_node'],
                );
            }

            if (! is_string($postgresProcess) || $postgresProcess === '') {
                throw new NodeWriteInputException(
                    'validation_failed',
                    'The analytics role requires --postgres-process.',
                    ['field' => 'postgres_process'],
                );
            }

            return $payload;
        }

        if ($postgresNode !== null) {
            throw new NodeWriteInputException('validation_failed', 'Only the analytics role accepts --postgres-node.', [
                'field' => 'postgres_node',
            ]);
        }

        if ($clickhouseNode !== null) {
            throw new NodeWriteInputException(
                'validation_failed',
                'Only the analytics role accepts --clickhouse-node.',
                ['field' => 'clickhouse_node'],
            );
        }

        if ($postgresProcess !== null) {
            throw new NodeWriteInputException(
                'validation_failed',
                'Only the analytics role accepts --postgres-process.',
                ['field' => 'postgres_process'],
            );
        }

        return $payload;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function putString(array &$payload, string $key, ?string $value): void
    {
        if ($value !== null) {
            $payload[$key] = $value;
        }
    }

    /**
     * @param  list<string>  $arguments
     */
    private function appendRepeatableOption(array &$arguments, string $option, mixed $values): void
    {
        if (! is_array($values)) {
            return;
        }

        foreach ($values as $value) {
            if (is_string($value) && $value !== '') {
                $arguments[] = "--{$option}={$value}";
            }
        }
    }
}
