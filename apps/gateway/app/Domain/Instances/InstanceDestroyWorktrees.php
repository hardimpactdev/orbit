<?php

declare(strict_types=1);

namespace App\Domain\Instances;

use App\Domain\Nodes\NodeAccessAuthorizer;
use App\Domain\RemotePrograms\RemoteProgramFailed;
use App\Domain\RemotePrograms\RemoteProgramRunner;
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

    public function assertAvailable(Instance $instance): void
    {
        if ($instance->runtime()->exists()) {
            return;
        }
        $instances = Instance::query()->where('node_id', $instance->node_id)->whereDoesntHave('runtime')
            ->whereNotNull('checkout_path')->orderBy('id')->get();
        $program = new RemoteProgramCatalog(base_path('../../swamp/ansible'))->program('instance.destroy-git');
        try {
            $result = $this->reader->run($instance->node, $program, ['action' => 'inspect', 'paths' => [$instance->checkout_path],
                'instances' => $instances->map(static fn (Instance $row): array => ['id' => $row->id, 'path' => $row->checkout_path])->all()]);
        } catch (RemoteProgramFailed $exception) {
            throw new ResourceOperationException('instance.host_unavailable', 'The Node could not be read for safe checkout removal.', 409,
                previous: $exception, details: ['reason' => $exception->reason->value]);
        }
        if ($result->outcome !== 'inspected' || ! is_array($result->data['dependents'] ?? null)) {
            throw new ResourceOperationException('instance.remove_refused', 'Git dependencies could not be read safely.', 409, details: ['reason' => 'git_error']);
        }
        $consumer = RequireActiveWireGuardPeer::activePeer(request());
        if ($result->data['dependents'] !== []) {
            throw new ResourceOperationException('instance.worktree_dependents', 'Other worktrees depend on Git data in this checkout.', 409,
                details: $this->details($instance, $result->data['dependents'], $consumer instanceof Node ? 'node:'.$consumer->id : null));
        }
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
            'other_dependents' => $visible ? 0 : $rows->count(), 'unowned_paths' => array_values(array_unique($unowned))];
    }
}
