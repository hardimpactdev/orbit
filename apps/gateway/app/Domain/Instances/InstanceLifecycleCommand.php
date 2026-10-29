<?php

declare(strict_types=1);

namespace App\Domain\Instances;

use App\Domain\Operations\OperationGatewayCommand;
use App\Domain\Shared\ResourceOperationException;
use App\Models\Operation;

/** Named Gateway state transitions. It never invokes a Node transport. */
final readonly class InstanceLifecycleCommand implements OperationGatewayCommand
{
    public function __construct(private InstanceLifecycleState $state) {}

    /** @return array<string, mixed> */
    public function schema(): array
    {
        return ['type' => 'object', 'additionalProperties' => false,
            'required' => ['action', 'operation_id'], 'properties' => [
                'action' => ['type' => 'string', 'enum' => [
                    'configuration', 'clone-checkpoint', 'activate', 'finish', 'clone-environment', 'clone-sqlite-intent', 'clone-sqlite-attach', 'clone-definitions', 'registration-activate',
                    'removal-inspect', 'removal-revalidate', 'removal-accept', 'removal-route-clear', 'removal-database-intent', 'removal-runtime-intent', 'removal-row-delete',
                    'transfer-cutover', 'transfer-finish', 'transfer-sqlite-intent', 'database-intent',
                ]],
                'operation_id' => ['type' => 'integer', 'minimum' => 1],
                'instance_id' => ['type' => 'integer', 'minimum' => 1],
            ]];
    }

    /** @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public function run(Operation $operation, #[\SensitiveParameter] array $input): array
    {
        if ($input['operation_id'] !== $operation->id) {
            throw new ResourceOperationException('operation.execution_forbidden', 'The state transition belongs to another Operation.', 422);
        }

        return match ($input['action']) {
            'configuration' => ['written' => true],
            'finish' => $this->finish($operation),
            default => throw new ResourceOperationException('operation.execution_forbidden', 'Unknown lifecycle state transition.', 422),
        };
    }

    /** @return array<string, mixed> */
    private function finish(Operation $operation): array
    {
        app(InstanceLifecycleExecution::class)->assertEvidence($operation);
        $instance = $this->state->instance($operation);
        if ($operation->kind === 'instance.rename') {
            $source = app(InstanceLifecycleExecution::class)->proven($operation, 'rename-inspect');
            $this->state->renamed($operation, $source);
        }
        $result = ['lifecycle_complete' => true, 'instance_id' => $instance->id, 'project_id' => $instance->project_id, 'name' => $instance->name,
            'status' => $instance->refresh()->status->value, 'branch' => $instance->branch,
            'created' => $operation->input['created'] ?? false, 'name_converted_from' => $operation->input['name_converted_from'] ?? null];

        return $result;
    }
}
