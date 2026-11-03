<?php

declare(strict_types=1);

use App\Domain\Operations\GatewayWorkflowGrantCheck;

/** The two grants every intended policy carries: run and read on every workflow, and the Schedule workflows' writes. */
function grant_base(): array
{
    return [
        ['workflow' => '*', 'actions' => ['run', 'read']],
        ['workflow' => 'schedule-*', 'schedule_workflows' => true, 'actions' => ['run', 'read', 'write']],
    ];
}

/** @param list<array<string,mixed>> $workflows */
function grant_policy(array $workflows = [], array $vaults = []): array
{
    return ['version' => 2, 'principal' => 'user:orbit-gateway', 'workflows' => [...grant_base(), ...$workflows], 'vaults' => $vaults];
}

it('rejects broader or malformed intended policy', function (array $change): void {
    $definitions = ['process.stop' => ['workflow' => 'operation-process-control']];
    expect((new GatewayWorkflowGrantCheck)->execute($definitions, grant_policy()))->toBe(1);

    expect(fn () => (new GatewayWorkflowGrantCheck)->execute($definitions, array_replace(grant_policy(), $change)))
        ->toThrow(LogicException::class);
})->with([
    'different principal' => [['principal' => 'user:other']],
    'other schema' => [['version' => 3]],
    'extra authority field' => [['credentials' => []]],
    'non-list entries' => [['workflows' => ['named' => grant_base()[0]]]],
    'no wildcard' => [['workflows' => [grant_base()[1]]]],
    'no Schedule workflows' => [['workflows' => [grant_base()[0]]]],
    'duplicate wildcard' => [['workflows' => [...grant_base(), grant_base()[0]]]],
    'broader wildcard' => [['workflows' => [['workflow' => '*', 'actions' => ['run', 'read', 'write']], grant_base()[1]]]],
    'conditional entry' => [['workflows' => [[...grant_base()[0], 'condition' => 'true'], grant_base()[1]]]],
    'exact grant the wildcard covers' => [['workflows' => [...grant_base(), ['workflow' => 'operation-process-control', 'actions' => ['run', 'read']]]]],
]);

it('rejects retired workflow grant intent after its final declaration is removed', function (): void {
    expect(fn () => (new GatewayWorkflowGrantCheck)->execute([], grant_policy([['workflow' => 'operation-retired', 'actions' => ['run', 'read']]])))
        ->toThrow(LogicException::class, 'operation-retired');
});

it('checks exact execution copy vault intent against public declarations', function (): void {
    $copy = ['vault' => 'orbit-control-inputs', 'key_pattern' => '^database-write-intent-[1-9][0-9]*$'];
    $definitions = ['database.write' => ['workflow' => 'operation-database-write', 'execution_copies' => [$copy]]];

    expect((new GatewayWorkflowGrantCheck)->execute($definitions, grant_policy(vaults: [[...$copy, 'actions' => ['write', 'read']]])))->toBe(1);
});

it('refuses missing or broader execution copy vault intent', function (array $entry): void {
    $copy = ['vault' => 'orbit-control-inputs', 'key_pattern' => '^database-write-intent-[1-9][0-9]*$'];
    $definitions = ['database.write' => ['workflow' => 'operation-database-write', 'execution_copies' => [$copy]]];

    expect(fn () => (new GatewayWorkflowGrantCheck)->execute($definitions, grant_policy(vaults: $entry)))
        ->toThrow(LogicException::class);
})->with([
    'missing' => [[]],
    'broader pattern' => [[['vault' => 'orbit-control-inputs', 'key_pattern' => '^.*$', 'actions' => ['write', 'read']]]],
    'other vault' => [[['vault' => 'other', 'key_pattern' => '^database-write-intent-[1-9][0-9]*$', 'actions' => ['write', 'read']]]],
    'write only' => [[['vault' => 'orbit-control-inputs', 'key_pattern' => '^database-write-intent-[1-9][0-9]*$', 'actions' => ['write']]]],
    'admin' => [[['vault' => 'orbit-control-inputs', 'key_pattern' => '^database-write-intent-[1-9][0-9]*$', 'actions' => ['write', 'read', 'admin']]]],
    'unanchored' => [[['vault' => 'orbit-control-inputs', 'key_pattern' => 'database-write-intent-', 'actions' => ['write', 'read']]]],
]);

it('refuses retired execution copy intent after its declaration is removed', function (): void {
    $vaults = [['vault' => 'orbit-control-inputs', 'key_pattern' => '^old-[0-9]+$', 'actions' => ['write', 'read']]];

    expect(fn () => (new GatewayWorkflowGrantCheck)->execute([], grant_policy(vaults: $vaults)))
        ->toThrow(LogicException::class, 'vault intent');
});

it('requires write only for the exact declared schedule input producer', function (): void {
    $definitions = ['schedule:maintenance' => ['workflow' => 'maintenance-projects', 'schedule_write' => true],
        'project.routine' => ['workflow' => 'project-routine']];
    $policy = grant_policy([['workflow' => 'maintenance-projects', 'publishes_inputs' => true, 'actions' => ['write', 'read', 'run']]]);
    expect((new GatewayWorkflowGrantCheck)->execute($definitions, $policy))->toBe(2);
    $unmarked = $policy;
    unset($unmarked['workflows'][2]['publishes_inputs']);
    expect(fn () => (new GatewayWorkflowGrantCheck)->execute($definitions, $unmarked))->toThrow(LogicException::class);
    $false = $policy;
    $false['workflows'][2]['publishes_inputs'] = false;
    expect(fn () => (new GatewayWorkflowGrantCheck)->execute($definitions, $false))->toThrow(LogicException::class);
    $missing = $policy;
    $missing['workflows'][2]['actions'] = ['run', 'read'];
    expect(fn () => (new GatewayWorkflowGrantCheck)->execute($definitions, $missing))->toThrow(LogicException::class);
    $broader = grant_policy([$policy['workflows'][2], ['workflow' => 'project-routine', 'publishes_inputs' => true, 'actions' => ['write', 'read', 'run']]]);
    expect(fn () => (new GatewayWorkflowGrantCheck)->execute($definitions, $broader))->toThrow(LogicException::class, 'project-routine');
    $retired = $definitions;
    unset($retired['schedule:maintenance']['schedule_write']);
    expect(fn () => (new GatewayWorkflowGrantCheck)->execute($retired, $policy))->toThrow(LogicException::class, 'maintenance-projects');
    expect(fn () => (new GatewayWorkflowGrantCheck)->execute($definitions, grant_policy()))->toThrow(LogicException::class, 'maintenance-projects');
    $admin = $policy;
    $admin['workflows'][2]['actions'][] = 'admin';
    expect(fn () => (new GatewayWorkflowGrantCheck)->execute($definitions, $admin))->toThrow(LogicException::class);
});
