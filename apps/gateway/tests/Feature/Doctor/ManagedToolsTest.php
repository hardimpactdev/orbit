<?php

declare(strict_types=1);

use App\Actions\NodeFacts\RecordNodeFactAction;
use App\Domain\Doctor\DoctorFamily;
use App\Domain\Doctor\FactDoctor;
use App\Domain\NodeFacts\NodeFactKind;
use App\Models\Node;
use App\Models\NodeFact;
use App\Models\Tool;
use App\Models\ToolManagerRecord;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;

uses(LazilyRefreshDatabase::class);

it('counts managed Tools and reports literal drift, absence and unusable facts', function (): void {
    $node = Node::withoutEvents(fn (): Node => Node::query()->create([
        'name' => 'dev', 'status' => 'active', 'platform' => 'linux', 'architecture' => 'x86_64',
        'public_ssh_host' => '192.0.2.4', 'wireguard_ip' => '10.44.0.4', 'user' => 'orbit', 'ssh_host_fingerprint' => 'SHA256:fingerprint',
    ]));
    $manager = ToolManagerRecord::query()->create(['name' => 'apt', 'node_id' => $node->id, 'status' => 'active']);
    Tool::query()->create(['node_id' => $node->id, 'tool_manager_id' => $manager->id, 'package' => 'tree',
        'desired_version' => '1.10', 'installed_version' => '1.10', 'status' => 'installed']);
    $read = fn (): array => app(FactDoctor::class)->report([$node], [DoctorFamily::Tool])->toArray()['nodes'][0]['families'][0];
    expect($read())->toMatchArray(['checked' => 1, 'status' => 'unverifiable']);
    $fact = NodeFact::query()->create(['node_id' => $node->id, 'kind' => 'packages', 'subject' => 'apt', 'digest' => str_repeat('a', 64),
        'observed_at' => now(), 'received_at' => now(), 'data' => ['scope' => '/usr/bin/apt', 'packages' => [['name' => 'tree', 'version' => '1.1']]]]);
    expect($read()['issues'][0])->toMatchArray(['code' => 'tool.version_mismatch', 'expected' => '1.10', 'observed' => '1.1']);
    $fact->update(['data' => ['scope' => '/usr/bin/apt', 'packages' => [['name' => 'tree', 'version' => '1.10']]]]);
    expect($read())->toMatchArray(['checked' => 1, 'status' => 'healthy', 'issues' => []]);
    $fact->update(['data' => ['scope' => '/usr/bin/apt', 'packages' => [['name' => 'tree', 'version' => '1.10', 'held' => true]]]]);
    expect($read())->toMatchArray(['checked' => 1, 'status' => 'healthy', 'issues' => []]);
    $fact->update(['data' => ['scope' => '/usr/bin/apt', 'packages' => []]]);
    expect($read()['issues'][0]['code'])->toBe('tool.not_installed');
    $fact->update(['observed_at' => now()->subMinutes(13)]);
    expect($read()['issues'][0]['code'])->toBe('tool.inspection_failed');
    $reporter = app(RecordNodeFactAction::class);
    $matching = ['scope' => '/usr/bin/apt', 'packages' => [['name' => 'tree', 'version' => '1.10']]];
    expect($reporter->execute($node, NodeFactKind::Packages, 'apt', CarbonImmutable::now()->subMinutes(2), $matching))->toBeTrue()
        ->and($reporter->execute($node, NodeFactKind::Packages, 'apt', CarbonImmutable::now(), $matching))->toBeFalse()
        ->and($fact->refresh()->confirmed_at->greaterThan($fact->observed_at))->toBeTrue()
        ->and($read())->toMatchArray(['checked' => 1, 'status' => 'healthy']);
    // The agent reports a held apt package through its fact endpoint, which keeps the `held` mark.
    $node->forceFill(['agent_secret_hash' => hash('sha256', 'agent-secret')])->save();
    $held = ['scope' => '/usr/bin/apt', 'packages' => [['name' => 'tree', 'version' => '1.10', 'held' => true]]];
    test()->withServerVariables(['REMOTE_ADDR' => $node->wireguard_ip])->withToken('agent-secret')
        ->postJson('/api/v1/agent/facts', ['kind' => 'packages', 'subject' => 'apt', 'observed_at' => now()->addSecond()->format('Y-m-d\\TH:i:s\\Z'), 'data' => $held])
        ->assertNoContent();
    // Only boolean true marks a held row, as ObservedPackages reads it.
    foreach ([1, '1', 'true', false] as $mark) {
        test()->withServerVariables(['REMOTE_ADDR' => $node->wireguard_ip])->withToken('agent-secret')
            ->postJson('/api/v1/agent/facts', ['kind' => 'packages', 'subject' => 'apt', 'observed_at' => now()->addSeconds(2)->format('Y-m-d\\TH:i:s\\Z'),
                'data' => ['scope' => '/usr/bin/apt', 'packages' => [['name' => 'tree', 'version' => '1.10', 'held' => $mark]]]])
            ->assertUnprocessable();
    }
    expect($fact->refresh()->data['packages'][0])->toBe(['name' => 'tree', 'version' => '1.10', 'held' => true])
        ->and($read())->toMatchArray(['checked' => 1, 'status' => 'healthy']);
});
