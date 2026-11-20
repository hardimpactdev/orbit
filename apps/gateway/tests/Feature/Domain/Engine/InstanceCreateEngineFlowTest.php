<?php

declare(strict_types=1);

use App\Domain\Engine\EngineRun;
use App\Domain\Engine\EngineUnsupported;
use App\Domain\Engine\StepSpec;
use App\Domain\Executor\ExecutorOrderSigner;
use App\Domain\Instances\InstanceCreateEngineFlow;
use App\Domain\Instances\NestedFleetClubKey;
use App\Models\Operation;
use App\Models\OperationEngineStep;

/** The admitted clone intent of the first engine clone (nested create 288), as a create Operation. */
function engineCloneOperation(?Closure $change = null): Operation
{
    $fixture = json_decode((string) file_get_contents(base_path('tests/Fixtures/instance-create-engine-clone.json')), true, flags: JSON_THROW_ON_ERROR);
    $input = $fixture['input'];
    if ($change !== null) {
        $input = $change($input);
    }

    return (new Operation)->forceFill(['id' => 288, 'kind' => 'instance.create', 'target_type' => 'instance', 'target_id' => $fixture['target_id'],
        'node_id' => $fixture['node_id'], 'input' => $input]);
}

it('runs a container clone as the instance family: copy and fetch on the Node, then the copy\'s own steps in order', function (): void {
    $steps = app(InstanceCreateEngineFlow::class)->steps(engineCloneOperation());

    expect(array_map(static fn (StepSpec $step): array => [$step->name, $step->node, $step->action, $step->target, $step->after, $step->onFailure], $steps))->toBe([
        // Each Node step locks its own part of the Node, so they run side by side.
        ['clone', 3, 'instance.clone', '3:clone-6', [], false],
        ['fetch', 3, 'instance.fetch_branch', '3:fetch-6', [], false],
        ['prepare', 3, 'instance.prepare', '6', ['clone'], false],
        ['start', 3, 'instance.start', '6', ['prepare'], false],
        ['checkout', 3, 'instance.checkout', '6', ['start', 'fetch'], false],
        ['t3', 3, 'instance.t3', '6', ['checkout'], false],
        // Only after a failed step: the copy stops and stays.
        ['stop', 3, 'instance.stop', '6', ['clone'], true],
    ]);
});

it('sends a clone the engine does not cover to Swamp before anything starts', function (Closure $change): void {
    expect(fn () => app(InstanceCreateEngineFlow::class)->steps(engineCloneOperation($change)))->toThrow(EngineUnsupported::class);
})->with([
    'a default, not a clone' => [static function (array $input): array {
        unset($input['direct_provisioning']['container']['clone']);

        return $input;
    }],
    'a branch selection' => [static function (array $input): array {
        $input['branch_selection'] = ['target' => 'feature/x'];

        return $input;
    }],
    'a CI hold' => [static function (array $input): array {
        $input['direct_provisioning']['container']['clone']['ci_hold'] = ['node_id' => 12];

        return $input;
    }],
    'Node services' => [static function (array $input): array {
        $input['direct_provisioning']['container']['node_services'] = [['id' => 1]];

        return $input;
    }],
    'an already active create' => [static function (array $input): array {
        $input['direct_provisioning']['already_active'] = true;

        return $input;
    }],
    'a firewall row other than a TCP route allow' => [static function (array $input): array {
        $input['direct_provisioning']['container']['firewall'][] = ['route' => 'no', 'rule' => 'allow', 'proto' => 'tcp'];

        return $input;
    }],
]);

it('gives the copy its admitted NIC, config, T3 proxy and route rule', function (): void {
    $input = app(InstanceCreateEngineFlow::class)->input(engineCloneOperation(), 'clone', new EngineRun(288, [], 0, null));

    expect($input)->toMatchArray(['project' => 'orbit-instances', 'name' => 'instance-6', 'source' => 'instance-2', 'nic_acl' => 'orbitinst0-proxy',
        'filters' => ['security.ipv4_filtering' => 'true', 'security.mac_filtering' => 'true']])
        ->and($input['proxy'])->toBe(['device' => 't3', 'listen' => 'tcp:10.45.0.2:3803', 'connect' => 'tcp:'.$input['address'].':3773'])
        ->and($input['routes'])->toBe([['interface_in' => 'orbit', 'interface_out' => 'orbitinst0', 'from' => 'any', 'to' => $input['address'], 'port' => 3773,
            'comment' => 'orbit:instance-6-t3']]);
});

it('has the started copy run its app-account swap, so a copy of an older default moves to www-data before checkout', function (): void {
    $input = app(InstanceCreateEngineFlow::class)->input(engineCloneOperation(), 'start', new EngineRun(288, [], 0, null));

    expect($input['app_account'])->toBe('/usr/local/libexec/orbit-app-user');
});

