<?php

declare(strict_types=1);

use App\Domain\Shared\ResourceOperationException;
use App\Domain\Tasks\TaskPromptGroup;
use App\Domain\Tasks\TaskPromptRenderer;
use App\Domain\Tasks\TaskPromptSubtask;
use App\Domain\Tasks\TaskTopology;
use App\Domain\Tasks\TaskTopologyEvidence;
use App\Domain\Tasks\TaskTurnInstructions;
use App\Domain\TaskVms\TaskVmSize;
use Carbon\CarbonImmutable;

it('stores entries with their roles in canonical order', function (): void {
    $topology = TaskTopology::fromInput(['app-prod+ingress', 'app-dev']);

    expect($topology->toArray())->toBe(['app-prod+ingress', 'app-dev'])
        ->and($topology->nodes())->toBe(['app-prod', 'app-dev'])
        ->and($topology->count())->toBe(2)
        ->and(TaskTopology::fromInput([])->isEmpty())->toBeTrue();
});

it('refuses entries that do not fit', function (mixed $topology): void {
    expect(TaskTopology::valid($topology))->toBeFalse()
        ->and(fn () => TaskTopology::fromInput($topology))->toThrow(ResourceOperationException::class, TaskTopology::invalidMessage());
})->with([
    'unknown node' => [['gateway']], 'unknown role' => [['app-prod+websocket']], 'base role as extra' => [['app-prod+app-prod']],
    'ingress on app-dev' => [['app-dev+ingress']], 'repeated role' => [['app-prod+ingress+ingress']],
    'repeated node' => [['app-prod', 'app-prod+ingress']], 'second production node alone' => [['app-prod-2']],
    'three nodes' => [['app-dev', 'app-prod', 'app-prod-2']], 'not a list' => [['a' => 'app-dev']], 'scalar' => ['app-dev'],
]);

it('trusts a stored declaration, also one the catalog no longer accepts', function (): void {
    $stored = TaskTopology::fromStored(['app-prod-3+database', 'app-dev']);

    expect($stored->toArray())->toBe(['app-prod-3+database', 'app-dev'])
        ->and(TaskTopology::union([$stored])->toArray())->toBe(['app-dev', 'app-prod-3+database'])
        ->and(TaskTopology::fromStored(null)->isEmpty())->toBeTrue();
});

it('unites declarations per node and refuses a union larger than the largest VM', function (): void {
    $topology = static fn (array $entries): TaskTopology => TaskTopology::fromInput($entries);

    expect(TaskTopology::union([$topology(['app-prod+ingress']), new TaskTopology, $topology(['app-dev', 'app-prod'])])->toArray())->toBe(['app-dev', 'app-prod+ingress'])
        ->and($topology(['app-dev', 'app-prod+ingress'])->covers($topology(['app-prod'])))->toBeTrue()
        ->and($topology(['app-prod'])->covers($topology(['app-prod+ingress'])))->toBeFalse()
        ->and(TaskTopology::union([$topology(['app-prod', 'app-prod-2+ingress']), $topology(['app-prod'])])->toArray())->toBe(['app-prod', 'app-prod-2+ingress'])
        ->and(fn () => TaskTopology::assertFits([$topology(['app-prod', 'app-prod-2']), $topology(['app-dev'])]))->toThrow(ResourceOperationException::class, TaskTopology::tooManyMessage());
    TaskTopology::assertFits([$topology(['app-prod']), $topology(['app-dev']), $topology(['app-prod+ingress'])]);
});

it('derives the entry pattern and the messages from the catalog', function (): void {
    expect(preg_match('#\A/\\\\A\(\?:([^)]+)\)\(\?:\\\\\+([^)]+)\)\?\\\\z/\z#', TaskTopology::EntryPattern, $match))->toBe(1)
        ->and(explode('|', $match[1]))->toBe(array_keys(TaskTopology::Nodes))
        ->and(explode('|', $match[2]))->toBe(TaskTopology::ExtraRoles);
    foreach ([...array_keys(TaskTopology::Nodes), 'gateway', 'operator', 'app-prod-3'] as $node) {
        foreach (['', ...array_map(static fn (string $role): string => '+'.$role, [...TaskTopology::ExtraRoles, 'websocket'])] as $roles) {
            $catalog = isset(TaskTopology::Nodes[$node]) && ($roles === '' || in_array(substr($roles, 1), TaskTopology::ExtraRoles, true));
            expect(preg_match(TaskTopology::EntryPattern, $node.$roles))->toBe($catalog ? 1 : 0);
        }
    }

    expect(TaskTopology::invalidMessage())->toBe('Topology must be a list of at most 2 distinct app-dev, app-prod, or app-prod-2 nodes, each with an optional +ingress role where it fits the node; app-prod-2 needs app-prod.')
        ->and(TaskTopology::tooManyMessage())->toBe('The subtasks of a group declare more than 2 test nodes together. The largest task VM holds 2: split the work into groups.');
});

