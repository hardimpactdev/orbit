<?php

declare(strict_types=1);

namespace App\Domain\Instances\Environment;

use App\Domain\Operations\DirectOperation;
use App\Domain\Shared\ResourceOperationException;
use App\Infrastructure\AgentCommands\AgentCommandRefused;
use App\Infrastructure\AgentCommands\AgentCommandRunner;
use App\Models\Node;
use App\Models\Operation;

/** One convergent Node primitive; Swamp owns execution and recovery. */
final readonly class DirectInstanceWebRoots
{
    public function __construct(private InstanceServedWebRootsInput $input, private AgentCommandRunner $agent) {}

    public function assertOperation(Operation $operation): void
    {
        if ($operation->kind !== 'instance.prepare_served_web_roots' || ! DirectOperation::owns($operation)
            || $operation->target_type !== 'instance' || $operation->node_id === null) {
            throw new ResourceOperationException('operation.execution_forbidden', 'A direct Instance web-root intent is required.', 422);
        }
    }

    /** @return array<string, mixed> */
    public function prepare(Operation $operation): array
    {
        $this->assertOperation($operation);
        $input = $this->input->forOperation($operation);
        if ($input === null) {
            return ['instance_id' => $operation->target_id, 'confirmed' => true, 'no_op' => true];
        }
        try {
            $this->agent->run(Node::query()->findOrFail($operation->node_id), 'instance.prepare-served-web-roots', $input);
        } catch (AgentCommandRefused $exception) {
            return ['error_code' => $exception->errorCode === 'instance.release_missing' ? 'route.web_root_release_missing' : $exception->errorCode];
        }

        return ['instance_id' => $operation->target_id, 'confirmed' => true, 'no_op' => false];
    }
}