/** The first engine clone's intent as a nested-fleet task copy with a Process, as Orbit's own task copies are. */
function nestedTaskCopy(array $input): array
{
    $container = &$input['direct_provisioning']['container'];
    $container['project'] = 'orbit-instances-nested';
    $container['config'] = [...$container['config'], 'security.nesting' => 'true'];
    $container['club_key'] = NestedFleetClubKey::intent();
    $container['inner_volume'] = ['name' => 'inner-6', 'pool' => 'orbit-instances', 'size' => '30GiB', 'device' => 'inner-pool', 'path' => '/srv/inner-pool',
        'config' => ['block.filesystem' => 'btrfs', 'security.shifted' => 'true'], 'inner_pool' => ['name' => 'inner', 'source' => '/srv/inner-pool/pool']];
    $container['allowed_devices'] = ['root' => ['type' => 'disk', 'path' => '/', 'pool' => 'orbit-instances', 'size' => 'old'],
        'inner-pool' => ['type' => 'disk', 'pool' => 'orbit-instances', 'source' => 'inner-6', 'path' => '/srv/inner-pool']];
    $container['processes'] = [['id' => 41, 'name' => 'orbit-process-41.service']];
    $container['clone']['inner_volume'] = 'inner-2';
    $container['clone']['clean_workspace'] = true;

    return $input;
}

it('runs a nested-fleet task copy on the engine: its volume beside the clone, its Club key beside the checkout', function (): void {
    $steps = app(InstanceCreateEngineFlow::class)->steps(engineCloneOperation(nestedTaskCopy(...)));

    expect(array_map(static fn (StepSpec $step): array => [$step->name, $step->action, $step->after], $steps))->toBe([
        ['clone', 'instance.clone', []],
        ['fetch', 'instance.fetch_branch', []],
        ['copy_volume', 'instance.copy_volume', []],
        ['prepare', 'instance.prepare', ['clone']],
        ['start', 'instance.start', ['prepare', 'copy_volume']],
        ['club_key', 'instance.club_key', ['start']],
        ['spare', 'instance.spare_volume', ['start']],
        ['checkout', 'instance.checkout', ['start', 'fetch']],
        ['t3', 'instance.t3', ['checkout']],
        ['stop', 'instance.stop', ['clone']],
    ])
        // The spare is made beside the create, one at a time per default, and never holds it up or fails it.
        ->and(array_values(array_filter($steps, static fn (StepSpec $step): bool => $step->name === 'spare'))[0])
        ->toMatchObject(['target' => '3:spare-inner-2', 'optional' => true])
        // The signer signs each of them.
        ->and(array_diff(array_map(static fn (StepSpec $step): string => $step->action, $steps), ExecutorOrderSigner::Actions))->toBe([]);
});

it('copies the default\'s inner volume and guards the nested copy\'s exact config, devices and volumes', function (): void {
    $flow = app(InstanceCreateEngineFlow::class);
    $run = new EngineRun(288, [], 0, null);
    $operation = engineCloneOperation(nestedTaskCopy(...));

    $volume = $flow->input($operation, 'copy_volume', $run);
    $start = $flow->input($operation, 'start', $run);

    expect($volume)->toBe(['project' => 'orbit-instances-nested', 'name' => 'instance-6', 'source' => 'instance-2',
        'snapshot' => $operation->input['direct_provisioning']['container']['clone']['snapshot'], 'device' => 'inner-pool', 'volume_source' => 'inner-2',
        'volume' => 'inner-6', 'pool' => 'orbit-instances', 'size' => '30GiB'])
        ->and($flow->input($operation, 'clone', $run))->not->toHaveKey('inner_volume')
        ->and($flow->input($operation, 'clone', $run)['exact_seed'])->toBeTrue()
        ->and($flow->input(engineCloneOperation(), 'clone', $run))->not->toHaveKey('exact_seed')
        ->and($start['volume'])->toBe(['device' => 'inner-pool', 'name' => 'inner-6'])
        ->and((array) $start['allowed']['config'])->toBe($operation->input['direct_provisioning']['container']['config'])
        // The admitted root is the intent's own root device, as the clone wrote it.
        ->and(((array) $start['allowed']['devices'])['root'])->toBe(['type' => 'disk', 'path' => '/', 'pool' => 'orbit-instances',
            'size' => $operation->input['direct_provisioning']['container']['root_disk_size']])
        ->and((array) $start['allowed']['volumes'])->toBe(['inner-pool' => ['block.filesystem' => 'btrfs', 'security.shifted' => 'true', 'size' => '30GiB']]);
});

it('delivers the Club key where the nested Gateway reads it, and nothing without a value', function (): void {
    $input = app(InstanceCreateEngineFlow::class)->input(engineCloneOperation(nestedTaskCopy(...)), 'club_key', new EngineRun(288, [], 0, null));

    expect($input)->toBe(['project' => 'orbit-instances-nested', 'name' => 'instance-6', 'directory' => '/run/orbit-club', 'file' => 'swamp-club-api-key',
        'owner' => 1000, 'group' => 1000, 'tmpfiles' => '/etc/tmpfiles.d/orbit-club.conf', 'gateway' => 'nested-gateway', 'serve_unit' => 'orbit-swamp-serve']);
});

