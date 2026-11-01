<?php

declare(strict_types=1);

namespace App\Domain\Routes;

use App\Domain\Broadcasting\RecordEventBroadcaster;
use App\Domain\Broadcasting\RecordEventType;
use App\Domain\Operations\DirectOperation;
use App\Domain\Operations\DirectOperationProjection;
use App\Domain\Operations\DirectOperationPublicView;
use App\Domain\Operations\OperationStatus;
use App\Domain\Shared\ResourceOperationException;
use App\Models\Node;
use App\Models\Operation;
use App\Models\Route;
use App\Support\ValidatedData;
use Illuminate\Support\Facades\DB;

/** Fresh caller views and minimal terminal projection; no execution receipts. */
final readonly class NativeRouteRemovalProjection implements DirectOperationProjection, DirectOperationPublicView
{
    public function __construct(private DirectOperation $direct) {}

    public function input(Operation $operation): array
    {
        return [...array_filter($this->publicInput($operation, ValidatedData::integer($operation->node_id)), static fn (mixed $value): bool => $value !== null),
            'intent' => ['environment_writes' => $operation->input['route_environment']],
            'execution_secrets' => $operation->input['execution_secrets']];
    }

    public function assertPublicParticipant(Operation $operation, int $callerNodeId): void
    {
        if (! in_array($callerNodeId, ValidatedData::integerList($operation->input['participant_node_ids']), true)) {
            throw new ResourceOperationException('operation.execution_forbidden', 'This Node is not an admitted Route participant.', 403);
        }
    }

    public function publicInput(Operation $operation, int $callerNodeId): array
    {
        $this->assertPublicParticipant($operation, $callerNodeId);

        return [...app(NativeRoutePublicationInput::class)->forNode(Node::query()->findOrFail($callerNodeId)),
            ...app(NativeRouteRemovalPublicInput::class)->forNode($operation, $callerNodeId),
            'participant_node_id' => $callerNodeId];
    }

    public function apply(Operation $operation, array $data): Operation
    {
        return DB::transaction(function () use ($operation, $data): Operation {
            $operation->setRawAttributes(Operation::query()->whereKey($operation->id)->lockForUpdate()->firstOrFail()->getAttributes(), true);

            return $this->applyOwned($operation, $data);
        });
    }

    /** @param array<string,mixed> $data */
    private function applyOwned(Operation $operation, array $data): Operation
    {
        $wasTerminal = $operation->status->terminal();
        $result = ValidatedData::object($data['result'] ?? []);
        $operation = $this->direct->project($operation, $data, $result);
        if ($wasTerminal) {
            return $operation;
        }
        if ($operation->status === OperationStatus::Succeeded) {
            $snapshot = ValidatedData::object($operation->input['route_removal']);
            $rows = array_map(static fn (array $row): array => ['route_id' => ValidatedData::integer($row['route_id']), 'domain' => ValidatedData::string($row['domain']), 'replacement_of' => $row['replacement_of'] === null ? null : ValidatedData::integer($row['replacement_of'])], RouteExecutionProof::objects($snapshot['routes']));
            app(NativeRouteRemovalCompletion::class)->apply(
                ['routes' => $rows],
                $operation->kind === 'route.destroy',
                array_map(static fn (array $member): array => ['node_id' => ValidatedData::integer($member['node_id']), 'steps' => ValidatedData::stringList($member['steps'])], RouteExecutionProof::objects($result['retained_on_nodes'] ?? [])),
            );
            if ($operation->kind === 'route.destroy') {
                foreach ($rows as $row) {
                    DB::afterCommit(static fn () => app(RecordEventBroadcaster::class)->broadcast(RecordEventType::RouteDeleted, $row['route_id'], ['id' => $row['route_id'], 'domain' => $row['domain']]));
                }
            }
        } elseif ($operation->status === OperationStatus::Failed) {
            Route::query()->whereKey($operation->target_id)->update(['status' => RouteStatus::Failed,
                'failed_step' => 'withdrawal', 'error_code' => $operation->error_code]);
        }

        return $operation;
    }
}
