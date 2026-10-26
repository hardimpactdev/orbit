<?php

declare(strict_types=1);

use App\Domain\Nodes\NodeProvisioningException;
use App\Infrastructure\AgentCommands\AgentCommandRefused;
use App\Infrastructure\AgentCommands\AgentRunnerFailure;
use App\Infrastructure\Nodes\MacOsNodeConverger;
use App\Infrastructure\Processes\CommandResult;
use App\Infrastructure\Ssh\HostKey;
use App\Infrastructure\Ssh\HostKeyScanner;
use App\Infrastructure\Ssh\SshHostKeyScanException;
use App\Infrastructure\Ssh\SshKeyProvider;
use App\Models\Node;
use Tests\Support\FakeMacAdmissionAgentRunner;

it('pins the connection and installs the command binary before native enrollment checks', function (): void {
    $ssh = new FakeMacAdmissionAgentRunner;
    $converger = macos_converger($ssh, fingerprint: 'SHA256:pinned');

    $observation = $converger->enroll(new Node(['name' => 'mini']), '192.0.2.40', 22, 'mini', '10.44.0.40', null, 'SHA256:pinned');

    expect($observation->architecture)
        ->toBe('arm64')
        ->and($observation->hostKey->fingerprint)
        ->toBe('SHA256:pinned')
        ->and($ssh->connections[0]->user)
        ->toBe('mini')
        ->and($ssh->connections[0]->host)
        ->toBe('192.0.2.40')
        ->and($ssh->connections[0]->identityFile)
        ->toBe('/tmp/orbit-gateway-key')
        ->and($ssh->commands)
        ->toBe(['probe', 'agent.install', 'nodes.inspect-macos-enrollment'])
        ->and($ssh->inputs)->toBe([['address' => '10.44.0.40']]);

});

it('stops when the existing account does not authorize the gateway key', function (): void {
    $ssh = new FakeMacAdmissionAgentRunner;
    $ssh->probeFailure = new AgentRunnerFailure('agent.install', AgentRunnerFailure::Transport, 'Permission denied', result: new CommandResult(255, '', '', 1, false));
    $converger = macos_converger($ssh, fingerprint: 'SHA256:pinned');

    expect(fn () => $converger->enroll(new Node(['name' => 'mini']), '192.0.2.40', 22, 'mini', '10.44.0.40', null, 'SHA256:pinned'))
        ->toThrow(function (NodeProvisioningException $exception): void {
            expect($exception->step)
                ->toBe('account')
                ->and($exception->errorCode)
                ->toBe('node.account_unavailable');
        });

    expect($ssh->commands)->toHaveCount(1);
});

it('maps native enrollment refusals to provisioning errors', function (string $agentCode, string $step, string $code): void {
    $ssh = new FakeMacAdmissionAgentRunner;
    $ssh->inspectionFailure = new AgentCommandRefused('nodes.inspect-macos-enrollment', $agentCode, 'Refused');
    $converger = macos_converger($ssh);
    expect(fn () => $converger->enroll(new Node(['name' => 'mini']), '192.0.2.40', 22, 'mini', '10.44.0.40', null, 'SHA256:pinned'))
        ->toThrow(function (NodeProvisioningException $exception) use ($step, $code): void {
            expect($exception->step)->toBe($step)->and($exception->errorCode)->toBe($code);
        });
})->with([
    ['nodes.macos_platform_mismatch', 'machine-architecture', 'node.platform_mismatch'],
    ['nodes.macos_architecture_unavailable', 'machine-architecture', 'node.architecture_unavailable'],
    ['nodes.macos_wireguard_required', 'identity', 'node.wireguard_required'],
]);

it('stops before observation when the pinned agent cannot install', function (): void {
    $agents = new FakeMacAdmissionAgentRunner;
    $agents->installFailure = new AgentCommandRefused('agent.install', 'agent.install_failed', 'Failed');
    $node = new Node(['name' => 'mini', 'platform' => 'linux', 'architecture' => 'x86_64', 'user' => 'original']);
    expect(fn () => macos_converger($agents)->enroll($node, '192.0.2.40', 22, 'mini', '10.44.0.40', null, 'SHA256:pinned'))
        ->toThrow(fn (NodeProvisioningException $e) => expect($e->step)->toBe('agent')->and($e->errorCode)->toBe('agent.install_failed'));
    expect($agents->commands)->toBe(['probe', 'agent.install']);
    expect($node->only(['platform', 'architecture', 'user']))->toBe(['platform' => 'linux', 'architecture' => 'x86_64', 'user' => 'original']);
    expect($node->exists)->toBeFalse();
});

it('does not connect when the approved fingerprint disagrees', function (): void {
    $ssh = new FakeMacAdmissionAgentRunner;
    $converger = macos_converger($ssh, fingerprint: 'SHA256:live');

    expect(fn () => $converger->enroll(new Node(['name' => 'mini']), '192.0.2.40', 22, 'mini', '10.44.0.40', null, 'SHA256:approved'))
        ->toThrow(function (NodeProvisioningException $exception): void {
            expect($exception->errorCode)->toBe('node.ssh_host_key_mismatch');
        });

    expect($ssh->commands)->toBeEmpty();
});

it('does not connect when a pinned host key changed', function (): void {
    $ssh = new FakeMacAdmissionAgentRunner;
    $converger = macos_converger($ssh, fingerprint: 'SHA256:live');

    expect(fn () => $converger->enroll(new Node(['name' => 'mini']), '192.0.2.40', 22, 'mini', '10.44.0.40', 'SHA256:pinned', 'SHA256:pinned'))
        ->toThrow(function (NodeProvisioningException $exception): void {
            expect($exception->errorCode)->toBe('node.ssh_host_key_changed');
        });

    expect($ssh->commands)->toBeEmpty();
});

it('reports a failed host key scan', function (): void {
    $ssh = new FakeMacAdmissionAgentRunner;
    $scanner = new MacOsConvergerScanner('SHA256:pinned');
    $scanner->fail = true;

    expect(fn () => macos_converger($ssh, scanner: $scanner)->enroll(new Node(['name' => 'mini']), '192.0.2.40', 22, 'mini', '10.44.0.40', null, 'SHA256:pinned'))
        ->toThrow(function (NodeProvisioningException $exception): void {
            expect($exception->step)
                ->toBe('ssh-host-key')
                ->and($exception->errorCode)
                ->toBe('node.ssh_host_key_scan_failed');
        });

    expect($ssh->commands)->toBeEmpty();
});

function macos_converger(FakeMacAdmissionAgentRunner $ssh, ?MacOsConvergerScanner $scanner = null, string $fingerprint = 'SHA256:pinned'): MacOsNodeConverger
{
    return new MacOsNodeConverger(
        $scanner ?? new MacOsConvergerScanner($fingerprint),
        $ssh,
        new MacOsConvergerKeys,
    );
}

final class MacOsConvergerKeys implements SshKeyProvider
{
    public function privateKeyPath(): string
    {
        return '/tmp/orbit-gateway-key';
    }

    public function publicKey(): string
    {
        return 'ssh-ed25519 GATEWAY';
    }
}

final class MacOsConvergerScanner implements HostKeyScanner
{
    public bool $fail = false;

    public function __construct(public string $fingerprint) {}

    public function scan(string $host, int $port, ?Node $via = null): HostKey
    {
        if ($this->fail) {
            throw new SshHostKeyScanException('scan failed');
        }

        return new HostKey('ssh-ed25519', 'PUBLICKEY', $this->fingerprint);
    }
}
