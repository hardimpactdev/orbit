<?php

declare(strict_types=1);

use App\Domain\GatewayReleases\GatewayReleaseException;
use App\Domain\GatewayReleases\GatewayReleaseLayout;
use App\Infrastructure\AgentCommands\AgentCommandRefused;
use App\Infrastructure\AgentCommands\AgentDelivery;
use App\Infrastructure\GatewayReleases\GatewayReleaseAgentDelivery;
use App\Infrastructure\GatewayReleases\GatewayReleaseRetry;
use App\Infrastructure\Processes\CommandResult;
use App\Infrastructure\Processes\ProcessInvocation;
use App\Infrastructure\Processes\ProcessRunner;
use App\Infrastructure\Releases\ReleaseProvenance;
use App\Models\Node;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\Http;
use Tests\Support\FakeAgentCommandRunner;
use Tests\Support\SigstoreTestSigner;

beforeEach(function (): void {
    $this->base = sys_get_temp_dir().'/orbit-release-agent-'.bin2hex(random_bytes(6));
    $this->layout = new GatewayReleaseLayout($this->base.'/orbit/apps/gateway');
    $this->sha = str_repeat('a', 40);
    $this->tag = 'cli-v0.6900.0';
    $this->download = 'https://github.com/hardimpactdev/orbit/releases/download/'.$this->tag;
    $this->gateway = new Node()->forceFill(['name' => 'gateway', 'platform' => 'linux', 'architecture' => 'x86_64']);
    $this->binaries = [];
    $this->checksums = '';
    $this->manifest = ['commit' => str_repeat('b', 40), 'version' => '0.5.0', 'artifacts' => []];
    foreach (GatewayReleaseAgentDelivery::Platforms as $platform) {
        $name = 'orbit-agent-'.$platform;
        $this->binaries[$name] = 'binary for '.$platform;
        $digest = hash('sha256', $this->binaries[$name]);
        $this->checksums .= $digest.'  '.$name."\n";
        $this->manifest['artifacts'][$platform] = ['sha256' => $digest, 'path' => $name, 'url' => $this->download.'/'.$name];
    }
    $this->tags = "{$this->sha}\trefs/tags/{$this->tag}\n".str_repeat('c', 40)."\trefs/tags/cli-v0.6899.0\n";
    $this->listings = [];
    $this->trustRoot = SigstoreTestSigner::instance()->trustRootPath();
    $this->installs = new FakeAgentCommandRunner;
    $this->installed = [];
    $this->delivery = fn (): GatewayReleaseAgentDelivery => new GatewayReleaseAgentDelivery(
        processes: new class($this) implements ProcessRunner
        {
            public function __construct(private object $test) {}

            public function run(ProcessInvocation $invocation): CommandResult
            {
                $this->test->listings[] = $invocation->arguments;

                return new CommandResult(exitCode: 0, stdout: $this->test->tags, stderr: '', durationMs: 1, truncated: false);
            }
        },
        provenance: new ReleaseProvenance('https://github.com/hardimpactdev/orbit', $this->trustRoot),
        repository: 'https://github.com/hardimpactdev/orbit',
        cacheDirectory: $this->base.'/home/agent-artifacts',
        runner: function (AgentDelivery $delivery): FakeAgentCommandRunner {
            $this->installed[] = [$delivery->pin($this->gateway), is_file($delivery->localFile($this->gateway))];

            return $this->installs;
        },
    );
    // The release CI publishes: its provenance signs exactly the manifest and SHA256SUMS it serves, unless a test
    // passes another bundle response.
    $this->publish = function (?string $manifest = null, mixed $provenance = null): void {
        $manifest ??= json_encode($this->manifest);
        Http::fake([
            $this->download.'/manifest.json' => Http::response($manifest),
            $this->download.'/SHA256SUMS' => Http::response($this->checksums),
            $this->download.'/provenance.sigstore.json' => $provenance ?? Http::response(SigstoreTestSigner::instance()->bundle(['manifest.json' => $manifest, 'SHA256SUMS' => $this->checksums])),
            ...array_map(static fn (string $body) => Http::response($body), array_combine(array_map(fn (string $name): string => $this->download.'/'.$name, array_keys($this->binaries)), $this->binaries)),
        ]);
    };
    Http::preventStrayRequests();
});

afterEach(function (): void {
    if (is_dir($this->base)) {
        exec('chmod -R u+w '.escapeshellarg($this->base));
        new Filesystem()->deleteDirectory($this->base);
    }
});

