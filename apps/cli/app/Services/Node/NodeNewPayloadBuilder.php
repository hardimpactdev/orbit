<?php

declare(strict_types=1);

namespace App\Services\Node;

use App\Exceptions\NodeWriteInputException;

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
        'websocket',
        'agent',
    ];

    private const array ROLES = [
        'app-dev',
        'app-prod',
        'database',
        'agent',
        'ingress',
        'websocket',
        's3',
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
        ?string $tld,
        ?string $user,
        ?string $gatewayEndpoint,
        ?string $ingressNode,
        ?string $redisNode,
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

        $payload = [
            'name' => $name,
        ];

        $this->putString($payload, 'template', $template);
        $this->putString($payload, 'host', $host);
        $this->putString($payload, 'operator_name', $operatorName);
        $this->putString($payload, 'tld', $tld);
        $this->putString($payload, 'user', $user);
        $this->putString($payload, 'gateway_endpoint', $gatewayEndpoint);
        $this->putString($payload, 'ingress_node', $ingressNode);
        $this->putString($payload, 'redis_node', $redisNode);
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

        return $payload;
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
            'tld' => 'tld',
            'user' => 'user',
            'gateway_endpoint' => 'gateway-endpoint',
            'ingress_node' => 'ingress',
            'redis_node' => 'redis-node',
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
            throw new NodeWriteInputException('validation_failed', 'Node template is required when --template is supplied.', ['field' => 'template']);
        }

        if (! in_array($template, self::TEMPLATES, true)) {
            throw new NodeWriteInputException(
                'validation_failed',
                'Node template must be one of operator, app-development, app-production, gateway, ingress, database, s3, websocket, or agent.',
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
            throw new NodeWriteInputException('validation_failed', 'At least one role is required when --roles is supplied.', ['field' => 'roles']);
        }

        $parsed = array_values(array_unique($parsed));

        foreach ($parsed as $role) {
            if (! in_array($role, self::ROLES, true)) {
                throw new NodeWriteInputException(
                    'validation_failed',
                    'Node roles must be one or more of app-dev, app-prod, database, agent, ingress, websocket, or s3.',
                    ['field' => 'roles'],
                );
            }
        }

        return $parsed;
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
