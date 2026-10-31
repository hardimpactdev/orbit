<?php

declare(strict_types=1);

namespace App\Domain\Routes;

use App\Actions\Operations\StartOperationAction;
use App\Domain\Nodes\RoleName;
use App\Domain\Shared\LifecycleStatus;
use App\Domain\Shared\ResourceOperationException;
use App\Models\Node;
use App\Models\Operation;
use App\Models\Route;
use App\Support\ValidatedData;
use Illuminate\Support\Facades\DB;

/** Records a complete withdrawal inventory before the native root starts. */
final readonly class NativeRouteRemovalAdmission
{
    public const int EnvironmentSlots = 64;

    public function __construct(private NativeRouteRemovalIntent $intent, private StartOperationAction $operations) {}

    public function start(string $kind, Route $route, bool $offline = false, ?Node $caller = null, ?string $requestId = null): Operation
    {
        return DB::transaction(fn (): Operation => $this->startOwned($kind, $route, $offline, $caller, $requestId));
    }

    private function startOwned(string $kind, Route $route, bool $offline, ?Node $caller, ?string $requestId): Operation
    {
        $snapshot = $this->intent->capture($route, ['offline' => $offline]);
        $nodeId = $snapshot['nodes'][0]['node_id'] ?? null;
        if ($offline || $nodeId === null) {
            $nodeId = Node::query()->where('status', LifecycleStatus::Active)
                ->whereHas('roles', static fn ($query) => $query->where('role', RoleName::Gateway)->where('status', LifecycleStatus::Active))
                ->orderBy('id')->value('id');
        }
        if (! is_int($nodeId)) {
            throw new ResourceOperationException('route.coordinator_required', 'Route withdrawal requires an admitted coordinator.', 409);
        }
        $nodes = array_values(array_unique([$nodeId, ...array_column($snapshot['nodes'], 'node_id'), ...ValidatedData::integerList($snapshot['dns_node_ids'])]));
        sort($nodes);
        if (count($nodes) > self::EnvironmentSlots) {
            throw new ResourceOperationException('route.participant_limit', 'The Route exceeds the admitted participant limit.', 422);
        }
        $fields = $bindings = $references = [];
        foreach (range(0, self::EnvironmentSlots - 1) as $slot) {
            $field = 'environment_sync_node_'.$slot;
            $owner = $nodes[$slot] ?? $nodeId;
            $used = isset($nodes[$slot]);
            $bindings[$field] = ['owner_node_id' => $owner, 'read_node_ids' => $used ? [$owner] : [], 'write_node_ids' => []];
            if ($used) {
                $fields[(string) $owner] = $field;
                $references[$field] = ['$secret' => 'route.environment_sync', 'id' => $route->id];
            }
        }
        $this->intent->beginWithdrawal($route);

        return $this->operations->execute($kind, 'route', $route->id, $nodeId, [
            'route_id' => $route->id, 'route_removal' => $snapshot, 'offline' => $offline,
            'node_ids' => $nodes, 'participant_node_ids' => $nodes,
            'access_node_ids' => $nodes, 'caller_node_id' => $caller?->id,
            'environment_fields' => $fields, 'private_bindings' => $bindings, ...$references,
        ], 'operator', $requestId);
    }

    /** @return array<string,string> */
    public static function privateMaterial(): array
    {
        $fields = [];
        foreach (range(0, self::EnvironmentSlots - 1) as $slot) {
            $fields['environment_sync_node_'.$slot] = 'route.environment_sync';
        }

        return $fields;
    }
}