it('finds the commit by its release tag and verifies the Gateway binary without authentication before any change', function (): void {
    ($this->publish)();

    $fetched = ($this->delivery)()->fetch('aaaaaaa', $this->gateway);

    expect($fetched)->toBe(['sha' => $this->sha, 'manifest' => json_encode($this->manifest)])
        ->and($this->listings)->toBe([['git', 'ls-remote', '--tags', '--refs', 'https://github.com/hardimpactdev/orbit', 'refs/tags/cli-v*']])
        ->and(hash_file('sha256', $this->base.'/home/agent-artifacts/'.$this->manifest['artifacts']['linux-x86_64']['sha256']))->toBe($this->manifest['artifacts']['linux-x86_64']['sha256'])
        ->and(glob($this->base.'/home/agent-artifacts/*'))->toHaveCount(1)
        ->and(is_dir($this->base.'/releases'))->toBeFalse();
    Http::assertSent(static fn ($request): bool => ! $request->hasHeader('Authorization'));
    Http::assertSent(fn ($request): bool => $request->url() === $this->download.'/provenance.sigstore.json');
    Http::assertNotSent(static fn ($request): bool => str_ends_with($request->url(), 'linux-aarch64'));
});

it('refuses a release whose provenance is missing or does not verify, before it stages or downloads the agent', function (Closure $provenance): void {
    ($this->publish)(provenance: $provenance->call($this));

    $error = release_failure(fn () => ($this->delivery)()->fetch($this->sha, $this->gateway));

    expect($error->errorCode)->toBe('gateway.release_provenance_invalid')->and($error->step)->toBe('agent-delivery')->and($error->sha)->toBe($this->sha)
        ->and(file_exists($this->base.'/home/agent-artifacts'))->toBeFalse();
    Http::assertNotSent(static fn ($request): bool => str_contains($request->url(), '/orbit-agent-'));
})->with([
    'no bundle, as in a release from before provenance' => [fn () => Http::response('Not Found', 404)],
    'a bundle for another manifest' => [fn () => Http::response(SigstoreTestSigner::instance()->bundle(['manifest.json' => '{}', 'SHA256SUMS' => $this->checksums]))],
    'a bundle from another workflow' => [fn () => Http::response(SigstoreTestSigner::instance()->bundle(['manifest.json' => json_encode($this->manifest), 'SHA256SUMS' => $this->checksums], ['identity' => 'https://github.com/hardimpactdev/orbit/.github/workflows/ci.yml@refs/heads/main']))],
]);

it('waits without failing the commit when a Gateway setting keeps provenance from being checked', function (Closure $trustRoot): void {
    ($this->publish)();
    $this->trustRoot = $trustRoot->call($this);

    $error = release_failure(fn () => ($this->delivery)()->fetch($this->sha, $this->gateway));

    expect($error->errorCode)->toBe('gateway.release_provenance_unconfigured')
        ->and(new GatewayReleaseRetry()->retryable($error->errorCode, $this->sha))->toBeTrue()
        ->and(file_exists($this->base.'/home/agent-artifacts'))->toBeFalse();
})->with([
    'a trust root path with a typo' => [fn (): string => $this->base.'/missing-trusted-root.json'],
    'a trust root that is not JSON' => [function (): string {
        mkdir($this->base, 0700, true);
        file_put_contents($this->base.'/trusted_root.json', '<html>');

        return $this->base.'/trusted_root.json';
    }],
]);

it('waits when the provenance bundle cannot be downloaded', function (): void {
    ($this->publish)(provenance: Http::response('Bad Gateway', 502));

    expect(release_failure(fn () => ($this->delivery)()->fetch($this->sha, $this->gateway))->errorCode)->toBe('gateway.release_agent_delivery_unavailable')
        ->and(file_exists($this->base.'/home/agent-artifacts'))->toBeFalse();
});

it('refuses while the commit has no published release, so a later attempt can find it', function (): void {
    $this->tags = str_repeat('c', 40)."\trefs/tags/cli-v0.6899.0\n";

    $error = release_failure(fn () => ($this->delivery)()->fetch($this->sha, $this->gateway));

    expect($error->errorCode)->toBe('gateway.release_agent_delivery_unavailable')->and($error->step)->toBe('agent-delivery')
        ->and(file_exists($this->base))->toBeFalse();
    Http::assertNothingSent();
});

it('refuses a manifest that does not match the release or its SHA256SUMS', function (string $fault): void {
    $manifest = $this->manifest;
    match ($fault) {
        'checksum' => $manifest['artifacts']['linux-x86_64']['sha256'] = str_repeat('d', 64),
        'other release' => $manifest['artifacts']['linux-x86_64']['url'] = 'https://github.com/hardimpactdev/orbit/releases/download/cli-v0.1.0.0/orbit-agent-linux-x86_64',
        'missing platform' => $manifest['artifacts'] = array_slice($manifest['artifacts'], 1),
    };
    ($this->publish)(json_encode($manifest));

    $error = release_failure(fn () => ($this->delivery)()->fetch($this->sha, $this->gateway));

    expect($error->errorCode)->toBe('gateway.release_agent_delivery_invalid')->and(file_exists($this->base.'/home/agent-artifacts'))->toBeFalse();
})->with(['checksum', 'other release', 'missing platform']);

