<?php

declare(strict_types=1);

namespace App\Services\Node;

use App\Exceptions\NodeWriteInputException;

class NodeRoleAddPayloadBuilder
{
    /**
     * @return array<string, mixed>
     */
    public function build(
        string $role,
        ?string $valkeyNode,
        ?string $postgresNode,
        ?string $postgresProcess,
        ?string $clickhouseNode,
        ?string $s3DataPath,
    ): array {
        if (in_array($role, ['gateway', 'vpn', 'router'], true)) {
            throw new NodeWriteInputException(
                'validation_failed',
                "Role '{$role}' is gateway-coupled and cannot be assigned independently.",
                ['field' => 'role', 'role' => $role],
            );
        }

        if ($role === 'agent') {
            throw new NodeWriteInputException(
                'validation_failed',
                "The agent role cannot be added through node role commands. Use 'node:new --template=agent' instead.",
                ['field' => 'role', 'role' => $role],
            );
        }

        $settings = [];

        if ($role === 'websocket') {
            if ($valkeyNode === null) {
                throw new NodeWriteInputException('validation_failed', 'The websocket role requires --valkey-node.', [
                    'field' => 'valkey_node',
                ]);
            }

            $settings['valkey_node'] = $valkeyNode;
        } elseif ($valkeyNode !== null) {
            throw new NodeWriteInputException('validation_failed', "Role '{$role}' does not accept --valkey-node.", [
                'field' => 'valkey_node',
                'role' => $role,
            ]);
        }

        if ($role === 'analytics') {
            if ($postgresNode === null) {
                throw new NodeWriteInputException('validation_failed', 'The analytics role requires --postgres-node.', [
                    'field' => 'postgres_node',
                ]);
            }

            if ($clickhouseNode === null) {
                throw new NodeWriteInputException(
                    'validation_failed',
                    'The analytics role requires --clickhouse-node.',
                    ['field' => 'clickhouse_node'],
                );
            }

            if ($postgresProcess === null) {
                throw new NodeWriteInputException(
                    'validation_failed',
                    'The analytics role requires --postgres-process.',
                    ['field' => 'postgres_process'],
                );
            }

            $settings['postgres_node'] = $postgresNode;
            $settings['postgres_process'] = $postgresProcess;
            $settings['clickhouse_node'] = $clickhouseNode;
        } else {
            if ($postgresNode !== null) {
                throw new NodeWriteInputException(
                    'validation_failed',
                    "Role '{$role}' does not accept --postgres-node.",
                    ['field' => 'postgres_node', 'role' => $role],
                );
            }

            if ($clickhouseNode !== null) {
                throw new NodeWriteInputException(
                    'validation_failed',
                    "Role '{$role}' does not accept --clickhouse-node.",
                    ['field' => 'clickhouse_node', 'role' => $role],
                );
            }

            if ($postgresProcess !== null) {
                throw new NodeWriteInputException(
                    'validation_failed',
                    "Role '{$role}' does not accept --postgres-process.",
                    ['field' => 'postgres_process', 'role' => $role],
                );
            }
        }

        if ($role === 's3') {
            $settings['data_path'] = $s3DataPath ?? '/srv/orbit/s3/data';
        } elseif ($s3DataPath !== null) {
            throw new NodeWriteInputException('validation_failed', "Role '{$role}' does not accept --s3-data-path.", [
                'field' => 's3_data_path',
                'role' => $role,
            ]);
        }

        return [
            'role' => $role,
            'settings' => $settings,
        ];
    }
}