it('takes the most nodes from the largest VM size', function (): void {
    $largest = max(array_map(static fn (TaskVmSize $size): int => $size->resources()->testNodes, TaskVmSize::cases()));

    expect(TaskTopology::maxNodes())->toBe($largest)
        ->and(TaskVmSize::cases()[array_key_last(TaskVmSize::cases())]->resources()->testNodes)->toBe($largest);
});

it('sizes a VM by its test nodes, not by their roles', function (array $topology, TaskVmSize $size): void {
    expect(TaskVmSize::forTopology(TaskTopology::fromInput($topology)))->toBe($size);
})->with([
    'none' => [[], TaskVmSize::Normal],
    'one' => [['app-prod+ingress'], TaskVmSize::Medium],
    'two' => [['app-dev', 'app-prod'], TaskVmSize::Large],
]);

it('holds a topology only in a VM at least its size, with its inner pool', function (): void {
    $topology = static fn (array $entries): TaskTopology => TaskTopology::fromInput($entries);

    expect(TaskVmSize::Normal->holds($topology(['app-dev'])))->toBeFalse()
        ->and(TaskVmSize::Medium->holds($topology(['app-prod+ingress'])))->toBeTrue()
        ->and(TaskVmSize::Medium->holds($topology(['app-dev', 'app-prod'])))->toBeFalse()
        ->and(TaskVmSize::Large->holds($topology(['app-prod', 'app-prod-2+ingress'])))->toBeTrue()
        ->and(TaskVmSize::Normal->holds(new TaskTopology))->toBeTrue()
        ->and(TaskVmSize::forTopology(TaskTopology::fromStored(['app-dev', 'app-prod', 'app-prod-2'])))->toBe(TaskVmSize::Large)
        ->and(array_map(static fn (TaskVmSize $size): ?string => $size->resources()->topologyPool, TaskVmSize::cases()))->toBe([null, '25GiB', '45GiB']);
});

/** One JSON line of `.e2e/evidence.jsonl` in the harness's shape. */
function evidence_line(string $start, string $label, string $node, array $argv, int $exit = 0): string
{
    return json_encode(['v' => 1, 'start' => $start, 'end' => $start, 'duration_ms' => 5, 'label' => $label, 'node' => $node, 'exit' => $exit, 'argv' => $argv], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES)."\n";
}

describe('JSON topology evidence', function (): void {
    it('directs reviewers to the JSON evidence file written by the harness', function (): void {
        expect(TaskTurnInstructions::topology(7, TaskTopology::fromInput(['app-dev']), reviewer: true))
            ->toContain('`--record` appends to .e2e/evidence.jsonl');
    });

    it('keeps a UTF-8 label whose bytes contain 0x85 in one record', function (): void {
        $line = json_encode(['v' => 1, 'start' => '2026-10-13T10:01:00Z', 'end' => '2026-10-13T10:01:00Z', 'label' => 'Ångström probe', 'node' => 'app-dev', 'exit' => 0, 'argv' => ['orbit', 'node:list']], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);

        $evidence = TaskTopologyEvidence::fromJsonLines($line."\n", CarbonImmutable::parse('2026-10-13T10:00:00Z'), CarbonImmutable::parse('2026-10-13T11:00:00Z'));

        expect($evidence->missing(['app-dev']))->toBe([])
            ->and($evidence->summary(['app-dev']))->toContain('Ångström probe');
    });

    it('points a truncated summary to the JSON evidence file', function (): void {
        $lines = str_repeat(evidence_line('2026-10-13T10:01:00Z', 'probe', 'app-dev', ['orbit', 'node:list']), 21);

        $evidence = TaskTopologyEvidence::fromJsonLines($lines, CarbonImmutable::parse('2026-10-13T10:00:00Z'), CarbonImmutable::parse('2026-10-13T11:00:00Z'));

        expect($evidence->summary(['app-dev']))->toEndWith('- and 1 more in .e2e/evidence.jsonl');
    });
});

