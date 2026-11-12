<?php

declare(strict_types=1);

use App\Actions\Doctor\RunDoctorAction;
use App\Actions\NodeFacts\RecordNodeFactAction;
use App\Domain\AgentView\AgentStateView;
use App\Domain\Doctor\DoctorFamily;
use App\Domain\Doctor\FactDoctor;
use App\Domain\NodeFacts\NodeFactKind;
use App\Infrastructure\AgentView\CacheAgentStateView;
use App\Models\Node;
use App\Models\NodeFact;
use App\Models\ProblemFingerprint;
use Carbon\CarbonImmutable;
use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\Repository;
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
        ->and(Validator::make([...$data, 'instance_bridge' => ['error_code' => 'private or unknown output']], NodeFactKind::Host->rules())->passes())->toBeFalse()
        ->and(Validator::make([...$data, 'instance_bridge' => ['error_code' => 'instance.bridge_busy']], NodeFactKind::Host->rules())->passes())->toBeFalse();
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
    'ambiguous ACL state' => ['instance.bridge_state_unresolved'],
]);

it('reports endpoint warnings in node doctor output without making the Node unhealthy and clears them on recovery', function (): void {
    $node = Node::withoutEvents(fn (): Node => Node::query()->create([
        'name' => 'bridge-host', 'status' => 'active', 'platform' => 'linux', 'architecture' => 'x86_64',
        'wireguard_ip' => '10.44.0.7', 'public_ssh_host' => '10.44.0.7', 'ssh_host_fingerprint' => 'SHA256:fixture', 'user' => 'orbit',
    ]));
    $node->accessibleNodes()->attach($node);
    $view = new CacheAgentStateView(new Repository(new ArrayStore));
    $view->putSubscriber(true, true, 1);
    $view->putNode($node->id, [], null, 1, CacheAgentStateView::now(), null);
    app()->instance(AgentStateView::class, $view);
    $data = ['os' => 'linux', 'arch' => 'x86_64',
        'filesystems' => [['mount' => '/', 'size' => 100_000_000_000, 'free' => 90_000_000_000]],
        'instance_bridge' => ['error_code' => null],
        'instance_bridge_warnings' => ['warning_count' => 200,
            'warnings' => array_map(static fn (int $port): array => [
                'code' => 'instance.container_endpoint_unresolved', 'host' => '10.253.0.1', 'port' => $port,
            ], range(13310, 13329))]];
    $validated = Validator::make($data, NodeFactKind::Host->rules())->validate();
    app(RecordNodeFactAction::class)->execute($node, NodeFactKind::Host, '', CarbonImmutable::now(), $validated);
    $fact = NodeFact::query()->where('node_id', $node->id)->where('kind', NodeFactKind::Host)->sole();
    expect($fact->data['instance_bridge_warnings'])->toBe($data['instance_bridge_warnings']);

    $report = $this->withServerVariables(['REMOTE_ADDR' => '10.44.0.7'])
        ->postJson('/api/v1/doctor', ['node_id' => $node->id, 'families' => ['node']])
        ->assertOk()->assertJsonPath('data.healthy', true)
        ->assertJsonPath('data.summary.informational', 1)
        ->assertJsonPath('data.nodes.0.families.0.status', 'healthy')->json('data');
    $issue = collect($report['nodes'][0]['families'][0]['issues'])->sole();
    expect($issue)->toMatchArray(['code' => 'node.instance_bridge_endpoint_unresolved', 'kind' => 'informational',
        'observed' => '200 unresolved']);
    expect($issue['summary'])->toContain('Warning:', '200 endpoint(s)', '10.253.0.1:13310', '10.253.0.1:13313', '196 more.')
        ->not->toContain('node.instance_bridge_unhealthy');

    expect(strlen($issue['summary']))->toBeLessThanOrEqual(255);
    foreach ([null, ['warning_count' => 0, 'warnings' => []]] as $healthy) {
        if ($healthy === null) {
            unset($data['instance_bridge_warnings']);
        } else {
            $data['instance_bridge_warnings'] = $healthy;
        }
        $fact->update(['data' => Validator::make($data, NodeFactKind::Host->rules())->validate()]);
        $this->postJson('/api/v1/doctor', ['node_id' => $node->id, 'families' => ['node']])
            ->assertOk()->assertJsonPath('data.healthy', true)
            ->assertJsonPath('data.nodes.0.families.0.issues', []);
    }
});

it('rejects oversized or unsafe bridge warning facts', function (array $bridge): void {
    expect(Validator::make(['os' => 'linux', 'arch' => 'x86_64', 'filesystems' => [],
        'instance_bridge' => ['error_code' => null], 'instance_bridge_warnings' => $bridge], NodeFactKind::Host->rules())->fails())->toBeTrue();
})->with([
    'sample without count' => [['warnings' => []]],
    'count without sample' => [['warning_count' => 1]],
    'negative count' => [['warning_count' => -1, 'warnings' => []]],
    'too many samples' => [['warning_count' => 21, 'warnings' => array_fill(0, 21, [
        'code' => 'instance.container_endpoint_unresolved', 'host' => '10.253.0.1', 'port' => 13330])]],
    'private host' => [['warning_count' => 1, 'warnings' => [[
        'code' => 'instance.container_endpoint_unresolved', 'host' => 'secret-token', 'port' => 13330]]]],
    'invalid port' => [['warning_count' => 1, 'warnings' => [[
        'code' => 'instance.container_endpoint_unresolved', 'host' => '10.253.0.1', 'port' => 65536]]]],
    'private output' => [['warning_count' => 1, 'warnings' => [[
        'code' => 'instance.container_endpoint_unresolved', 'host' => '10.253.0.1', 'port' => 13330, 'stderr' => 'secret-token']]]],
    'unknown code' => [['warning_count' => 1, 'warnings' => [[
        'code' => 'secret-token', 'host' => '10.253.0.1', 'port' => 13330]]]],
]);

