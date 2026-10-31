<?php

declare(strict_types=1);

use App\Domain\Operations\GatewayWorkflowGrantCheck;

it('requires both run and read for each exact Gateway workflow while sharing aliases', function (): void {
    $definitions = [
        'process.start' => ['workflow' => 'operation-process-control'],
        'process.restart' => ['workflow' => 'operation-process-control'],
    ];
    $policy = ['version' => 1, 'principal' => 'user:orbit-gateway', 'workflows' => [
        ['workflow' => 'operation-process-control', 'actions' => ['read', 'run']],
    ]];

    expect((new GatewayWorkflowGrantCheck)->execute($definitions, $policy))->toBe(1);
});

it('rejects a newly declared Horizon workflow without a matching grant intent', function (): void {
    $definitions = ['process.horizon_handoff' => ['workflow' => 'operation-process-horizon-handoff']];
    $policy = ['version' => 1, 'principal' => 'user:orbit-gateway', 'workflows' => []];

    expect(fn () => (new GatewayWorkflowGrantCheck)->execute($definitions, $policy))
        ->toThrow(LogicException::class, 'operation-process-horizon-handoff');
});

it('rejects run-only and read-only intent rather than implying the other action', function (array $actions): void {
    $definitions = ['process.stop' => ['workflow' => 'operation-process-stop']];
    $policy = ['version' => 1, 'principal' => 'user:orbit-gateway', 'workflows' => [
        ['workflow' => 'operation-process-stop', 'actions' => $actions],
    ]];

    expect(fn () => (new GatewayWorkflowGrantCheck)->execute($definitions, $policy))
        ->toThrow(LogicException::class, 'run and read');
})->with([['actions' => ['run']], ['actions' => ['read']]]);

it('rejects broader or malformed intended policy', function (array $change): void {
    $definitions = ['process.stop' => ['workflow' => 'operation-process-stop']];
    $policy = ['version' => 1, 'principal' => 'user:orbit-gateway', 'workflows' => [
        ['workflow' => 'operation-process-stop', 'actions' => ['run', 'read']],
    ]];

    expect(fn () => (new GatewayWorkflowGrantCheck)->execute($definitions, array_replace($policy, $change)))
        ->toThrow(LogicException::class);
})->with([
    'different principal' => [['principal' => 'user:other']],
    'other schema' => [['version' => 2]],
    'extra authority field' => [['credentials' => []]],
    'non-list entries' => [['workflows' => ['named' => ['workflow' => 'operation-process-stop', 'actions' => ['run', 'read']]]]],
    'wildcard' => [['workflows' => [['workflow' => '*', 'actions' => ['run', 'read']]]]],
    'broader action' => [['workflows' => [['workflow' => 'operation-process-stop', 'actions' => ['run', 'read', 'write']]]]],
    'conditional entry' => [['workflows' => [['workflow' => 'operation-process-stop', 'actions' => ['run', 'read'], 'condition' => 'true']]]],
    'duplicate action' => [['workflows' => [['workflow' => 'operation-process-stop', 'actions' => ['run', 'run', 'read']]]]],
    'duplicate workflow' => [['workflows' => [
        ['workflow' => 'operation-process-stop', 'actions' => ['run', 'read']],
        ['workflow' => 'operation-process-stop', 'actions' => ['run', 'read']],
    ]]],
]);

it('rejects retired workflow grant intent after its final declaration is removed', function (): void {
    $policy = ['version' => 1, 'principal' => 'user:orbit-gateway', 'workflows' => [
        ['workflow' => 'operation-retired', 'actions' => ['run', 'read']],
    ]];

    expect(fn () => (new GatewayWorkflowGrantCheck)->execute([], $policy))
        ->toThrow(LogicException::class, 'operation-retired');
});
