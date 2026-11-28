<?php

declare(strict_types=1);

use App\Actions\NodeFacts\RecordNodeFactAction;
use App\Actions\Tools\StartToolOperationAction;
use App\Domain\Doctor\DoctorFamily;
use App\Domain\Doctor\FactDoctor;
use App\Domain\NodeFacts\NodeFactKind;
use App\Domain\Tools\ToolOperationException;
use App\Models\Node;
use App\Models\Tool;
use App\Models\ToolManagerRecord;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;

uses(LazilyRefreshDatabase::class);

/** A Linux Node whose agent reports `fzf` with two Cellar versions, and the linked one when `$linked` is set. */
function linked_brew_node(?string $linked, int $address = 9): Node
{
    $node = Node::withoutEvents(fn (): Node => Node::query()->create([
        'name' => 'beast'.$address, 'status' => 'active', 'platform' => 'linux', 'architecture' => 'x86_64', 'public_ssh_host' => '10.44.0.'.$address,
        'wireguard_ip' => '10.44.0.'.$address, 'user' => 'orbit', 'ssh_host_fingerprint' => 'SHA256:fingerprint',
        'ssh_host_key_type' => 'ssh-ed25519', 'ssh_host_key' => 'test-host-key',
    ]));
    $formula = ['name' => 'fzf', 'versions' => ['0.74.3', '0.75.0'], 'tap' => 'homebrew/core', 'on_request' => true, 'dependencies' => []];
    app(RecordNodeFactAction::class)->execute($node, NodeFactKind::Packages, 'brew', CarbonImmutable::now(), [
        'scope' => '/home/linuxbrew/.linuxbrew', 'casks' => [],
        'formulae' => [$linked === null ? $formula : [...$formula, 'linked' => $linked]],
    ]);

    return $node;
}

it('adopts a formula with several Cellar versions at the linked one, and refuses it without a link', function (): void {
    $node = linked_brew_node('0.75.0');
    app(StartToolOperationAction::class)->adopt($node->id, 'brew', 'fzf', null, null, null);
    expect(Tool::query()->where('node_id', $node->id)->sole()->installed_version)->toBe('0.75.0');

    $unlinked = linked_brew_node(null, 10);
    expect(fn () => app(StartToolOperationAction::class)->adopt($unlinked->id, 'brew', 'fzf', null, null, null))
        ->toThrow(fn (ToolOperationException $error) => expect([$error->errorCode, $error->adoptionBlock])->toBe(['tool.adoption_unsupported', 'version_unreadable']));
});

it('lets Doctor read the linked version of several, and leaves several without a link unverifiable', function (): void {
    $node = linked_brew_node('0.75.0');
    $manager = ToolManagerRecord::query()->create(['name' => 'brew', 'node_id' => $node->id, 'status' => 'active']);
    Tool::query()->create(['node_id' => $node->id, 'tool_manager_id' => $manager->id, 'package' => 'fzf',
        'desired_version' => '0.75.0', 'installed_version' => '0.75.0', 'status' => 'installed']);
    $read = fn (): array => app(FactDoctor::class)->report([$node], [DoctorFamily::Tool])->toArray()['nodes'][0]['families'][0];
    expect($read())->toMatchArray(['checked' => 1, 'status' => 'healthy', 'issues' => []]);

    $fact = fn (array $formula): bool => app(RecordNodeFactAction::class)->execute($node, NodeFactKind::Packages, 'brew', CarbonImmutable::now()->addSecond(), [
        'scope' => '/home/linuxbrew/.linuxbrew', 'casks' => [],
        'formulae' => [['name' => 'fzf', 'versions' => ['0.74.3', '0.75.0'], 'tap' => 'homebrew/core', 'on_request' => true, 'dependencies' => [], ...$formula]],
    ]);
    $fact(['linked' => '0.74.3']);
    expect($read()['issues'][0])->toMatchArray(['code' => 'tool.version_mismatch', 'observed' => '0.74.3']);
    $fact([]);
    expect($read()['issues'][0]['code'])->toBe('tool.inspection_failed');
});

it('accepts a linked formula version from the agent only as a string', function (): void {
    $node = linked_brew_node(null);
    $node->forceFill(['agent_secret_hash' => hash('sha256', 'agent-secret')])->save();
    $post = fn (mixed $linked, int $second) => test()->withServerVariables(['REMOTE_ADDR' => $node->wireguard_ip])->withToken('agent-secret')
        ->postJson('/api/v1/agent/facts', ['kind' => 'packages', 'subject' => 'brew', 'observed_at' => now()->addSeconds($second)->format('Y-m-d\\TH:i:s\\Z'),
            'data' => ['scope' => '/home/linuxbrew/.linuxbrew', 'casks' => [], 'formulae' => [['name' => 'fzf', 'versions' => ['0.74.3', '0.75.0'],
                'tap' => 'homebrew/core', 'on_request' => true, 'dependencies' => [], 'linked' => $linked]]]]);
    $post(75, 1)->assertUnprocessable();
    $post('0.75.0', 2)->assertNoContent();
});
