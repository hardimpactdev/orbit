<?php

declare(strict_types=1);

use App\Domain\Shared\ResourceOperationException;
use App\Domain\Tasks\TaskPromptGroup;
use App\Domain\Tasks\TaskPromptRenderer;
use App\Domain\Tasks\TaskPromptSubtask;
use App\Domain\Tasks\TaskTopology;
use App\Domain\Tasks\TaskTopologyEvidence;
use App\Domain\TaskVms\TaskVmSize;
use Carbon\CarbonImmutable;

it('stores entries with their roles in canonical order', function (): void {
    expect(TaskTopology::from(['app-prod+websocket+ingress', 'app-dev']))->toBe(['app-prod+ingress+websocket', 'app-dev'])
        ->and(TaskTopology::nodes(['app-prod+ingress', 'app-dev+database']))->toBe(['app-prod', 'app-dev']);
});

it('refuses entries that do not fit', function (mixed $topology): void {
    expect(TaskTopology::valid($topology))->toBeFalse()
        ->and(fn () => TaskTopology::from($topology))->toThrow(ResourceOperationException::class, TaskTopology::Invalid);
})->with([
    'unknown node' => [['gateway']], 'unknown role' => [['app-dev+router']], 'base role as extra' => [['app-prod+app-prod']],
    'ingress on app-dev' => [['app-dev+ingress']], 'database on app-prod' => [['app-prod+database']],
    'repeated node' => [['app-dev', 'app-dev+database']], 'second production node alone' => [['app-prod-2']],
    'too many' => [['app-dev', 'app-prod', 'app-prod-2', 'app-dev']], 'not a list' => [['a' => 'app-dev']],
]);

it('unites declarations per node, each of which must be valid on its own', function (): void {
    expect(TaskTopology::union([['app-prod+ingress'], null, ['app-dev', 'app-prod+websocket'], []]))->toBe(['app-dev', 'app-prod+ingress+websocket'])
        ->and(TaskTopology::covers(['app-dev', 'app-prod+ingress+websocket'], ['app-prod+ingress']))->toBeTrue()
        ->and(TaskTopology::covers(['app-dev'], ['app-dev+database']))->toBeFalse()
        ->and(TaskTopology::union([['app-dev+database'], ['app-dev+websocket', 'app-prod']]))->toBe(['app-dev+database+websocket', 'app-prod'])
        ->and(fn () => TaskTopology::union([['app-prod'], ['app-prod-2']]))->toThrow(ResourceOperationException::class, TaskTopology::Invalid);
});

it('sizes a VM by its test nodes, not by their roles', function (array $topology, TaskVmSize $size): void {
    expect(TaskVmSize::forTopology($topology))->toBe($size);
})->with([
    'none' => [[], TaskVmSize::Normal],
    'one' => [['app-prod+ingress+websocket'], TaskVmSize::Medium],
    'two' => [['app-dev', 'app-prod'], TaskVmSize::Large],
]);

it('holds a topology only in a VM at least its size', function (): void {
    expect(TaskVmSize::Normal->holds(['app-dev']))->toBeFalse()
        ->and(TaskVmSize::Medium->holds(['app-dev+database']))->toBeTrue()
        ->and(TaskVmSize::Medium->holds(['app-dev', 'app-prod']))->toBeFalse()
        ->and(TaskVmSize::Large->holds(['app-dev', 'app-prod', 'app-prod-2']))->toBeTrue()
        ->and(TaskVmSize::Normal->holds([]))->toBeTrue();
});

it('counts only successful evidence entries since the handoff, per declared node', function (): void {
    $log = implode("\n", [
        '=== 2026-10-13T09:59:59.999Z early node=app-dev exit=0 duration=1ms end=2026-10-13T10:00:00.000Z',
        '=== 2026-10-13T10:00:00.000Z ingress probe node=app-prod exit=0 duration=1ms end=2026-10-13T10:00:00.001Z',
        '=== 2026-10-13T10:05:00.000Z failed node=app-dev exit=2 duration=1ms end=2026-10-13T10:05:00.001Z',
        '$ orbit node:list',
        '=== not a header node=app-dev exit=0',
    ]);

    expect(TaskTopologyEvidence::missing($log, ['app-dev', 'app-prod'], CarbonImmutable::parse('2026-10-13T10:00:00Z')))->toBe(['app-dev'])
        ->and(TaskTopologyEvidence::missing('', ['app-dev'], CarbonImmutable::now()))->toBe(['app-dev']);
});

it('tells the implementer about the declared topology, and nothing without one', function (): void {
    $group = new TaskPromptGroup(id: 7, title: 'Nodes', brief: 'Brief', projectSlug: 'orbit', projectId: 46, defaultBranch: 'main', taskCheck: null, startCommit: null);

    expect(TaskPromptRenderer::implementer($group, new TaskPromptSubtask(1, 'Prove', 'Brief', 1, [], ['app-prod+ingress'])))
        ->toContain('This subtask declares the topology app-prod+ingress. Orbit prepared it nested in this task VM as TASK-7')
        ->toContain('The reviewer must prove the deliverables on app-prod')
        ->and(TaskPromptRenderer::implementer($group, new TaskPromptSubtask(1, 'Plain', 'Brief', 1, [])))
        ->not->toContain('declares the topology')->not->toContain('topology_requested');
});
