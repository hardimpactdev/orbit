<?php

declare(strict_types=1);

use App\Infrastructure\AgentCommands\AgentContract;
use App\Infrastructure\AgentCommands\AgentDelivery;
use App\Models\Node;
use Illuminate\Support\Facades\Http;

function deliveryNode(string $architecture = 'x86_64', string $platform = 'linux'): Node
{
    return new Node(['name' => 'n', 'platform' => $platform, 'architecture' => $architecture]);
}

function temporaryDelivery(array $manifest, array $files = []): string
{
    $directory = sys_get_temp_dir().'/orbit-agent-delivery-'.bin2hex(random_bytes(4));
    mkdir($directory, 0o700, true);
    foreach ($files as $name => $contents) {
        file_put_contents("{$directory}/{$name}", $contents);
    }
    file_put_contents("{$directory}/manifest.json", json_encode($manifest, JSON_THROW_ON_ERROR));

    return "{$directory}/manifest.json";
}

describe(AgentContract::class, function (): void {
    it('reads each command privilege, bound, and errors from the contract Rust writes', function (): void {
        $contract = app(AgentContract::class);
        $install = $contract->command('agent.install');

        expect($contract->names())->toContain('agent.status', 'agent.install')
            ->and($install->mutates)->toBeTrue()
            ->and($install->privilege('linux'))->toBe('root')
            ->and($install->privilege('macos'))->toBe('account')
            ->and($install->needsSudo('linux'))->toBeTrue()
            ->and($install->needsSudo('macos'))->toBeFalse()
            ->and($install->declares('agent.health_check_failed'))->toBeTrue()
            ->and($contract->commonErrors())->toContain('agent.version_mismatch', 'agent.identity_mismatch')
            ->and($contract->command('agent.status')->needsSudo('linux'))->toBeFalse()
            ->and($contract->command('agent.status')->outputMaxBytes)->toBe(0)
            ->and($contract->command('task-vm.export-git-pack')->outputMaxBytes)->toBe(2 * 1024 ** 3)
            ->and($contract->command('task-vm.export-git-pack')->needsSudo('linux'))->toBeFalse();
    });

    it('refuses an unknown command as a programming error', function (): void {
        expect(fn () => app(AgentContract::class)->command('no.such'))->toThrow(LogicException::class);
    });
});

describe(AgentDelivery::class, function (): void {
    it('pins each Node by platform and architecture and reports the presence version of that pin', function (): void {
        $delivery = app(AgentDelivery::class);
        $pin = $delivery->pin(deliveryNode());

        expect($pin)->toMatch('/\A[0-9a-f]{64}\z/')
            ->and($delivery->reportedVersion(deliveryNode()))->toBe('0.5.0+'.substr($pin, 0, 16))
            ->and(hash_file('sha256', $delivery->localFile(deliveryNode())))->toBe($pin);
    });

    it('refuses a Node platform it has no build for', function (): void {
        expect(fn () => app(AgentDelivery::class)->pin(deliveryNode('aarch64', 'macos')))
            ->toThrow(RuntimeException::class, 'no build for [macos-aarch64]');
    });

    it('maps Apple silicon to the macOS ARM build and gives a Linux ARM Node no build', function (string $architecture): void {
        $manifest = temporaryDelivery(['commit' => str_repeat('c', 40), 'version' => '0.5.0', 'artifacts' => [
            'linux-x86_64' => ['sha256' => str_repeat('a', 64)],
            'macos-aarch64' => ['sha256' => str_repeat('b', 64)],
        ]]);
        $delivery = new AgentDelivery($manifest, sys_get_temp_dir());

        expect($delivery->pin(deliveryNode($architecture, 'macos')))->toBe(str_repeat('b', 64))
            ->and($delivery->pin(deliveryNode('amd64')))->toBe(str_repeat('a', 64))
            ->and(fn () => $delivery->pin(deliveryNode($architecture)))->toThrow(RuntimeException::class, "no build for [linux-{$architecture}]");
    })->with(['arm64', 'aarch64']);

    it('refuses a delivered file that does not match its pin', function (): void {
        $manifest = temporaryDelivery(
            ['commit' => str_repeat('c', 40), 'version' => '0.5.0', 'artifacts' => ['linux-x86_64' => ['sha256' => hash('sha256', 'pinned'), 'path' => 'agent']]],
            ['agent' => 'tampered'],
        );

        expect(fn () => (new AgentDelivery($manifest, sys_get_temp_dir()))->localFile(deliveryNode()))
            ->toThrow(RuntimeException::class, 'does not match its pin');
    });

    it('refuses a manifest without a commit, version, or valid artifact digests', function (array $manifest): void {
        expect(fn () => (new AgentDelivery(temporaryDelivery($manifest), sys_get_temp_dir()))->pin(deliveryNode()))
            ->toThrow(RuntimeException::class);
    })->with([
        'no version' => [['commit' => 'c', 'artifacts' => []]],
        'bad digest' => [['commit' => 'c', 'version' => '0.5.0', 'artifacts' => ['linux-x86_64' => ['sha256' => 'ABC']]]],
    ]);
});

it('downloads a published artifact when its optional adjacent file is absent and reuses the verified cache', function (): void {
    $url = 'https://github.com/hardimpactdev/orbit/releases/download/agent-v'.str_repeat('c', 40).'/orbit-agent-linux-x86_64';
    $bytes = 'published agent';
    $manifest = temporaryDelivery(['commit' => str_repeat('c', 40), 'version' => '0.5.0', 'artifacts' => [
        'linux-x86_64' => ['sha256' => hash('sha256', $bytes), 'path' => 'orbit-agent-linux-x86_64', 'url' => $url],
    ]]);
    Http::fake([$url => Http::response($bytes)]);
    $delivery = new AgentDelivery($manifest, dirname($manifest).'/cache');
    expect(file_get_contents($delivery->localFile(deliveryNode())))->toBe($bytes)
        ->and($delivery->localFile(deliveryNode()))->toBe(dirname($manifest).'/cache/'.hash('sha256', $bytes));
    Http::assertSentCount(1);
});

it('refuses a corrupt adjacent artifact instead of downloading another copy', function (): void {
    $manifest = temporaryDelivery(['commit' => str_repeat('c', 40), 'version' => '0.5.0', 'artifacts' => [
        'linux-x86_64' => ['sha256' => hash('sha256', 'pinned'), 'path' => 'agent', 'url' => 'https://example.test/agent'],
    ]], ['agent' => 'corrupt']);
    Http::fake();
    expect(fn () => (new AgentDelivery($manifest, dirname($manifest).'/cache'))->localFile(deliveryNode()))
        ->toThrow(RuntimeException::class, 'does not match its pin');
    Http::assertNothingSent();
});
