<?php

declare(strict_types=1);

namespace App\Domain\Processes;

use App\Domain\Nodes\ManagedUserAccount;
use App\Domain\Nodes\Storage\StoragePath;
use App\Domain\Operations\DirectOperationPrivateBindings;
use App\Domain\Operations\OperationSecretValue;
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

/** DB-only, per-placement Process capture for an owning multi-Node parent. */
final readonly class ProcessInstallationBatch
{
    public function __construct(private ProcessOperationAdmission $admission, private ProcessOperationSecrets $secrets,
        private ProcessControlProjection $projection, private DirectOperationPrivateBindings $bindings) {}

    /** The target's Instance is an admitted placement view, never a saved replacement Instance.
     * @return array<string,mixed>
     */
    public function capture(Instance $instance, ProcessTarget $target, ?ManagedUserAccount $account = null): array
    {
        $placed = $target->instance;
        if ($placed === null || $placed->id !== $instance->id || $placed->project_id !== $instance->project_id
            || $placed->node_id !== $target->node->id) {
            $this->refuse();
        }
        $records = Process::query()->whereIn('owner_type', Instance::morphTypes())->where('owner_id', $instance->id)
            ->where('status', '!=', LifecycleStatus::Removing)->orderBy('id')->lockForUpdate()->limit(4097)->get();
        if ($records->count() > 4096) {
            throw new ResourceOperationException('operation.runtime_limit', 'An Instance runtime plan may contain at most 4096 members.', 409);
        }
        $pin = app(AgentDelivery::class)->pin($target->node);
        $members = [];
        foreach ($records as $record) {
            $view = $this->placedProcess($record, $instance->checkout_path, $placed->checkout_path);
            $input = $this->admission->inputForTarget($view, 'create', $target, $account);
            $members[] = ['id' => $record->id, 'source_definition_id' => $record->source_definition_id,
                'input' => [...$input, 'agent_sha256' => $pin]];
        }

        return ['instance_id' => $instance->id, 'node_id' => $target->node->id, 'origin_node_id' => $instance->node_id, 'origin_checkout' => $instance->checkout_path,
            'target' => ['user' => $target->user, 'checkout_path' => $target->checkoutPath,
                'certificate_scope' => $target->certificateScope, 'environment_file' => $target->environmentFile,
                'production_release_layout' => $target->productionReleaseLayout, 'route_domain' => $target->routeDomain,
                'on_demand' => $target->onDemandHostStart,
                'instance' => Arr::only($placed->getAttributes(), ['checkout_path', 'root', 'source_is_laravel', 'vite_port', 'ssr_port']),
                'account' => $account === null ? null : ['user' => $account->user, 'group' => $account->group, 'home' => $account->home]],
            'members' => $members];
    }

    /** Safe native installer/control descriptors, with no synthetic Process Operation.
     * @param  array<string,mixed>  $batch
     * @return list<array{id:int,input:array<string,mixed>}>
     */
    public function input(array $batch): array
    {
        return array_map(fn (array $member): array => ['id' => ValidatedData::integer($member['id']),
            'input' => Arr::only($this->projection->inputFor('process.create', ValidatedData::object($member['input']),
                ValidatedData::integer($batch['node_id'])), ['control', 'installation'])], $this->members($batch));
    }

    /** Render a frozen prospective placement before the native parent starts; no DB cutover is required.
     * The parent owns vault publication and fixed descriptor names through SwampCommandStep.
     *
     * @param  array<string,mixed>  $capture
     */
    public function materialForCapture(Instance $instance, ProcessTarget $target, array $capture, ?ManagedUserAccount $account = null): OperationSecretValue
    {
        return DB::transaction(function () use ($instance, $target, $capture, $account): OperationSecretValue {
            $placement = $target->instance;
            if ($placement === null) {
                $this->refuse();
            }
            $origin = Instance::query()->lockForUpdate()->findOrFail($instance->id);
            $node = Node::query()->findOrFail($target->node->id);
            if ($origin->node_id !== ($capture['origin_node_id'] ?? null)
                || $origin->checkout_path !== ($capture['origin_checkout'] ?? null)
                || $node->status !== LifecycleStatus::Active || $node->platform !== 'linux'
                || $this->capture($origin, $target, $account) !== $capture) {
                $this->refuse();
            }
            $values = $redactions = [];
            foreach ($this->members($capture) as $member) {
                $record = Process::query()->findOrFail(ValidatedData::integer($member['id']));
                $view = $this->placedProcess($record, $origin->checkout_path, $placement->checkout_path);
                $value = $this->secrets->materialFor($view, $target, ValidatedData::object($member['input']), $account);
                $values[(string) $record->id] = ['installation' => $value->value];
                $redactions = [...$redactions, ...$value->strings()];
            }

            return new OperationSecretValue($values, $redactions);
        });
    }

    /** Fixed fields only; the owning adapter chooses one at registration, never from a request.
     * Store capture under input.process_installations.<field> and declare that wire field.
     */
    public function material(Operation $operation, string $field): OperationSecretValue
    {
        if (! in_array($field, ['source_installations', 'destination_installations'], true)
            || ! in_array($operation->kind, ['instance.transfer', 'instance.transfer_rollback'], true)
            || $operation->target_type !== 'instance') {
            $this->refuse();
        }
        $owner = $this->bindings->ownerNode($operation, $field);
        $batch = ValidatedData::object(ValidatedData::object($operation->input['process_installations'] ?? [])[$field] ?? []);
        $instance = Instance::query()->findOrFail($operation->target_id);
        $snapshot = ValidatedData::object($batch['target']);
        $placement = ValidatedData::object($snapshot['instance']);
        if (($batch['instance_id'] ?? null) !== $instance->id || ($batch['node_id'] ?? null) !== $owner
            || $instance->node_id !== $owner || $instance->checkout_path !== ($placement['checkout_path'] ?? null)) {
            $this->refuse();
        }
        $node = Node::query()->findOrFail($owner);
        if ($node->status !== LifecycleStatus::Active || $node->platform !== 'linux') {
            $this->refuse();
        }
        $placed = clone $instance;
        $placed->forceFill($placement)->setRelation('node', $node);
        $target = new ProcessTarget($node, ValidatedData::string($snapshot['user']), ValidatedData::string($snapshot['checkout_path']),
            certificateScope: ValidatedData::nullableString($snapshot['certificate_scope']), instance: $placed,
            environmentFile: ValidatedData::string($snapshot['environment_file']), productionReleaseLayout: is_bool($snapshot['production_release_layout']) ? $snapshot['production_release_layout'] : throw new \InvalidArgumentException('A production placement flag is required.'),
            routeDomain: ValidatedData::nullableString($snapshot['route_domain']), onDemandHostStart: is_bool($snapshot['on_demand']) ? $snapshot['on_demand'] : throw new \InvalidArgumentException('An on-demand flag is required.'));
        if (! $target->productionReleaseLayout && $target->user !== $node->user) {
            $this->refuse();
        }
        $account = $snapshot['account'] === null ? null : ValidatedData::object($snapshot['account']);
        $account = $account === null ? null : new ManagedUserAccount(ValidatedData::string($account['user']), ValidatedData::string($account['group']), ValidatedData::string($account['home']));
        $values = $redactions = [];
        foreach ($this->members($batch) as $member) {
            $record = Process::query()->findOrFail(ValidatedData::integer($member['id']));
            if (! Instance::isMorphType($record->owner_type) || $record->owner_id !== $instance->id
                || $record->source_definition_id !== $member['source_definition_id'] || $record->status === LifecycleStatus::Removing) {
                $this->refuse();
            }
            $view = $this->placedProcess($record, $placed->checkout_path, $placed->checkout_path);
            $input = ValidatedData::object($member['input']);
            if ($this->admission->inputForTarget($view, 'create', $target, $account) !== Arr::except($input, ['agent_sha256'])) {
                $this->refuse();
            }
            $value = $this->secrets->materialFor($view, $target, $input, $account);
            $values[(string) $record->id] = ['installation' => $value->value];
            $redactions = [...$redactions, ...$value->strings()];
        }

        return new OperationSecretValue($values, $redactions);
    }

    /** @param array<string,mixed> $batch
     * @return list<array<string,mixed>>
     */
    private function members(array $batch): array
    {
        $members = $batch['members'] ?? null;
        if (! is_array($members) || ! array_is_list($members) || count($members) > 4096) {
            $this->refuse();
        }
        $members = array_map(ValidatedData::object(...), $members);
        if (count(array_unique(array_map(static fn (array $member): int => ValidatedData::integer($member['id']), $members))) !== count($members)) {
            $this->refuse();
        }

        return $members;
    }

    public function placedProcess(Process $process, string $from, string $to): Process
    {
        $move = function (string $path) use ($from, $to): string {
            if ($path === '') {
                return $path;
            }
            if (StoragePath::tryParse($path) === null) {
                $this->refuse();
            }
            if ($path === $from) {
                return $to;
            }
            if (str_starts_with($path, $from.'/')) {
                return $to.substr($path, strlen($from));
            }
            $this->refuse();
        };
        $view = clone $process;
        $view->working_directory = $view->working_directory === null ? null : $move($view->working_directory);
        $config = $view->runtime_config;
        if (is_string($config['environment_file'] ?? null)) {
            $config['environment_file'] = $move($config['environment_file']);
        }
        $view->runtime_config = $config;

        return $view;
    }

    private function refuse(): never
    {
        throw new ResourceOperationException('operation.execution_forbidden', 'The Process batch does not match its admitted Instance placement.', 422);
    }
}