it('counts JSON records that exited 0, did work, and started between the handoff and now', function (): void {
    $lines = '{"v":1,"start":"2026-10-13T10:01:00.000Z","label":"cut'."\n"
        .evidence_line('2026-10-13T09:59:59.999Z', 'before handoff', 'app-dev', ['orbit', 'node:list'])
        .evidence_line('2026-10-13T10:00:00.000Z', 'ingress probe', 'app-prod', ['systemctl', 'is-active', 'caddy'])
        .evidence_line('2026-10-13T10:05:00.000Z', 'failed', 'app-dev', ['orbit', 'node:list'], exit: 2)
        .evidence_line('2026-10-13T10:06:00.000Z', 'nothing', 'app-dev', ['true'])
        .evidence_line('2026-10-13T10:07:00.000Z', 'shell nothing', 'app-dev', ['sh', '-c', ':'])
        .evidence_line('2026-10-13T10:08:00.000Z', 'echo', 'app-dev', ['/bin/bash', '-c', 'echo done'])
        .evidence_line('2026-10-13T12:00:00.000Z', 'future', 'app-dev', ['orbit', 'node:list'])
        .str_replace('"v":1', '"v":2', evidence_line('2026-10-13T10:09:00.000Z', 'other version', 'app-dev', ['orbit', 'node:list']))
        .str_replace('["orbit","node:list"]', '"orbit node:list"', evidence_line('2026-10-13T10:09:00.000Z', 'argv string', 'app-dev', ['orbit', 'node:list']))
        .evidence_line('2026-10-13T10:10:00.000Z', 'bad node', 'App Dev', ['orbit', 'node:list']);
    $evidence = TaskTopologyEvidence::fromJsonLines($lines, CarbonImmutable::parse('2026-10-13T10:00:00Z'), CarbonImmutable::parse('2026-10-13T11:00:00Z'));

    expect($evidence->missing(['app-dev', 'app-prod']))->toBe(['app-dev'])
        ->and($evidence->summary(['app-prod']))->toBe("Topology evidence (exit 0, recorded by bin/e2e-topology):\n- 2026-10-13T10:00:00.000Z app-prod \"ingress probe\": systemctl is-active caddy");
});

it('counts a shell script that does work and shows its argv quoted', function (): void {
    $evidence = TaskTopologyEvidence::fromJsonLines(
        evidence_line('2026-10-13T10:01:00.000Z', 'curl', 'app-prod', ['sh', '-c', "curl -fsS http://localhost/ | grep 'It works'"]),
        CarbonImmutable::parse('2026-10-13T10:00:00Z'),
        CarbonImmutable::parse('2026-10-13T11:00:00Z'),
    );

    expect($evidence->missing(['app-prod']))->toBe([])
        ->and($evidence->summary(['app-prod']))->toEndWith(": sh -c 'curl -fsS http://localhost/ | grep '\\''It works'\\'''");
});

/** One evidence log entry in the harness's shape. */
it('tells the implementer about the declared topology, and nothing without one', function (): void {
    $group = new TaskPromptGroup(id: 7, title: 'Nodes', brief: 'Brief', projectSlug: 'orbit', projectId: 46, defaultBranch: 'main', taskCheck: null, startCommit: null);

    expect(TaskPromptRenderer::implementer($group, new TaskPromptSubtask(1, 'Prove', 'Brief', 1, [], TaskTopology::fromInput(['app-prod+ingress']))))
        ->toContain('This subtask declares the topology app-prod+ingress. Orbit prepared it nested in this task VM as TASK-7')
        ->toContain('The reviewer must prove the deliverables on app-prod')
        ->and(TaskPromptRenderer::implementer($group, new TaskPromptSubtask(1, 'Plain', 'Brief', 1, [])))
        ->not->toContain('declares the topology')->not->toContain('topology_requested');
});
