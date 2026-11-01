<?php

declare(strict_types=1);

use App\Domain\Operations\GatewayWorkflowGrantCheck;

it('requires both run and read for each exact Gateway workflow while sharing aliases', function (): void {
    $definitions = [
        'process.start' => ['workflow' => 'operation-process-control'],
        'process.restart' => ['workflow' => 'operation-process-control'],
    ];
    $policy = ['version' => 2, 'vaults' => [], 'principal' => 'user:orbit-gateway', 'workflows' => [
        ['workflow' => 'operation-process-control', 'actions' => ['read', 'run']],
    ]];

    expect((new GatewayWorkflowGrantCheck)->execute($definitions, $policy))->toBe(1);
});

it('rejects a newly declared Horizon workflow without a matching grant intent', function (): void {
    $definitions = ['process.horizon_handoff' => ['workflow' => 'operation-process-horizon-handoff']];
    $policy = ['version' => 2, 'vaults' => [], 'principal' => 'user:orbit-gateway', 'workflows' => []];

    expect(fn () => (new GatewayWorkflowGrantCheck)->execute($definitions, $policy))
        ->toThrow(LogicException::class, 'operation-process-horizon-handoff');
});

it('rejects run-only and read-only intent rather than implying the other action', function (array $actions): void {
    $definitions = ['process.stop' => ['workflow' => 'operation-process-stop']];
    $policy = ['version' => 2, 'vaults' => [], 'principal' => 'user:orbit-gateway', 'workflows' => [
        ['workflow' => 'operation-process-stop', 'actions' => $actions],
    ]];

    expect(fn () => (new GatewayWorkflowGrantCheck)->execute($definitions, $policy))
        ->toThrow(LogicException::class, 'run and read');
})->with([['actions' => ['run']], ['actions' => ['read']]]);

it('rejects broader or malformed intended policy', function (array $change): void {
    $definitions = ['process.stop' => ['workflow' => 'operation-process-stop']];
    $policy = ['version' => 2, 'vaults' => [], 'principal' => 'user:orbit-gateway', 'workflows' => [
        ['workflow' => 'operation-process-stop', 'actions' => ['run', 'read']],
    ]];

    expect(fn () => (new GatewayWorkflowGrantCheck)->execute($definitions, array_replace($policy, $change)))
        ->toThrow(LogicException::class);
})->with([
    'different principal' => [['principal' => 'user:other']],
    'other schema' => [['version' => 3]],
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
    $policy = ['version' => 2, 'vaults' => [], 'principal' => 'user:orbit-gateway', 'workflows' => [
        ['workflow' => 'operation-retired', 'actions' => ['run', 'read']],
    ]];

    expect(fn () => (new GatewayWorkflowGrantCheck)->execute([], $policy))
        ->toThrow(LogicException::class, 'operation-retired');
});

it('checks exact execution copy vault intent against public declarations', function (): void {
    $copy = ['vault' => 'orbit-control-inputs', 'key_pattern' => '^database-write-intent-[1-9][0-9]*$'];
    $definitions = ['database.write' => ['workflow' => 'operation-database-write', 'execution_copies' => [$copy]]];
    $policy = ['version' => 2, 'principal' => 'user:orbit-gateway',
        'workflows' => [['workflow' => 'operation-database-write', 'actions' => ['run', 'read']]],
        'vaults' => [[...$copy, 'actions' => ['write', 'read']]]];

    expect((new GatewayWorkflowGrantCheck)->execute($definitions, $policy))->toBe(1);
});

it('refuses missing or broader execution copy vault intent', function (array $entry): void {
    $copy = ['vault' => 'orbit-control-inputs', 'key_pattern' => '^database-write-intent-[1-9][0-9]*$'];
    $definitions = ['database.write' => ['workflow' => 'operation-database-write', 'execution_copies' => [$copy]]];
    $policy = ['version' => 2, 'principal' => 'user:orbit-gateway',
        'workflows' => [['workflow' => 'operation-database-write', 'actions' => ['run', 'read']]],
        'vaults' => $entry];

    expect(fn () => (new GatewayWorkflowGrantCheck)->execute($definitions, $policy))->toThrow(LogicException::class);
})->with([
    'missing' => [[]],
    'broader pattern' => [[['vault' => 'orbit-control-inputs', 'key_pattern' => '^.*$', 'actions' => ['write', 'read']]]],
    'other vault' => [[['vault' => 'other', 'key_pattern' => '^database-write-intent-[1-9][0-9]*$', 'actions' => ['write', 'read']]]],
    'write only' => [[['vault' => 'orbit-control-inputs', 'key_pattern' => '^database-write-intent-[1-9][0-9]*$', 'actions' => ['write']]]],
    'admin' => [[['vault' => 'orbit-control-inputs', 'key_pattern' => '^database-write-intent-[1-9][0-9]*$', 'actions' => ['write', 'read', 'admin']]]],
    'unanchored' => [[['vault' => 'orbit-control-inputs', 'key_pattern' => 'database-write-intent-', 'actions' => ['write', 'read']]]],
]);

it('refuses retired execution copy intent after its declaration is removed', function (): void {
    $policy = ['version' => 2, 'principal' => 'user:orbit-gateway', 'workflows' => [],
        'vaults' => [['vault' => 'orbit-control-inputs', 'key_pattern' => '^old-[0-9]+$', 'actions' => ['write', 'read']]]];

    expect(fn () => (new GatewayWorkflowGrantCheck)->execute([], $policy))->toThrow(LogicException::class);
});

it('requires write only for the exact declared schedule input producer', function (): void {
    $definitions = ['schedule:maintenance' => ['workflow' => 'maintenance-projects', 'schedule_write' => true],
        'project.routine' => ['workflow' => 'project-routine']];
    $policy = ['version' => 2, 'principal' => 'user:orbit-gateway', 'vaults' => [], 'workflows' => [
        ['workflow' => 'maintenance-projects', 'publishes_inputs' => true, 'actions' => ['write', 'read', 'run']],
        ['workflow' => 'project-routine', 'actions' => ['run', 'read']],
    ]];
    expect((new GatewayWorkflowGrantCheck)->execute($definitions, $policy))->toBe(2);
    $unmarked = $policy;
    unset($unmarked['workflows'][0]['publishes_inputs']);
    expect(fn () => (new GatewayWorkflowGrantCheck)->execute($definitions, $unmarked))->toThrow(LogicException::class);
    $false = $policy;
    $false['workflows'][0]['publishes_inputs'] = false;
    expect(fn () => (new GatewayWorkflowGrantCheck)->execute($definitions, $false))->toThrow(LogicException::class);
    $missing = $policy;
    $missing['workflows'][0]['actions'] = ['run', 'read'];
    expect(fn () => (new GatewayWorkflowGrantCheck)->execute($definitions, $missing))->toThrow(LogicException::class);
    $broader = $policy;
    $broader['workflows'][1]['actions'][] = 'write';
    expect(fn () => (new GatewayWorkflowGrantCheck)->execute($definitions, $broader))->toThrow(LogicException::class);
    $retired = $definitions;
    unset($retired['schedule:maintenance']['schedule_write']);
    expect(fn () => (new GatewayWorkflowGrantCheck)->execute($retired, $policy))->toThrow(LogicException::class);
    $admin = $policy;
    $admin['workflows'][0]['actions'][] = 'admin';
    expect(fn () => (new GatewayWorkflowGrantCheck)->execute($definitions, $admin))->toThrow(LogicException::class);
});
