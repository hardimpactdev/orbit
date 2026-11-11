<?php

declare(strict_types=1);

use App\Actions\NodeFacts\RecordNodeFactAction;
use App\Domain\Doctor\DoctorFamily;
use App\Domain\Doctor\FactDoctor;
use App\Domain\NodeFacts\NodeFactKind;
use App\Models\Node;
use App\Models\NodeFact;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Validator;

uses(LazilyRefreshDatabase::class);

it('surfaces a persistent bridge refresh failure from the observer host fact in Doctor and clears it on recovery', function (string $code): void {
    $node = Node::withoutEvents(fn (): Node => Node::query()->create([
        'name' => 'bridge-host', 'status' => 'active', 'platform' => 'linux', 'architecture' => 'x86_64',
        'wireguard_ip' => '10.44.0.7', 'public_ssh_host' => '10.44.0.7', 'ssh_host_fingerprint' => 'SHA256:fixture', 'user' => 'orbit',
    ]));
    $data = ['os' => 'linux', 'arch' => 'x86_64', 'filesystems' => [],
        'instance_bridge' => ['error_code' => $code]];
    expect(Validator::make($data, NodeFactKind::Host->rules())->passes())->toBeTrue()
        ->and(Validator::make([...$data, 'instance_bridge' => null], NodeFactKind::Host->rules())->passes())->toBeTrue()
        ->and(Validator::make([...$data, 'instance_bridge' => []], NodeFactKind::Host->rules())->passes())->toBeFalse()
        ->and(Validator::make([...$data, 'instance_bridge' => ['error_code' => 'private or unknown output']], NodeFactKind::Host->rules())->passes())->toBeFalse();
    $validated = Validator::make($data, NodeFactKind::Host->rules())->validate();
    expect(app(RecordNodeFactAction::class)->execute($node, NodeFactKind::Host, '', CarbonImmutable::now(), $validated))->toBeTrue();
    $fact = NodeFact::query()->where('node_id', $node->id)->where('kind', NodeFactKind::Host)->firstOrFail();
    expect($fact->data['instance_bridge']['error_code'])->toBe($code);

    $report = app(FactDoctor::class)->report([$node], [DoctorFamily::Node])->toArray();
    $issue = collect($report['nodes'][0]['families'][0]['issues'])->firstWhere('code', 'node.instance_bridge_unhealthy');
    expect($issue)->toMatchArray(['observed' => $code, 'summary' => 'Instance bridge endpoint refresh failed: '.$code]);

    $data['instance_bridge']['error_code'] = null;
    $fact->update(['data' => $data]);
    expect(json_encode(app(FactDoctor::class)->report([$node], [DoctorFamily::Node])->toArray()))->not->toContain('node.instance_bridge_unhealthy');
})->with([
    'unresolved endpoint' => ['instance.container_endpoint_unresolved'],
    'bridge write failure' => ['instance.container_bridge_write_failed'],
    'unreadable health' => ['instance.container_bridge_health_unreadable'],
    'command timeout' => ['instance.bridge_command_timeout'],
    'lock timeout' => ['instance.bridge_lock_timeout'],
    'ambiguous ACL state' => ['instance.bridge_state_unresolved'],
]);