// A release must contain exactly the supported platforms, even when extra binaries have valid checksums.
it('refuses releases that still deliver Linux ARM or another extra platform', function (string $fault): void {
    foreach (['linux-aarch64', ...($fault === 'unknown platform' ? ['linux-riscv64'] : [])] as $platform) {
        $name = 'orbit-agent-'.$platform;
        $this->binaries[$name] = 'binary for '.$platform;
        $digest = hash('sha256', $this->binaries[$name]);
        $this->checksums .= $digest.'  '.$name."\n";
        $this->manifest['artifacts'][$platform] = ['sha256' => $fault === 'checksum' ? str_repeat('d', 64) : $digest, 'path' => $name, 'url' => $this->download.'/'.$name];
    }
    ($this->publish)();

    expect(release_failure(fn () => ($this->delivery)()->fetch($this->sha, $this->gateway))->errorCode)->toBe('gateway.release_agent_delivery_invalid');
})->with(['none', 'checksum', 'unknown platform']);

it('refuses a Gateway binary whose bytes do not match the pin', function (): void {
    $this->binaries['orbit-agent-linux-x86_64'] = 'tampered';
    ($this->publish)();

    expect(release_failure(fn () => ($this->delivery)()->fetch($this->sha, $this->gateway))->errorCode)->toBe('gateway.release_agent_delivery_unavailable')
        ->and(glob($this->base.'/home/agent-artifacts/*'))->toBe([]);
});

it('puts the manifest into a sealed release and installs that pin on the Gateway', function (): void {
    ($this->publish)();
    $agent = $this->layout->releasePath('aaaaaaaaaaaa').'/apps/agent';
    mkdir($agent, 0755, true);
    chmod($agent, 0555);
    $delivery = ($this->delivery)();
    $fetched = $delivery->fetch($this->sha, $this->gateway);

    $delivery->install($fetched, $this->layout, $this->gateway);
    $delivery->install($fetched, $this->layout, $this->gateway);

    $path = $agent.'/delivery/manifest.json';
    expect(file_get_contents($path))->toBe($fetched['manifest'])
        ->and(fileperms($path) & 0777)->toBe(0444)
        ->and(fileperms(dirname($path)) & 0777)->toBe(0555)
        ->and(fileperms($agent) & 0777)->toBe(0555)
        ->and(glob(dirname($path).'/.manifest-*'))->toBe([])
        ->and($this->installed)->toBe(array_fill(0, 2, [$this->manifest['artifacts']['linux-x86_64']['sha256'], true]))
        ->and($this->installs->installs)->toHaveCount(2);
});

it('uses again the empty delivery directory that an interrupted attempt left in a sealed release', function (): void {
    ($this->publish)();
    $delivery = ($this->delivery)();
    $fetched = $delivery->fetch($this->sha, $this->gateway);
    $agent = $this->layout->releasePath('aaaaaaaaaaaa').'/apps/agent';
    mkdir($agent.'/delivery', 0755, true);
    chmod($agent.'/delivery', 0555);
    chmod($agent, 0555);

    $delivery->install($fetched, $this->layout, $this->gateway);

    expect(file_get_contents($agent.'/delivery/manifest.json'))->toBe($fetched['manifest'])
        ->and(fileperms($agent.'/delivery') & 0777)->toBe(0555)
        ->and(fileperms($agent) & 0777)->toBe(0555);
});

it('refuses a release that already holds another manifest', function (): void {
    ($this->publish)();
    $delivery = ($this->delivery)();
    $fetched = $delivery->fetch($this->sha, $this->gateway);
    $path = $this->layout->releasePath('aaaaaaaaaaaa').'/'.GatewayReleaseAgentDelivery::Manifest;
    mkdir(dirname($path), 0755, true);
    file_put_contents($path, '{"other":true}');

    expect(release_failure(fn () => $delivery->install($fetched, $this->layout, $this->gateway))->errorCode)->toBe('gateway.release_agent_delivery_invalid')
        ->and(file_get_contents($path))->toBe('{"other":true}')
        ->and($this->installed)->toBe([]);
});

it('reports an installer refusal on the Gateway as a release failure', function (): void {
    ($this->publish)();
    mkdir($this->layout->releasePath('aaaaaaaaaaaa').'/apps/agent', 0755, true);
    $this->installs->failInstall(new AgentCommandRefused('agent.install', 'agent.install_failed', 'The pinned agent failed to install.', ['cause' => 'agent.health_check_failed']));
    $delivery = ($this->delivery)();

    $error = release_failure(fn () => $delivery->install($delivery->fetch($this->sha, $this->gateway), $this->layout, $this->gateway));

    expect($error)->toBeInstanceOf(GatewayReleaseException::class)
        ->and($error->errorCode)->toBe('gateway.release_agent_install_failed')
        ->and($error->getMessage())->toContain('agent.health_check_failed');
});
