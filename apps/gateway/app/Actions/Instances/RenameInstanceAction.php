<?php

declare(strict_types=1);

namespace App\Actions\Instances;

use App\Actions\Operations\StartOperationAction;
use App\Data\Instances\RenameInstanceData;
use App\Domain\Instances\InstanceSourceLayout;
use App\Domain\Instances\InstanceState;
use App\Domain\Nodes\Storage\CheckoutRemovalBoundary;
use App\Domain\Operations\OperationIdempotency;
use App\Domain\Shared\ResourceOperationException;
use App\Models\Instance;
use App\Models\InstanceTransfer;
use App\Models\Operation;
use Illuminate\Support\Facades\DB;

final readonly class RenameInstanceAction
{
    public function __construct(
        private StartOperationAction $operations,
        private OperationIdempotency $idempotency,
        private CheckoutRemovalBoundary $boundaries,
    ) {}

    public function execute(Instance $instance, RenameInstanceData $data, ?string $caller = null, ?string $requestId = null): Operation
    {
        if (($replay = $this->idempotency->replay('instance.rename')) !== null) {
            return $replay;
        }
        if ($data->domain !== null) {
            throw new ResourceOperationException('operation.kind_unavailable', 'Domain replacement requires the native Route publication parent.', 503);
        }

        return DB::transaction(fn (): Operation => $this->admit($instance->refresh(), $data, $caller, $requestId));
    }

    private function admit(Instance $instance, RenameInstanceData $data, ?string $caller, ?string $requestId): Operation
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
        $node = $instance->node;
        if ($node->user === 'root' || preg_match('/\A[a-z_][a-z0-9_-]{0,31}\z/D', $node->user) !== 1) {
            throw new ResourceOperationException('instance.source_owner_invalid', 'Rename requires the configured Node account.', 409);
        }
        $root = $this->boundaries->instanceGroupingDirectory($instance)->parent();
        if ($root === null) {
            throw new ResourceOperationException('instance.checkout_path_unsafe', 'Rename requires its stored checkout boundary.', 409);
        }

        return $this->operations->execute('instance.rename', 'instance', $instance->id, $instance->node_id, [
            'instance_id' => $instance->id, 'project_id' => $instance->project_id,
            'branch' => $data->branch,
            'checkout' => $instance->checkout_path, 'repository_identity' => $instance->project->repository_identity,
            'repository' => $instance->project->repository_url, 'source_layout' => $instance->source_layout,
            'prepare_id' => $instance->source_prepare_id, 'seed_repository' => $instance->seed_repository,
            'user' => $node->user,
            'source_root' => $root->value,
        ], $caller, $requestId);
    }
}
