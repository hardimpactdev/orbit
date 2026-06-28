<?php

declare(strict_types=1);

namespace App\Services\Solo;

final class SoloReadOperationCatalog
{
    /**
     * @return list<SoloReadOperation>
     */
    public static function all(): array
    {
        return [
            new SoloReadOperation(apiPath: 'tools', upstreamTemplate: '/tools', dataKey: 'tools'),
            new SoloReadOperation(apiPath: 'projects', upstreamTemplate: '/projects', dataKey: 'projects'),
            new SoloReadOperation(apiPath: 'project/list', upstreamTemplate: '/projects', dataKey: 'projects'),
            new SoloReadOperation(
                apiPath: 'project/show',
                upstreamTemplate: '/projects/{project}',
                dataKey: 'project',
                requiredQuery: ['project'],
            ),
            new SoloReadOperation(
                apiPath: 'project/status',
                upstreamTemplate: '/projects/{project}/status',
                dataKey: 'status',
                requiredQuery: ['project'],
            ),
            new SoloReadOperation(
                apiPath: 'project/stats',
                upstreamTemplate: '/projects/{project}/stats',
                dataKey: 'stats',
                requiredQuery: ['project'],
            ),
            new SoloReadOperation(apiPath: 'process/list', upstreamTemplate: '/processes', dataKey: 'processes'),
            new SoloReadOperation(
                apiPath: 'process/show',
                upstreamTemplate: '/processes/{process}',
                dataKey: 'process',
                requiredQuery: ['process'],
            ),
            new SoloReadOperation(
                apiPath: 'process/output',
                upstreamTemplate: '/processes/{process}/output',
                dataKey: 'output',
                requiredQuery: ['process'],
            ),
            new SoloReadOperation(apiPath: 'scratchpad/list', upstreamTemplate: '/scratchpads', dataKey: 'scratchpads'),
            new SoloReadOperation(
                apiPath: 'scratchpad/show',
                upstreamTemplate: '/scratchpads/{scratchpad}',
                dataKey: 'scratchpad',
                requiredQuery: ['scratchpad'],
            ),
            new SoloReadOperation(
                apiPath: 'scratchpad/find',
                upstreamTemplate: '/scratchpads/find/{query}',
                dataKey: 'scratchpads',
                requiredQuery: ['query'],
            ),
            new SoloReadOperation(apiPath: 'todo/list', upstreamTemplate: '/todos', dataKey: 'todos'),
            new SoloReadOperation(
                apiPath: 'todo/show',
                upstreamTemplate: '/todos/{todo}',
                dataKey: 'todo',
                requiredQuery: ['todo'],
            ),
            new SoloReadOperation(apiPath: 'service/list', upstreamTemplate: '/services', dataKey: 'services'),
            new SoloReadOperation(apiPath: 'timer/list', upstreamTemplate: '/timers', dataKey: 'timers'),
            new SoloReadOperation(apiPath: 'lock/status', upstreamTemplate: '/locks/status', dataKey: 'lock'),
            new SoloReadOperation(apiPath: 'agent-tool/list', upstreamTemplate: '/agent-tools', dataKey: 'agent_tools'),
        ];
    }

    public static function find(string $apiPath): SoloReadOperation
    {
        foreach (self::all() as $operation) {
            if ($operation->apiPath === trim($apiPath, characters: '/')) {
                return $operation;
            }
        }

        throw new SoloProxyException(
            errorCode: 'validation_failed',
            message: 'Unknown Solo read operation.',
            meta: [
                'reason' => 'solo_operation_unknown',
                'operation' => $apiPath,
            ],
            status: 422,
        );
    }
}
