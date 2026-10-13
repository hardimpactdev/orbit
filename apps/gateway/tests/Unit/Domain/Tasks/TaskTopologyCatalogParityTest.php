<?php

declare(strict_types=1);

use App\Domain\Tasks\TaskTopology;
use App\Domain\TaskVms\TaskVmSize;

/**
 * The harness's node catalog: `apps/e2e/resources/topology-nodes.json`, or, in a checkout from before that file,
 * the constants and `match` arms of `TopologyRecipe`. The live Gateway validates declarations against its own catalog
 * and the group's harness composes them, so both must accept the same entries.
 *
 * @return array{nodes: array<string, array{base_role: string, requires: ?string, memory: ?string}>, extra_roles: list<string>, reserved: array<string, ?string>}
 */
function harness_topology_catalog(): array
{
    $json = dirname(__DIR__, 5).'/e2e/resources/topology-nodes.json';
    if (is_file($json)) {
        $catalog = json_decode((string) file_get_contents($json), true, 16, JSON_THROW_ON_ERROR);
        $nodes = [];
        $reserved = [];
        foreach ($catalog['nodes'] as $key => $node) {
            if (($node['declarable'] ?? true) === false) {
                $reserved[$key] = $node['memory'] ?? null;

                continue;
            }
            $nodes[$key] = ['base_role' => $node['base_role'], 'requires' => $node['requires'] ?? null, 'memory' => $node['memory'] ?? null];
        }

        return ['nodes' => $nodes, 'extra_roles' => $catalog['extra_roles'], 'reserved' => $reserved];
    }

    $source = (string) file_get_contents(dirname(__DIR__, 5).'/e2e/app/E2E/Value/TopologyRecipe.php');
    $list = static function (string $constant) use ($source): array {
        preg_match('/const array '.$constant.' = \[([^\]]*)\];/', $source, $match);
        preg_match_all("/'([^']+)'/", $match[1] ?? '', $items);

        return $items[1];
    };
    $nodes = [];
    foreach ($list('WORKLOADS') as $key) {
        preg_match("/'".preg_quote($key, '/')."' => new TopologyNode\\([^\\n]*\\['([a-z-]+)', \\.\\.\\.\\\$extra\\]\\)/", $source, $arm);
        preg_match("/'The Node \\[".preg_quote($key, '/')."\\] requires \\[([a-z0-9-]+)\\]\\.'/", $source, $requires);
        $nodes[$key] = ['base_role' => $arm[1] ?? '', 'requires' => $requires[1] ?? null, 'memory' => null];
    }

    return ['nodes' => $nodes, 'extra_roles' => $list('EXTRA_ROLES'), 'reserved' => []];
}

it('declares the same nodes, base roles, requirements, and extra roles as the harness', function (): void {
    $harness = harness_topology_catalog();

    expect(array_keys($harness['nodes']))->toBe(array_keys(TaskTopology::Nodes))
        ->and(array_map(static fn (array $node): string => $node['base_role'], $harness['nodes']))->toBe(TaskTopology::Nodes)
        ->and(array_filter(array_map(static fn (array $node): ?string => $node['requires'], $harness['nodes'])))->toBe(TaskTopology::Requires)
        ->and($harness['extra_roles'])->toBe(TaskTopology::ExtraRoles);
});

it('fits the harness memory of the largest topology into each size', function (): void {
    $harness = harness_topology_catalog();
    if ($harness['reserved'] === []) {
        // A harness from before topology-nodes.json keeps memory in its config only.
        expect($harness['nodes'])->not->toBe([]);

        return;
    }
    $mib = static fn (?string $size): int => match (true) {
        $size === null => 0,
        str_ends_with($size, 'GiB') => (int) $size * 1024,
        default => (int) $size,
    };
    $largest = max(array_map(static fn (array $node): int => $mib($node['memory']), $harness['nodes']));
    $fixed = array_sum(array_map($mib, $harness['reserved']));
    foreach (TaskVmSize::cases() as $size) {
        $resources = $size->resources();
        if ($resources->testNodes === 0) {
            continue;
        }
        expect($fixed + $resources->testNodes * $largest)->toBeLessThanOrEqual($mib($resources->memory));
    }
});
