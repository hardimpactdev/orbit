<?php

declare(strict_types=1);

namespace App\Services\Node;

use App\Exceptions\NodeWriteInputException;

class NodeRoleAddPayloadBuilder
{
    /**
     * @return array<string, mixed>
     */
    public function build(string $role, ?string $tld, ?string $redisNode, ?string $s3DataPath): array
    {
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

        if ($role === 'app-dev') {
            if ($tld === null) {
                throw new NodeWriteInputException('validation_failed', 'The app-dev role requires --tld.', ['field' => 'tld']);
            }

            $settings['tld'] = $tld;
        } elseif ($tld !== null) {
            throw new NodeWriteInputException('validation_failed', "Role '{$role}' does not accept --tld.", ['field' => 'tld', 'role' => $role]);
        }

        if ($role === 'websocket') {
            if ($redisNode === null) {
                throw new NodeWriteInputException('validation_failed', 'The websocket role requires --redis-node.', ['field' => 'redis_node']);
            }

            $settings['redis_node'] = $redisNode;
        } elseif ($redisNode !== null) {
            throw new NodeWriteInputException('validation_failed', "Role '{$role}' does not accept --redis-node.", ['field' => 'redis_node', 'role' => $role]);
        }

        if ($role === 's3') {
            $settings['data_path'] = $s3DataPath ?? '/srv/orbit/s3/data';
        } elseif ($s3DataPath !== null) {
            throw new NodeWriteInputException('validation_failed', "Role '{$role}' does not accept --s3-data-path.", ['field' => 's3_data_path', 'role' => $role]);
        }

        return [
            'role' => $role,
            'settings' => $settings,
        ];
    }
}
