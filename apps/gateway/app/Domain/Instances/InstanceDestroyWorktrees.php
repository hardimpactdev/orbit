<?php

declare(strict_types=1);

namespace App\Domain\Instances;

use App\Domain\Nodes\NodeAccessAuthorizer;
use App\Domain\RemotePrograms\RemoteProgramFailed;
use App\Domain\RemotePrograms\RemoteProgramRunner;
use App\Domain\Shared\LifecycleStatus;
use App\Domain\Shared\ResourceOperationException;
use App\Http\Middleware\RequireActiveWireGuardPeer;
use App\Infrastructure\RemotePrograms\RemoteProgramCatalog;
use App\Models\Instance;
use App\Models\Node;
use App\Support\ValidatedData;

/** Read-only admission through the existing SSH reader, before any removal side effect. */
final readonly class InstanceDestroyWorktrees
{
    public function __construct(private RemoteProgramRunner $reader, private NodeAccessAuthorizer $access) {}

    /** @return array{checkout_instances:list<array{id:int,path:string}>,checkout_warnings:list<array<string,mixed>>} */
    public function assertAvailable(Instance $instance): array
    {
        if ($instance->runtime()->exists() || $instance->checkout_path === ''
            || $instance->getAttribute('checkout_path') === null || $instance->status === InstanceState::Reserved
            || $instance->node->status !== LifecycleStatus::Active) {
            return ['checkout_instances' => [], 'checkout_warnings' => []];
        }
        $inventory = $this->inventory($instance);
        $program = new RemoteProgramCatalog(base_path('../../swamp/ansible'))->program('instance.destroy-git');
        try {
            $result = $this->reader->run($instance->node, $program, ['action' => 'inspect', 'paths' => [$instance->checkout_path],
                'instances' => $inventory]);
        } catch (RemoteProgramFailed $exception) {
            throw new ResourceOperationException('instance.host_unavailable', 'The Node could not be read for safe checkout removal.', 409,
                previous: $exception, details: ['reason' => $exception->reason->value]);
        }
        if ($result->outcome !== 'inspected' || ! is_array($result->data['dependents'] ?? null)) {
            $details = $this->failureDetails($instance, $result->data);
            throw new ResourceOperationException('instance.remove_refused', $this->failureMessage($details), 409, details: $details);
        }
        $consumer = RequireActiveWireGuardPeer::activePeer(request());
        if ($result->data['dependents'] !== []) {
            throw new ResourceOperationException('instance.worktree_dependents', 'Other worktrees depend on Git data in this checkout.', 409,
                details: $this->details($instance, $result->data['dependents'], $consumer instanceof Node ? 'node:'.$consumer->id : null));
        }

        return ['checkout_instances' => $inventory, 'checkout_warnings' => $this->warnings($instance, $result->data['warnings'] ?? [])];
    }

    /** @return list<array{id:int,path:string}> */
    public function inventory(Instance $instance): array
    {
        return array_values(Instance::query()->where('node_id', $instance->node_id)->whereDoesntHave('runtime')
            ->whereNotNull('checkout_path')->orderBy('id')->get()
            ->reject(static fn (Instance $row): bool => $row->checkout_path === InstanceContainer::workspaceOnNode($row))->map(static fn (Instance $row): array => ['id' => $row->id, 'path' => (string) $row->checkout_path])->all());
    }

    /**
     * @return list<array<string,mixed>>
     */
    public function warnings(Instance $instance, mixed $raw): array
    {
        $warnings = [];
        foreach (array_slice(is_array($raw) ? $raw : [], 0, 20) as $item) {
            if (! is_array($item) || ($item['code'] ?? null) !== 'instance.checkout_unknown') {
                continue;
            }
            $details = $this->failureDetails($instance, $item);
            if (isset($details['path'], $details['cause'])) {
                $warnings[] = ['code' => 'instance.checkout_unknown', 'path' => $details['path'], 'cause' => $details['cause'], 'instance_ids' => $details['instance_ids']];
            }
        }

        return $warnings;
    }

    /** @param array<array-key,mixed> $raw
     * @return array<string,mixed>
     */
    public function failureDetails(Instance $instance, array $raw): array
    {
        $details = ['reason' => 'git_error'];
        $path = $raw['path'] ?? null;
        if (is_string($path) && str_starts_with($path, '/') && strlen($path) <= 4096
            && in_array($raw['cause'] ?? null, ['unreadable', 'timed_out'], true)) {
            $details = [...$details, 'path' => $path, 'cause' => $raw['cause'], 'instance_ids' => Instance::query()->where('node_id', $instance->node_id)->where('checkout_path', $path)->orderBy('id')->limit(50)->pluck('id')->all()];
        }

        return $details;
    }

    /** @param array<string,mixed> $details */
    public function failureMessage(array $details): string
    {
        if (! is_string($details['path'] ?? null) || ! is_string($details['cause'] ?? null)) {
            return 'Git dependencies could not be read safely.';
        }
        $ids = ValidatedData::integerList($details['instance_ids'] ?? []);

        return 'Cannot read checkout '.mb_strcut($details['path'], 0, 256).($ids === [] ? '' : ' (Instance #'.implode(', #', $ids).')').': '.$details['cause'].'.';
    }

    /** @param array<array-key, mixed> $dependencies
     * @return array{dependents:list<array{id:int,name:string,project_id:int}>,other_dependents:int,unowned_paths:list<string>}
     */
    public function details(Instance $instance, array $dependencies, ?string $caller): array
    {
        $node = $instance->node;

        $consumer = is_string($caller) && preg_match('/\Anode:([1-9][0-9]*)\z/D', $caller, $match) === 1
            ? Node::query()->find((int) $match[1]) : null;
        $visible = $caller === null || ($consumer instanceof Node && $this->access->allows($consumer, $node));
        $ids = [];
        $unowned = [];
        foreach ($dependencies as $raw) {
            $dependency = ValidatedData::object($raw);
            $members = ValidatedData::integerList($dependency['instance_ids'] ?? []);
            if ($members === []) {
                // A dependency created after admission is mapped again when the source recheck refuses.
                $members = ValidatedData::integerList(Instance::query()->where('node_id', $node->id)->whereDoesntHave('runtime')
                    ->where('checkout_path', ValidatedData::string($dependency['path']))->pluck('id')->all());
            }
            if ($members === []) {
                $unowned[] = ValidatedData::string($dependency['path']);
            }
            $ids = [...$ids, ...$members];
        }
        $rows = Instance::query()->where('node_id', $node->id)->whereKey(array_unique($ids))->orderBy('id')->get();

        return ['dependents' => $visible ? array_values($rows->map(static fn (Instance $row): array => ['id' => $row->id, 'name' => $row->name, 'project_id' => $row->project_id])->all()) : [],
            'other_dependents' => $visible ? 0 : $rows->count(), 'unowned_paths' => array_values(array_unique($unowned)),
            'dependent_paths' => $visible ? array_values(array_unique(array_slice(array_map(static fn (mixed $raw): string => ValidatedData::string(ValidatedData::object($raw)['path']), $dependencies), 0, 50))) : array_values(array_unique($unowned))];
    }
}
