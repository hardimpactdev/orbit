<?php

declare(strict_types=1);

use App\Domain\Engine\EngineRun;
use App\Domain\Engine\EngineUnsupported;
use App\Domain\Engine\StepSpec;
use App\Domain\Instances\InstanceCreateEngineFlow;
use App\Models\Operation;

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

    expect(array_map(static fn (StepSpec $step): array => [$step->name, $step->node, $step->action, $step->target, $step->after], $steps))->toBe([
        ['clone', 3, 'instance.clone', '3', []],
        ['fetch', 3, 'instance.fetch_branch', '3', []],
        ['prepare', 3, 'instance.prepare', '6', ['clone']],
        ['start', 3, 'instance.start', '6', ['prepare']],
        ['checkout', 3, 'instance.checkout', '6', ['start', 'fetch']],
        ['t3', 3, 'instance.t3', '6', ['checkout']],
    ]);
});

it('sends a clone the engine does not cover to Swamp before anything starts', function (Closure $change): void {
    expect(fn () => app(InstanceCreateEngineFlow::class)->steps(engineCloneOperation($change)))->toThrow(EngineUnsupported::class);
})->with([
    'a default, not a clone' => [static function (array $input): array {
        unset($input['direct_provisioning']['container']['clone']);

        return $input;
    }],
    'a nested-fleet copy' => [static function (array $input): array {
        $input['direct_provisioning']['container']['club_key'] = ['directory' => '/run/orbit-club'];

        return $input;
    }],
    'a task copy' => [static function (array $input): array {
        $input['direct_provisioning']['container']['clone']['clean_workspace'] = true;

        return $input;
    }],
    'a branch selection' => [static function (array $input): array {
        $input['branch_selection'] = ['target' => 'feature/x'];

        return $input;
    }],
    'Processes' => [static function (array $input): array {
        $input['direct_provisioning']['container']['processes'] = [['name' => 'orbit-process-1.service']];

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
