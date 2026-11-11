<?php

declare(strict_types=1);

use App\Domain\Doctor\DoctorFamily;
use App\Domain\Doctor\FactDoctor;
use App\Domain\NodeFacts\NodeFactKind;
use App\Models\Node;
use App\Models\NodeFact;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Validator;

uses(LazilyRefreshDatabase::class);

it('surfaces a persistent bridge refresh failure from the observer host fact in Doctor and clears it on recovery', function (): void {
    $node = Node::withoutEvents(fn (): Node => Node::query()->create([
        'name' => 'bridge-host', 'status' => 'active', 'platform' => 'linux', 'architecture' => 'x86_64',
        'wireguard_ip' => '10.44.0.7', 'public_ssh_host' => '10.44.0.7', 'ssh_host_fingerprint' => 'SHA256:fixture', 'user' => 'orbit',
    ]));
    $data = ['os' => 'linux', 'arch' => 'x86_64', 'filesystems' => [],
        'instance_bridge' => ['error_code' => 'instance.container_endpoint_unresolved']];
    expect(Validator::make($data, NodeFactKind::Host->rules())->passes())->toBeTrue()
        ->and(Validator::make([...$data, 'instance_bridge' => null], NodeFactKind::Host->rules())->passes())->toBeTrue()
        ->and(Validator::make([...$data, 'instance_bridge' => []], NodeFactKind::Host->rules())->passes())->toBeFalse()
        ->and(Validator::make([...$data, 'instance_bridge' => ['error_code' => 'private or unknown output']], NodeFactKind::Host->rules())->passes())->toBeFalse();
    $fact = NodeFact::query()->create(['node_id' => $node->id, 'kind' => NodeFactKind::Host, 'digest' => 'fixture',
        'data' => $data, 'observed_at' => now(), 'received_at' => now()]);

    $report = json_encode(app(FactDoctor::class)->report([$node], [DoctorFamily::Node])->toArray());
    expect($report)->toContain('node.instance_bridge_unhealthy', 'instance.container_endpoint_unresolved');

    $data['instance_bridge']['error_code'] = null;
    $fact->update(['data' => $data]);
    expect(json_encode(app(FactDoctor::class)->report([$node], [DoctorFamily::Node])->toArray()))->not->toContain('node.instance_bridge_unhealthy');
});