it('names a task copy\'s clean and the Club key\'s word in the create\'s result, as the Swamp result does', function (): void {
    $step = static fn (string $output): OperationEngineStep => (new OperationEngineStep)->forceFill(['status' => 'done', 'node_id' => 3, 'output' => $output]);
    $cleaned = ['reset_count' => 1, 'reset' => ['routes/web.php'], 'removed_count' => 0, 'removed' => []];
    $run = new EngineRun(288, ['club_key' => $step('{"club_key":"changed"}'), 'checkout' => $step(json_encode(['commit' => str_repeat('a', 40), 'cleaned' => $cleaned]))], 0, null);

    expect(InstanceCreateEngineFlow::observed($run))->toBe(['workspace_cleaned' => $cleaned, 'club_key' => 'changed'])
        ->and(InstanceCreateEngineFlow::observed(new EngineRun(288, ['club_key' => $step('{"club_key":null}'), 'checkout' => $step('{"commit":"a"}')], 0, null)))->toBe([]);
});

it('makes the default\'s one spare from the same seed the copy takes', function (): void {
    $operation = engineCloneOperation(nestedTaskCopy(...));

    expect(app(InstanceCreateEngineFlow::class)->input($operation, 'spare', new EngineRun(288, [], 0, null)))->toBe(['project' => 'orbit-instances-nested',
        'source' => 'instance-2', 'snapshot' => $operation->input['direct_provisioning']['container']['clone']['snapshot'], 'volume_source' => 'inner-2',
        'pool' => 'orbit-instances']);
});

/** A run whose named steps are done with the given outputs. */
function engineRunWith(array $outputs): EngineRun
{
    return new EngineRun(288, array_map(static fn (string $output): OperationEngineStep => (new OperationEngineStep)->forceFill(['status' => 'done', 'node_id' => 3,
        'output' => $output]), $outputs), 0, null);
}

it('skips the app-account swap when the seed ran it with the same swap file', function (): void {
    $flow = app(InstanceCreateEngineFlow::class);
    $current = engineRunWith(['prepare' => '{"changes":3,"manifest":true,"app_account_current":true,"workspace_clean":true}']);
    $older = engineRunWith(['prepare' => '{"changes":4,"manifest":true,"app_account_current":false,"workspace_clean":true}']);

    expect($flow->input(engineCloneOperation(), 'start', $current))->not->toHaveKey('app_account')
        ->and($flow->input(engineCloneOperation(), 'start', $older)['app_account'])->toBe('/usr/local/libexec/orbit-app-user');
});

it('lets a task copy skip its clean only on a clean seed at the seed\'s own commit', function (): void {
    $operation = engineCloneOperation(nestedTaskCopy(...));
    $clone = $operation->input['direct_provisioning']['container']['clone'];
    $seedClean = static fn (array $outputs): bool => (new ReflectionMethod(InstanceCreateEngineFlow::class, 'seedClean'))->invoke(null, engineRunWith($outputs), $clone);
    $clean = '{"changes":3,"manifest":true,"app_account_current":true,"workspace_clean":true}';

    expect($seedClean(['prepare' => $clean, 'fetch' => '{"commit":null}', 'clone' => '{"snapshot":"'.$clone['snapshot'].'"}']))->toBeTrue()
        // A branch at another commit may hold files that the seed's commit ignored.
        ->and($seedClean(['prepare' => $clean, 'fetch' => '{"commit":"'.str_repeat('c', 40).'"}', 'clone' => '{"snapshot":null}']))->toBeFalse()
        // A newer seed than the admitted one: its tree is not the one the manifest was checked against.
        ->and($seedClean(['prepare' => $clean, 'fetch' => '{"commit":null}', 'clone' => '{"snapshot":"seed-'.str_repeat('d', 40).'"}']))->toBeFalse()
        ->and($seedClean(['prepare' => str_replace('true}', 'false}', $clean), 'fetch' => '{"commit":null}', 'clone' => '{"snapshot":null}']))->toBeFalse();
});

it('ends a failed create with the step Swamp names, the step\'s own code and the address left to a retry\'s completion', function (): void {
    $failed = static fn (string $step): OperationEngineStep => (new OperationEngineStep)->forceFill(['name' => $step, 'status' => 'failed', 'node_id' => 3,
        'error_code' => 'instance.container_checkout_failed']);
    $run = new EngineRun(288, ['clone' => (new OperationEngineStep)->forceFill(['name' => 'clone', 'status' => 'done', 'node_id' => 3]), 'checkout' => $failed('checkout'),
        'stop' => (new OperationEngineStep)->forceFill(['name' => 'stop', 'status' => 'failed', 'node_id' => 3, 'on_failure' => true, 'error_code' => 'instance.stop_failed'])], 0, null);

    expect(InstanceCreateEngineFlow::ended(engineCloneOperation(), $run))->toBe(['status' => 'failed', 'error_code' => 'instance.container_checkout_failed',
        'failure' => ['failed_step' => 'clone-t3', 'stderr_recorded' => false],
        'result' => ['ready' => false, 'instance_id' => 6, 'failed_step' => 'workspace', 'error_code' => 'instance.container_checkout_failed', 'route_publication' => 'deferred']]);
});
