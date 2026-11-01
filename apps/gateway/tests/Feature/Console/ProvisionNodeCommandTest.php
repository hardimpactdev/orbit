<?php

declare(strict_types=1);

use App\Domain\Shared\LifecycleStatus;
use App\Models\Node;
use App\Models\Operation;
use Tests\Support\NativeEnrollmentFixtures;

beforeEach(function (): void {
    $fixture = NativeEnrollmentFixtures::bind();
    $this->markAsGateway($fixture['gateway']);
    $this->socket = $fixture['socket'];
});

it('admits the same native enrollment from the console without claiming host completion', function (): void {
    $this->artisan('orbit:node-provision', [
        'name' => 'console-native', 'host' => '192.0.2.240', '--architecture' => 'x86_64',
        '--wireguard-ip' => '10.44.0.240', '--user' => 'bootstrap', '--orbit-user' => 'managed',
        '--host-key-fingerprint' => 'SHA256:'.str_repeat('A', 43),
    ])->expectsOutputToContain('Node enrollment Operation [1] is running.')->assertExitCode(0);

    $node = Node::query()->where('name', 'console-native')->sole();
    $operation = Operation::query()->where('kind', 'node.add')->sole();
    expect($node->status)->toBe(LifecycleStatus::Provisioning)
        ->and($node->architecture)->toBeNull()
        ->and($node->ssh_host_key)->toBeNull()
        ->and($operation->target_id)->toBe($node->id)
        ->and($operation->input['intent']['bootstrap']['target']['user'])->toBe('bootstrap')
        ->and($operation->input['intent']['candidate']['user'])->toBe('managed')
        ->and(array_filter($this->socket->sent, static fn (array $frame): bool => $frame['type'] === 'workflow.run'))->toHaveCount(1);
});

it('refuses malformed console input before reservation or native effects', function (array $options): void {
    $this->artisan('orbit:node-provision', ['name' => 'invalid-console', 'host' => '192.0.2.240', ...$options])
        ->expectsOutputToContain('Node provisioning arguments are invalid.')->assertExitCode(1);

    expect(Node::query()->where('name', 'invalid-console')->exists())->toBeFalse()
        ->and(Operation::query()->exists())->toBeFalse()
        ->and($this->socket->sent)->toBe([]);
})->with([
    'decimal port' => [['--ssh-port' => '22.5']],
    'scientific port' => [['--ssh-port' => '1e2']],
    'zero port' => [['--ssh-port' => '0']],
    'large port' => [['--ssh-port' => '65536']],
    'invalid Cluster' => [['--cluster' => '0']],
    'invalid LAN address' => [['--lan-ip' => 'fd00::1']],
    'unknown role' => [['--role' => ['unknown']]],
]);

it('reports admission refusal without a command tail or partial Node', function (): void {
    $this->artisan('orbit:node-provision', ['name' => 'unpinned-console', 'host' => '192.0.2.240',
        '--wireguard-ip' => '10.44.0.240'])
        ->expectsOutputToContain('Node enrollment was not admitted [node.ssh_host_fingerprint_required].')->assertExitCode(1);
    expect(Node::query()->where('name', 'unpinned-console')->exists())->toBeFalse()
        ->and(Operation::query()->exists())->toBeFalse()
        ->and($this->socket->sent)->toBe([]);
});

it('does not expose a native execution-copy failure or reserve a partial Node', function (): void {
    $this->socket->reply = static fn (array $request): array => [['type' => 'error', 'id' => $request['id'],
        'error' => ['code' => 'forbidden', 'message' => 'private-execution-copy-detail']]];
    $this->artisan('orbit:node-provision', ['name' => 'refused-console', 'host' => '192.0.2.240',
        '--wireguard-ip' => '10.44.0.240', '--host-key-fingerprint' => 'SHA256:'.str_repeat('A', 43)])
        ->expectsOutputToContain('Node enrollment was not admitted')->doesntExpectOutputToContain('private-execution-copy-detail')
        ->assertExitCode(1);
    expect(Node::query()->where('name', 'refused-console')->exists())->toBeFalse()
        ->and(Operation::query()->exists())->toBeFalse();
});
