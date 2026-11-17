<?php

declare(strict_types=1);

namespace Orbit\Extensions\Conn\Data;

use App\Data\Operations\OperationData;
use App\Models\Operation;

final readonly class ConnLaunchSnapshot
{
    public const array Stages = ['workspace_server', 'first_message', 'publication', 'application_preview', 'cleanup'];

    public const array Children = ['create_operation_id', 'wake_operation_id', 'completion_operation_id', 'destroy_operation_id'];

    /** @return array<string, mixed> */
    public function project(Operation $operation): array
    {
        $summary = array_intersect_key(OperationData::summary($operation)->toArray(), array_flip([
            'id', 'kind', 'status', 'created_at', 'started_at', 'finished_at', 'cancel_requested', 'poll_after_seconds', 'error_code',
        ]));
        $input = $operation->input;

        return [...$summary,
            'launch_id' => $input['launch_id'],
            'profile_id' => $input['profile_id'],
            'project_id' => $input['project_id'],
            'instance_id' => $input['instance_id'] ?? null,
            'workspace_id' => $input['workspace_id'],
            'target_node_id' => $operation->node_id,
            'initiator_node_id' => $operation->callerNodeId(),
            'source' => $input['source'],
            'phase' => $operation->state['launch_phase'] ?? 'preparing',
            'stages' => array_fill_keys(self::Stages, ['status' => 'pending', 'started_at' => null, 'finished_at' => null, 'error' => null]),
            'readiness' => ['workspace_server' => null, 'first_message' => null, 'application_preview' => null],
            'actions' => [
                'cancel' => ['available' => true, 'reason' => null, 'requires' => null],
                'retry_launch' => ['available' => false, 'reason' => 'The launch does not need a retry.', 'requires' => null],
                'retry_application' => ['available' => false, 'reason' => 'The application does not need a retry.', 'requires' => null],
            ],
            'children' => array_fill_keys(self::Children, null),
            'thread' => null,
        ];
    }
}
