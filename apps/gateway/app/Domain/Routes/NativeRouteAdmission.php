<?php

declare(strict_types=1);

namespace App\Domain\Routes;

use App\Models\Route;
use App\Support\ValidatedData;

/** Capture admitted resources before StartSwampRun. No executable Gateway plan. */
final readonly class NativeRouteAdmission
{
    public const int EnvironmentSlots = 64;

    /** @param array<string,mixed> $input
     * @return array<string,mixed>
     */
    public function capture(string $kind, Route $route, array $input, int $rootNodeId): array
    {
        $intent = match ($kind) {
            'route.destroy', 'route.withdraw' => ['route_removal' => app(NativeRouteRemovalIntent::class)->forRoute($route, $input)],
            'route.target_set' => ['route_target' => app(NativeRouteTargetIntent::class)->forRoute($route, $input)],
            default => [],
        };
        $resource = ValidatedData::object(reset($intent) ?: []);
        $nodes = ValidatedData::integerList($resource['node_ids'] ?? array_values(array_unique([
            ...array_map(static fn (array $node): int => ValidatedData::integer($node['node_id']), RouteExecutionProof::objects($resource['nodes'] ?? [])),
            ...ValidatedData::integerList($resource['dns_node_ids'] ?? []),
        ])));
        $leaves = RouteExecutionProof::objects($resource['certificates'] ?? []);
        foreach (['previous', 'desired', 'cleanup'] as $side) {
            $member = ValidatedData::object($resource[$side] ?? []);
            $leaves = [...$leaves, ...RouteExecutionProof::objects($member['certificates'] ?? [])];
        }
        $nodes = array_values(array_unique([$rootNodeId, ...$nodes, ...($leaves !== [] ? [209] : [])]));
        sort($nodes);
        $fields = [];
        $bindings = [];
        $references = [];
        foreach (range(0, self::EnvironmentSlots - 1) as $slot) {
            $field = 'environment_sync_node_'.$slot;
            $nodeId = $nodes[$slot] ?? $rootNodeId;
            $used = array_key_exists($slot, $nodes);
            $bindings[$field] = ['owner_node_id' => $nodeId, 'read_node_ids' => $used ? [$nodeId] : [], 'write_node_ids' => []];
            if ($used) {
                $fields[(string) $nodeId] = $field;
                $references[$field] = ['$secret' => 'route.environment_sync', 'id' => $route->id];
            }
        }

        return [...$input, ...$intent, 'participant_node_ids' => $nodes, 'certificates' => array_values(array_unique($leaves, SORT_REGULAR)),
            'environment_fields' => $fields, 'private_bindings' => $bindings, ...$references];
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
