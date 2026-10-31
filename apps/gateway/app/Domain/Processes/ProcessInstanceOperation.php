<?php

declare(strict_types=1);

namespace App\Domain\Processes;

use App\Actions\Instances\InstantiateProjectRuntimeDefinitionsAction;
use App\Actions\Operations\StartOperationAction;
use App\Domain\Operations\DirectOperation;
use App\Domain\Operations\DirectOperationProjection;
use App\Domain\Operations\OperationOutcome;
use App\Domain\Routes\CaddyPublicationPlan;
use App\Domain\Shared\LifecycleStatus;
use App\Domain\Shared\ResourceOperationException;
use App\Infrastructure\AgentCommands\AgentDelivery;
use App\Models\Instance;
use App\Models\Node;
use App\Models\Operation;
use App\Models\Process;
use App\Support\ValidatedData;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;

/** One immutable Instance plan; every member executes inside its native parent DAG. */
final readonly class ProcessInstanceOperation implements DirectOperationProjection
{
    public function __construct(private StartOperationAction $operations, private ProcessOperationAdmission $admission,
        private ProcessControlProjection $processes, private ProcessOperationCompletion $completion, private DirectOperation $direct) {}

    public function execute(Instance $instance, string $kind, ?string $requestId = null): Operation
    {
        return DB::transaction(function () use ($instance, $kind, $requestId): Operation {
            $instance = Instance::query()->with('node')->whereKey($instance->id)->lockForUpdate()->firstOrFail();
            $input = $this->capture($instance, $kind);

            return $this->operations->execute($kind, 'instance', $instance->id, $instance->node_id, $input, 'operator', $requestId);
        });
    }

    /** Safe record capture, also reusable by an owning parent admission transaction.
     * @return array<string,mixed>
     */
    public function capture(Instance $instance, string $kind): array
    {
        if (! in_array($kind, ['process.install_instance_definitions', 'process.restart_instance'], true)) {
            throw new ResourceOperationException('operation.kind_unavailable', 'This is not an Instance Process kind.', 409);
        }
        if ($kind === 'process.install_instance_definitions' && $instance->placedOnAppProd()) {
            $instance = app(InstantiateProjectRuntimeDefinitionsAction::class)->captureForClone($instance);
        }
        $query = Process::query()->whereIn('owner_type', Instance::morphTypes())->where('owner_id', $instance->id);
        $verb = $kind === 'process.restart_instance' ? 'restart' : 'create';
        if ($verb === 'restart') {
            $query->where('status', LifecycleStatus::Active)->where('desired_state', DesiredProcessState::Running)->where('restart_on_deploy', true);
        } else {
            $query->whereNotNull('source_definition_id')->whereNotIn('status', [LifecycleStatus::Active, LifecycleStatus::Removing]);
        }
        $records = $query->orderBy('id')->lockForUpdate()->limit(4097)->get();
        if ($records->count() > 4096) {
            throw new ResourceOperationException('operation.runtime_limit', 'An Instance runtime plan may contain at most 4096 members.', 409);
        }
        $pin = app(AgentDelivery::class)->pin($instance->node);
        $members = [];
        foreach ($records as $record) {
            $input = $this->admission->inputFor($record, $verb);
            if ($input['node_id'] !== $instance->node_id) {
                throw new ResourceOperationException('operation.execution_forbidden', 'The Process is placed on another Node.', 422);
            }
            $members[] = ['id' => $record->id, 'input' => [...$input, 'agent_sha256' => $pin]];
        }

        return ['instance_id' => $instance->id, 'node_id' => $instance->node_id, 'members' => $members,
            ...($verb === 'create' ? ['installations' => ['$secret' => 'process.instance_installations', 'id' => $instance->id]] : [])];
    }

    /** @return list<array{id:int,input:array<string,mixed>}> */
    public static function members(Operation $operation): array
    {
        $members = $operation->input['members'];
        if (! is_array($members) || ! array_is_list($members)) {
            throw new \InvalidArgumentException('The admitted runtime members must be a list.');
        }
        $result = [];
        foreach ($members as $value) {
            $member = ValidatedData::object($value);
            $result[] = ['id' => ValidatedData::integer($member['id']), 'input' => ValidatedData::object($member['input'])];
        }

        return $result;
    }

    /** An in-memory member view; never saved, admitted or dispatched as another Operation.
     * @param  array<string,mixed>  $member
     */
    public static function member(Operation $parent, array $member): Operation
    {
        $view = new Operation;
        $view->forceFill(['id' => $parent->id, 'status' => $parent->status, 'kind' => $parent->kind === 'process.restart_instance' ? 'process.restart' : 'process.create',
            'target_type' => 'process', 'target_id' => $member['id'], 'node_id' => $parent->node_id, 'input' => $member['input']]);

        return $view;
    }

    public function input(Operation $operation): array
    {
        $members = [];
        $vite = false;
        foreach (self::members($operation) as $member) {
            $safe = $this->processes->input(self::member($operation, $member));
            $vite = $vite || isset($safe['vite_plan']) || (is_array($safe['control'] ?? null) && isset($safe['control']['vite_plan']));
            unset($safe['caddy_publication']);
            $members[] = ['id' => $member['id'], 'input' => $safe];
        }

        return ['instance_id' => $operation->target_id, 'members' => $members,
            ...($vite ? ['caddy_publication' => CaddyPublicationPlan::forNode(Node::query()->findOrFail($operation->node_id))] : [])];
    }

    public function apply(Operation $operation, array $data): Operation
    {
        return DB::transaction(function () use ($operation, $data): Operation {
            $operation->refresh();
            $terminal = $operation->status->terminal();
            $reported = ValidatedData::object($data['result'] ?? []);
            $reported = Arr::only($reported, ['ready', 'error_code', 'process_ids', 'members']);
            if (is_array($reported['members'] ?? null)) {
                $reported['members'] = array_map(static fn (mixed $member): array => Arr::only(ValidatedData::object($member), ['id', 'ready', 'state', 'error_code']), $reported['members']);
            }
            $projected = $this->direct->project($operation, $data, $reported);
            if (! $terminal && $projected->status->terminal()) {
                $known = array_column(self::members($operation), null, 'id');
                foreach (is_array($reported['members'] ?? null) ? $reported['members'] : [] as $value) {
                    $result = ValidatedData::object($value);
                    $member = $known[ValidatedData::integer($result['id'])] ?? null;
                    if ($member === null) {
                        continue;
                    }
                    $outcome = isset($result['error_code']) ? OperationOutcome::failed(ValidatedData::string($result['error_code'])) : OperationOutcome::succeeded(['ready' => true, 'state' => $result['state'] ?? 'unknown']);
                    $this->completion->complete(self::member($operation, $member), $outcome);
                }
            }

            return $projected;
        });
    }
}