it('keeps the warning summary inside older SDK limits for the largest valid identities and count', function (): void {
    $summary = FactDoctor::bridgeWarningSummary(2147483647, array_fill(0, 20, '255.255.255.255:65535'));
    expect(strlen($summary))->toBeLessThanOrEqual(255);
    expect($summary)->toContain('2147483647', '255.255.255.255:65535', '2147483643 more');
});

it('lets an older Gateway validate and retain the complete legacy host fact while dropping the new root field', function (): void {
    $current = NodeFactKind::Host->rules();
    $rules = ['data' => ['required', 'array']];
    foreach ($current as $field => $rule) {
        if (! str_starts_with($field, 'instance_bridge_warnings')) {
            $rules['data.'.$field] = $rule;
        }
    }
    $legacy = ['os' => 'linux', 'arch' => 'x86_64', 'filesystems' => [],
        'instance_bridge' => ['error_code' => null]];
    $new = $legacy + ['instance_bridge_warnings' => ['warning_count' => 200, 'warnings' => []]];

    expect(Validator::make(['data' => $new], $rules)->validate()['data'])->toEqual($legacy);
    expect($current['instance_bridge'])->toContain('array:error_code');
});

it('stores scheduled endpoint warnings without marking the Node unhealthy, retains them while present and clears them on recovery', function (): void {
    $this->travelTo(new DateTimeImmutable('2026-11-12T12:00:00Z'));
    $node = Node::withoutEvents(fn (): Node => Node::query()->create([
        'name' => 'bridge-host', 'status' => 'active', 'platform' => 'linux', 'architecture' => 'x86_64',
        'wireguard_ip' => '10.44.0.7', 'public_ssh_host' => '10.44.0.7', 'ssh_host_fingerprint' => 'SHA256:fixture', 'user' => 'orbit',
    ]));
    $node->accessibleNodes()->attach($node);
    $view = new CacheAgentStateView(new Repository(new ArrayStore));
    app()->instance(AgentStateView::class, $view);
    $view->putSubscriber(true, true, 1);
    $view->putNode($node->id, [], null, 1, CacheAgentStateView::now(), null);
    foreach (['host' => ['os' => 'linux', 'arch' => 'x86_64',
        'filesystems' => [['mount' => '/', 'size' => 100_000_000_000, 'free' => 90_000_000_000]],
        'instance_bridge' => ['error_code' => null],
        'instance_bridge_warnings' => ['warning_count' => 200, 'warnings' => [
            ['code' => 'instance.container_endpoint_unresolved', 'host' => '10.253.0.1', 'port' => 13330]]]],
        'units' => ['units' => [], 'awake' => []],
        'ufw' => ['installed' => true, 'enabled' => true, 'rules' => []]] as $kind => $data) {
        NodeFact::query()->create(['node_id' => $node->id, 'kind' => $kind, 'digest' => hash('sha256', json_encode($data)),
            'data' => $data, 'observed_at' => now(), 'received_at' => now()]);
    }
    $doctor = app(RunDoctorAction::class);
    $doctor->scheduled();
    $row = ProblemFingerprint::query()->where('evidence->doctor_code', 'node.instance_bridge_endpoint_unresolved')->sole();
    expect($row->evidence['summary'])->toContain('200 endpoint(s)', '10.253.0.1:13330');
    expect($doctor->execute($node, $node->id, [DoctorFamily::Node])->healthy)->toBeTrue();
    $codes = fn (): array => array_column($this->withServerVariables(['REMOTE_ADDR' => '10.44.0.7'])
        ->getJson('/api/v1/gateway/status')->assertOk()->json('data.doctor.issues'), 'code');
    expect($codes())->toContain('node.instance_bridge_endpoint_unresolved');

    $this->travel(5)->minutes();
    $view->putSubscriber(true, true, 1);
    $view->putNode($node->id, [], null, 2, CacheAgentStateView::now(), null);
    $doctor->scheduled();
    expect($row->refresh()->evidence)->not->toHaveKey('resolved_at');

    $fact = NodeFact::query()->where('node_id', $node->id)->where('kind', NodeFactKind::Host)->sole();
    $data = $fact->data;
    $data['instance_bridge_warnings'] = ['warning_count' => 0, 'warnings' => []];
    $fact->update(['data' => $data]);
    $this->travel(5)->minutes();
    $view->putSubscriber(true, true, 1);
    $view->putNode($node->id, [], null, 3, CacheAgentStateView::now(), null);
    $doctor->scheduled();
    expect($row->refresh()->evidence['resolution'])->toBe('fresh_doctor_success');
    expect($codes())->not->toContain('node.instance_bridge_endpoint_unresolved');
});
