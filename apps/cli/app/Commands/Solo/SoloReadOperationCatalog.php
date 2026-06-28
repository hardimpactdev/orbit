<?php

declare(strict_types=1);

namespace App\Commands\Solo;

final class SoloReadOperationCatalog
{
    /**
     * @return list<SoloReadOperationDefinition>
     */
    public static function all(): array
    {
        return [
            new SoloReadOperationDefinition(
                command: 'solo:tools',
                signature: 'solo:tools {--json : Output JSON}',
                gatewayPath: '/api/solo/tools',
            ),
            new SoloReadOperationDefinition(
                command: 'solo:project:list',
                signature: 'solo:project:list {--json : Output JSON}',
                gatewayPath: '/api/solo/projects',
            ),
            new SoloReadOperationDefinition(
                command: 'solo:project:show',
                signature: 'solo:project:show {project? : Solo project name} {--json : Output JSON}',
                gatewayPath: '/api/solo/project/show',
                requiredArguments: ['project'],
            ),
            new SoloReadOperationDefinition(
                command: 'solo:project:status',
                signature: 'solo:project:status {project? : Solo project name} {--json : Output JSON}',
                gatewayPath: '/api/solo/project/status',
                requiredArguments: ['project'],
            ),
            new SoloReadOperationDefinition(
                command: 'solo:project:stats',
                signature: 'solo:project:stats {project? : Solo project name} {--json : Output JSON}',
                gatewayPath: '/api/solo/project/stats',
                requiredArguments: ['project'],
            ),
            new SoloReadOperationDefinition(
                command: 'solo:process:list',
                signature: 'solo:process:list {--json : Output JSON}',
                gatewayPath: '/api/solo/process/list',
            ),
            new SoloReadOperationDefinition(
                command: 'solo:process:show',
                signature: 'solo:process:show {process? : Solo process name or id} {--json : Output JSON}',
                gatewayPath: '/api/solo/process/show',
                requiredArguments: ['process'],
            ),
            new SoloReadOperationDefinition(
                command: 'solo:process:output',
                signature: 'solo:process:output {process? : Solo process name or id} {--lines=100 : Number of output lines} {--json : Output JSON}',
                gatewayPath: '/api/solo/process/output',
                requiredArguments: ['process'],
                queryOptions: ['lines' => 'lines'],
            ),
            new SoloReadOperationDefinition(
                command: 'solo:scratchpad:list',
                signature: 'solo:scratchpad:list {--json : Output JSON}',
                gatewayPath: '/api/solo/scratchpad/list',
            ),
            new SoloReadOperationDefinition(
                command: 'solo:scratchpad:show',
                signature: 'solo:scratchpad:show {scratchpad? : Solo scratchpad name or id} {--json : Output JSON}',
                gatewayPath: '/api/solo/scratchpad/show',
                requiredArguments: ['scratchpad'],
            ),
            new SoloReadOperationDefinition(
                command: 'solo:scratchpad:find',
                signature: 'solo:scratchpad:find {query? : Search query} {--json : Output JSON}',
                gatewayPath: '/api/solo/scratchpad/find',
                requiredArguments: ['query'],
            ),
            new SoloReadOperationDefinition(
                command: 'solo:todo:list',
                signature: 'solo:todo:list {--project= : Project id or name} {--json : Output JSON}',
                gatewayPath: '/api/solo/todo/list',
                queryOptions: ['project' => 'project'],
            ),
            new SoloReadOperationDefinition(
                command: 'solo:todo:show',
                signature: 'solo:todo:show {todo? : Solo todo id} {--project= : Project id or name} {--json : Output JSON}',
                gatewayPath: '/api/solo/todo/show',
                requiredArguments: ['todo'],
                queryOptions: ['project' => 'project'],
            ),
            new SoloReadOperationDefinition(
                command: 'solo:service:list',
                signature: 'solo:service:list {--json : Output JSON}',
                gatewayPath: '/api/solo/service/list',
            ),
            new SoloReadOperationDefinition(
                command: 'solo:timer:list',
                signature: 'solo:timer:list {--json : Output JSON}',
                gatewayPath: '/api/solo/timer/list',
            ),
            new SoloReadOperationDefinition(
                command: 'solo:lock:status',
                signature: 'solo:lock:status {--json : Output JSON}',
                gatewayPath: '/api/solo/lock/status',
            ),
            new SoloReadOperationDefinition(
                command: 'solo:agent-tool:list',
                signature: 'solo:agent-tool:list {--json : Output JSON}',
                gatewayPath: '/api/solo/agent-tool/list',
            ),
        ];
    }

    public static function find(string $command): ?SoloReadOperationDefinition
    {
        foreach (self::all() as $definition) {
            if ($definition->command === $command) {
                return $definition;
            }
        }

        return null;
    }
}
