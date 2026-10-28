<?php

declare(strict_types=1);

namespace App\Actions\Instances;

use App\Actions\Operations\StartOperationAction;
use App\Domain\Instances\InstanceState;
use App\Domain\Operations\AwaitOperation;
use App\Domain\Operations\OperationStatus;
use App\Domain\Projects\LifecyclePhase;
use App\Domain\Projects\ProjectLifecycleStepStore;
use App\Domain\Shared\ResourceOperationException;
use App\Models\Instance;
use App\Models\Operation;
use App\Models\Project;
use Illuminate\Support\Facades\DB;

/** Admits one immutable Project setup or teardown list. Parents own rollback policy. */
final readonly class StartInstanceStepListAction
{
    public function __construct(private ProjectLifecycleStepStore $steps, private StartOperationAction $operations) {}

    public function configurationDigest(Project $project, LifecyclePhase $phase): string
    {
        return hash('sha256', json_encode($this->steps->ordered($project, $phase), JSON_THROW_ON_ERROR));
    }

    public function execute(Instance $instance, LifecyclePhase $phase, string $triggeredBy, ?string $requestId): Operation
    {
        $instance->refresh()->loadMissing('project');
        if ($instance->placedOnAppProd() || $instance->status !== InstanceState::Active) {
            throw new ResourceOperationException('instance.setup_unavailable', 'Setup requires an active development Instance.', 409);
        }

        return $this->operations->execute('instance.run_steps', 'instance', $instance->id, $instance->node_id, [
            'instance_id' => $instance->id,
            'source' => 'project', 'list' => $phase->value,
            'configuration_digest' => $this->configurationDigest($instance->project, $phase),
        ], $triggeredBy, $requestId);
    }

    /** Gateway callers admit after committing their own changes, then await bounded reads. */
    public function retained(Instance $instance, LifecyclePhase $phase, string $caller): Operation
    {
        if (DB::transactionLevel() !== 0) {
            throw new ResourceOperationException('operation.await_in_transaction', 'Commit the caller transaction before admitting and awaiting a step list.', 409);
        }
        $instance->refresh()->loadMissing('project');
        if ($instance->placedOnAppProd()) {
            throw new ResourceOperationException('instance.setup_unavailable', 'Project step lists require a development Instance.', 409);
        }

        return $this->operations->execute('instance.run_steps', 'instance', $instance->id, $instance->node_id,
            ['instance_id' => $instance->id, 'source' => 'project', 'list' => $phase->value,
                'configuration_digest' => $this->configurationDigest($instance->project, $phase)], $caller, null);
    }

    public function await(Operation $operation): Operation
    {
        $finished = app(AwaitOperation::class)->execute($operation, 120);
        if ($finished->status !== OperationStatus::Succeeded) {
            $result = $finished->result ?? [];
            throw new ResourceOperationException($finished->error_code ?? 'instance.lifecycle_unconfirmed',
                'The Project step list did not succeed. Inspect its Operation before retrying.', 409,
                details: ['operation_id' => $finished->id, 'outcome' => is_string($result['outcome'] ?? null) ? $result['outcome'] : 'unconfirmed',
                    'step' => is_string($result['failed_step'] ?? null) ? $result['failed_step'] : '']);
        }

        return $finished;
    }
}
