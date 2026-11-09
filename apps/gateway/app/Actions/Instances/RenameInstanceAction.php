<?php

declare(strict_types=1);

namespace App\Actions\Instances;

use App\Data\Instances\InstanceData;
use App\Data\Instances\RenameInstanceData;
use App\Domain\Broadcasting\RecordEventBroadcaster;
use App\Domain\Broadcasting\RecordEventType;
use App\Domain\Instances\InstanceContainer;
use App\Domain\Instances\InstanceSourceLayout;
use App\Domain\Instances\InstanceState;
use App\Domain\NodeFacts\NodeAgentLiveness;
use App\Domain\NodeFacts\NodeAgentState;
use App\Domain\NodeFacts\NodeFactKind;
use App\Domain\Shared\ResourceOperationException;
use App\Domain\SourceControl\GitRepositoryIdentity;
use App\Models\Instance;
use App\Models\InstanceTransfer;
use App\Models\NodeFact;
use Illuminate\Support\Facades\DB;

/**
 * Records the branch a development checkout already has checked out (R1: no task, no run). The Gateway checks the
 * Node agent's latest `checkout` fact instead of reading the Node.
 */
final readonly class RenameInstanceAction
{
    public function __construct(private NodeAgentLiveness $liveness, private RecordEventBroadcaster $broadcaster) {}

    public function execute(Instance $instance, RenameInstanceData $data): Instance
    {
        InstanceContainer::assertCheckout($instance);
        if ($data->domain !== null) {
            throw new ResourceOperationException('operation.kind_unavailable', 'Domain replacement requires the native Route publication parent.', 503);
        }
        $branch = $data->branch ?? throw new ResourceOperationException('validation.failed', 'Provide --branch.', 422);

        [$result, $changed] = DB::transaction(function () use ($instance, $branch): array {
            $locked = Instance::query()->lockForUpdate()->findOrFail($instance->id);
            $this->admit($locked);
            $this->verify($locked, $branch);
            if ($branch === $locked->branch) {
                // Recording the recorded branch again keeps its override.
                return [$locked, false];
            }
            $locked->update(['branch' => $branch, 'branch_override' => $branch]);

            return [$locked->refresh(), true];
        });
        if ($changed) {
            $this->broadcaster->broadcast(RecordEventType::InstanceUpdated, $result->id,
                InstanceData::fromModel($result->load(['project', 'node', 'routes.targets']))->toArray());
        }

        return $result;
    }

    private function admit(Instance $instance): void
    {
        if (! $instance->placedOnAppDev() || $instance->source_layout !== InstanceSourceLayout::Checkout->value) {
            throw new ResourceOperationException('instance.rename_unsupported', 'Rename requires a development checkout Instance.', 409);
        }
        if ($instance->status === InstanceState::Removing
            || InstanceTransfer::query()->where('instance_id', $instance->id)->where('status', '!=', 'completed')->exists()
            || Instance::query()->where('clone_candidate_id', $instance->id)->whereNull('clone_completed_at')->exists()
            || $instance->clone_candidate_id !== null && $instance->clone_completed_at === null) {
            throw new ResourceOperationException('instance.lifecycle_busy', 'The Instance is busy with another lifecycle operation.', 409);
        }
        if ($instance->status !== InstanceState::Active) {
            throw new ResourceOperationException('instance.rename_inactive', 'Rename requires an active Instance.', 409);
        }
    }

    /**
     * The agent's latest report must show the branch checked out in the Instance's own checkout. The agent reports a
     * checkout within seconds of a change while it is online, so an online agent's fact is current; a silent agent's
     * fact cannot be trusted.
     */
    private function verify(Instance $instance, string $branch): void
    {
        $agent = $this->liveness->for($instance->node);
        $fact = NodeFact::query()->where('node_id', $instance->node_id)->where('kind', NodeFactKind::Checkout->value)
            ->where('subject', $instance->checkout_path)->first();
        if ($agent?->state !== NodeAgentState::Online || ! $fact instanceof NodeFact) {
            throw new ResourceOperationException('instance.branch_not_checked_out',
                "Orbit can't verify the checkout: the Node agent has not reported it recently. Try again when the agent reports.", 409);
        }
        $origin = $fact->data['origin'] ?? null;
        try {
            $identity = is_string($origin) && ! str_contains($origin, 'REDACTED@') ? GitRepositoryIdentity::derive($origin) : null;
        } catch (\InvalidArgumentException) {
            $identity = null;
        }
        if (($fact->data['top_level'] ?? null) !== $instance->checkout_path || $identity !== $instance->project->repository_identity) {
            throw new ResourceOperationException('instance.branch_not_checked_out', 'The checkout no longer matches its recorded path and origin.', 409);
        }
        if (($fact->data['branch'] ?? null) !== $branch) {
            throw new ResourceOperationException('instance.branch_not_checked_out',
                "The checkout has not checked out {$branch}. Check it out, then run instance:rename again.", 409);
        }
    }
}
