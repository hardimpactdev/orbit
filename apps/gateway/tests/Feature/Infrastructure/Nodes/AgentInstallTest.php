<?php

declare(strict_types=1);

use App\Domain\Certificates\LeafCertificateSigner;
use App\Domain\Nodes\ManagedNodeEligibility;
use App\Domain\Nodes\ManagedUserAccount;
use App\Domain\Nodes\ManagedUserAccountResolver;
use App\Domain\Nodes\NodeAgentRuntime;
use App\Domain\Nodes\RoleName;
use App\Domain\Nodes\Storage\NodeSettingsNormalizer;
use App\Domain\Nodes\Storage\StorageRootResolver;
use App\Domain\Shared\LifecycleStatus;
use App\Domain\Shared\ResourceOperationException;
use App\Infrastructure\AgentCommands\AgentCommandRefused;
use App\Infrastructure\Nodes\NodeAgentFootprint;
use App\Infrastructure\Nodes\NodeAgentRoleConverger;
use App\Infrastructure\Nodes\NodeAgentSshExecutor;
use App\Infrastructure\Nodes\NodeLocks;
use App\Models\Instance;
use App\Models\Node;
use App\Models\Project;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\Log;
use Tests\Support\FakeAgentCommandRunner;

it('refuses macOS agent installation before the agent and skips removal', function (): void {
    $runner = new FakeAgentCommandRunner;
    $agent = nodeAgentExecutor($runner);
    $node = nodeAgentNode();
    $node->platform = 'macos';

    expect(fn () => $agent->converge($node))->toThrow(function (ResourceOperationException $exception): void {
        expect($exception->errorCode)->toBe('node.agent_unsupported')
            ->and($exception->status)->toBe(422);
    });
    $agent->remove($node);

    expect($runner->calls)->toBeEmpty()->and($runner->installs)->toBeEmpty();
});

it('keeps the role converge going when the agent install fails', function (): void {
    Log::spy();
    $agent = new class implements NodeAgentRuntime
    {
        public function converge(Node $node): void
        {
            throw new RuntimeException('agent unavailable');
        }

        public function remove(Node $node): void
        {
            throw new RuntimeException('agent unavailable');
        }
    };
    $node = nodeAgentNode();
    $node->status = 'active';
    $node->platform = 'linux';
    $node->ssh_host_fingerprint = 'SHA256:'.str_repeat('A', 43);

    expect(fn () => (new NodeAgentRoleConverger($agent, new ManagedNodeEligibility))->converge($node))
        ->not->toThrow(Throwable::class);

    Log::shouldHaveReceived('warning')->once();
});

it('installs the pinned binary before it configures the agent, and tells it the binary changed', function (): void {
    $runner = nodeAgentConfigured((new FakeAgentCommandRunner)->installReplaces());
    $commandsAtInstall = null;
    $runner->onInstall(static function () use ($runner, &$commandsAtInstall): void {
        $commandsAtInstall = $runner->commands();
    });

    nodeAgentExecutor($runner)->converge(nodeAgentStoredNode());

    expect($runner->installs)->toBe(['app-prod'])
        ->and($commandsAtInstall)->toBe([])
        ->and($runner->commands())->toBe(['agent.configure'])
        ->and($runner->calls[0]['input']['binary_changed'])->toBeTrue();
});

it('fails the converge with agent.install_failed and configures nothing when the agent installer fails', function (): void {
    $runner = (new FakeAgentCommandRunner)->failInstall(new AgentCommandRefused('agent.install', 'agent.install_failed', 'health check failed', ['cause' => 'agent.health_check_failed']));
    $node = nodeAgentStoredNode();
    $node->forceFill(['agent_secret_hash' => str_repeat('c', 64)])->save();

    expect(fn () => nodeAgentExecutor($runner)->converge($node))
        ->toThrow(fn (ResourceOperationException $exception): bool => $exception->errorCode === 'agent.install_failed'
            && $exception->status === 502
            && str_contains($exception->getMessage(), 'agent.health_check_failed'));
    expect($runner->commands())->toBe([])
        ->and($node->fresh()?->agent_secret_hash)->toBe(str_repeat('c', 64));
});

it('sends the configuration, Gateway CA, and narrowed unit to the agent', function (): void {
    $runner = nodeAgentConfigured();

    nodeAgentExecutor($runner)->converge(nodeAgentNode());

    $input = $runner->calls[0]['input'];
    expect($input['configuration'])->toBe('gateway_url = "https://gateway.orbit"'."\n".'gateway_address = "10.44.0.1"'."\n")
        ->and($input['certificate'])->toContain('-----BEGIN CERTIFICATE-----')
        ->and($input['unit'])->toContain(NodeAgentFootprint::Marker, 'Restart=always', 'RestartSec=2', 'CapabilityBoundingSet=', 'NoNewPrivileges=yes', 'ProtectSystem=strict', 'ProtectHome=tmpfs', 'BindReadOnlyPaths=-/home/orbit/apps', 'PrivateTmp=yes', 'MemoryMax=128M', 'RestrictAddressFamilies=AF_UNIX AF_INET AF_INET6')
        ->and($input['binary_changed'])->toBeFalse();
});

it('binds back only the Instance root under an empty home', function (?ManagedUserAccount $account, array $settings, array $present, array $absent): void {
    $runner = nodeAgentConfigured();
    $node = nodeAgentNode();
    $node->settings = $settings;

    nodeAgentExecutor($runner, $account)->converge($node);

    expect($runner->calls[0]['input']['unit'])->toContain('CapabilityBoundingSet=', ...$present)
        ->not->toContain(...$absent);
})->with([
    'default root' => [new ManagedUserAccount('deploy', 'deploy', '/home/deploy'), [], ['ProtectHome=tmpfs', 'BindReadOnlyPaths=-/home/deploy/apps'], ['ProtectHome=read-only', 'ProtectHome=yes', 'SupplementaryGroups=']],
    'unsafe home' => [new ManagedUserAccount('orbit', 'orbit', '/home/orbit dir'), [], ['ProtectHome=yes'], ['BindReadOnlyPaths=']],
    'unknown account' => [null, [], ['ProtectHome=yes'], ['SupplementaryGroups=', 'BindReadOnlyPaths=']],
]);

it('writes the Gateway address while the gateway role itself converges', function (): void {
    $runner = nodeAgentConfigured();
    $agent = nodeAgentExecutor($runner);
    // `node:role:add gateway gateway --converge` marks the assignment provisioning while it runs.
    Node::query()->whereHas('roles', static fn ($query) => $query->where('role', RoleName::Gateway))
        ->firstOrFail()->roles()->where('role', RoleName::Gateway)->update(['status' => LifecycleStatus::Provisioning]);

    $agent->converge(nodeAgentNode());

    expect($runner->calls[0]['input']['configuration'])->toContain('gateway_address = "10.44.0.1"');
});

it('removes the agent through the agent', function (): void {
    $runner = (new FakeAgentCommandRunner)->answer('agent.remove', []);

    nodeAgentExecutor($runner)->remove(nodeAgentNode());

    expect($runner->commands())->toBe(['agent.remove']);
});

it('fails agent removal when the agent cannot remove its files', function (): void {
    $runner = (new FakeAgentCommandRunner)->refuse('agent.remove', 'agent.configure_failed', ['step' => 'agent-remove']);

    expect(fn () => nodeAgentExecutor($runner)->remove(nodeAgentNode()))
        ->toThrow(fn (ResourceOperationException $exception): bool => $exception->errorCode === 'agent.remove_failed');
});

function nodeAgentStoredNode(): Node
{
    return Node::query()->forceCreate([
        'name' => 'app-prod',
        'status' => LifecycleStatus::Active,
        'platform' => 'linux',
        'public_ssh_host' => '192.0.2.4',
        'user' => 'orbit',
        'architecture' => 'x86_64',
        'wireguard_ip' => '10.44.0.4',
    ]);
}

/**
 * Answers `agent.configure` like the agent: it keeps the installed secret while its hash matches the
 * record and otherwise writes a new one. `$secret` is the Node's secret file; `$hashAtConfigure` the
 * record the Gateway held while the agent ran.
 */
function nodeAgentConfigured(FakeAgentCommandRunner $runner = new FakeAgentCommandRunner, ?string &$secret = null, mixed &$hashAtConfigure = null): FakeAgentCommandRunner
{
    return $runner->answer('agent.configure', static function (array $input, Node $node) use (&$secret, &$hashAtConfigure): array {
        $hashAtConfigure = $node->exists ? Node::query()->whereKey($node->getKey())->value('agent_secret_hash') : null;
        $kept = $secret !== null && $input['secret_hash'] === hash('sha256', $secret);
        if (! $kept) {
            $secret = bin2hex(random_bytes(32));
        }

        return ['secret_hash' => hash('sha256', $secret), 'restarted' => ! $kept || $input['binary_changed'], 'unclosed' => []];
    });
}

describe('the agent secret', function (): void {
    it('stores only the hash the agent answers', function (): void {
        $secret = null;
        $node = nodeAgentStoredNode();

        nodeAgentExecutor(nodeAgentConfigured(secret: $secret))->converge($node);

        expect($secret)->toMatch('/\A[0-9a-f]{64}\z/')
            ->and($node->fresh()?->agent_secret_hash)->toBe(hash('sha256', (string) $secret));
    });

    it('sends the stored hash so the agent keeps a matching secret', function (): void {
        $secret = null;
        $node = nodeAgentStoredNode();
        $runner = nodeAgentConfigured(secret: $secret);
        $agent = nodeAgentExecutor($runner);
        $agent->converge($node);
        $first = $secret;

        $agent->converge($node->fresh() ?? $node);

        expect($runner->calls[0]['input']['secret_hash'])->toBeNull()
            ->and($runner->calls[1]['input']['secret_hash'])->toBe(hash('sha256', (string) $first))
            ->and($secret)->toBe($first);
    });

    it('sends no hash when the stored one is not a SHA-256 digest', function (): void {
        $node = nodeAgentStoredNode();
        $node->forceFill(['agent_secret_hash' => 'not-a-hash'])->save();
        $runner = nodeAgentConfigured();

        nodeAgentExecutor($runner)->converge($node);

        expect($runner->calls[0]['input']['secret_hash'])->toBeNull();
    });

    it('stores a replacement hash only after the agent restarted from it, so a failed rotation shows as a mismatch', function (): void {
        $node = nodeAgentStoredNode();
        $node->forceFill(['agent_secret_hash' => str_repeat('c', 64)])->save();
        $runner = (new FakeAgentCommandRunner)->refuse('agent.configure', 'agent.configure_failed', ['step' => 'agent-restart']);

        expect(fn () => nodeAgentExecutor($runner)->converge($node))
            ->toThrow(fn (ResourceOperationException $exception): bool => $exception->errorCode === 'agent.install_failed' && str_contains($exception->getMessage(), 'agent-restart'));

        expect($node->fresh()?->agent_secret_hash)->toBe(str_repeat('c', 64));
    });

    it('keeps the old record while the agent configures itself', function (): void {
        $node = nodeAgentStoredNode();
        $node->forceFill(['agent_secret_hash' => str_repeat('c', 64)])->save();
        $hashAtConfigure = null;

        nodeAgentExecutor(nodeAgentConfigured(hashAtConfigure: $hashAtConfigure))->converge($node);

        expect($hashAtConfigure)->toBe(str_repeat('c', 64))
            ->and($node->fresh()?->agent_secret_hash)->not->toBe(str_repeat('c', 64));
    });

    it('stops without touching the record when another converge took over the lock during the binary installation', function (): void {
        $node = nodeAgentStoredNode();
        $name = 'node-agent:id:'.$node->getKey();
        $runner = nodeAgentConfigured((new FakeAgentCommandRunner)->installReplaces())->onInstall(static function () use ($name): void {
            app(NodeLocks::class)->lock($name, 1)->forceRelease();
            app(NodeLocks::class)->lock($name, 60)->get();
        });

        try {
            expect(fn () => nodeAgentExecutor($runner)->converge($node))
                ->toThrow(fn (ResourceOperationException $exception): bool => $exception->errorCode === 'agent.converge_lock_lost');
        } finally {
            app(NodeLocks::class)->lock($name, 1)->forceRelease();
        }

        expect($node->fresh()?->agent_secret_hash)->toBeNull()
            ->and($runner->commands())->toBe([]);
    });

    it('stops without touching the record when another converge took over the lock while the agent configured itself', function (): void {
        $node = nodeAgentStoredNode();
        $name = 'node-agent:id:'.$node->getKey();
        $runner = (new FakeAgentCommandRunner)->answer('agent.configure', static function () use ($name): array {
            app(NodeLocks::class)->lock($name, 1)->forceRelease();
            app(NodeLocks::class)->lock($name, 60)->get();

            return ['secret_hash' => str_repeat('d', 64), 'restarted' => true, 'unclosed' => []];
        });

        try {
            expect(fn () => nodeAgentExecutor($runner)->converge($node))
                ->toThrow(fn (ResourceOperationException $exception): bool => $exception->errorCode === 'agent.converge_lock_lost');
        } finally {
            app(NodeLocks::class)->lock($name, 1)->forceRelease();
        }

        expect($node->fresh()?->agent_secret_hash)->toBeNull();
    });

    it('serializes converges of the same Node', function (): void {
        $node = nodeAgentStoredNode();
        $lock = app(NodeLocks::class)->lock('node-agent:id:'.$node->getKey(), 60);
        $lock->get();

        try {
            nodeAgentExecutor(nodeAgentConfigured(), lockWaitSeconds: 0)->converge($node);
            test()->fail('Expected the held lock to refuse the converge.');
        } catch (ResourceOperationException $exception) {
            expect($exception->errorCode)->toBe('agent.converge_busy');
        } finally {
            $lock->release();
        }

        $runner = (new FakeAgentCommandRunner)->failInstall(new AgentCommandRefused('agent.install', 'agent.install_failed', 'failed'));
        expect(fn () => nodeAgentExecutor($runner, lockWaitSeconds: 0)->converge($node))->toThrow(ResourceOperationException::class);

        nodeAgentExecutor(nodeAgentConfigured(), lockWaitSeconds: 0)->converge($node);

        expect(true)->toBeTrue();
    });
});

it('keeps the Node locks in a file store under ORBIT_HOME, whatever CACHE_STORE says', function (): void {
    $home = sys_get_temp_dir().'/orbit-node-locks-'.bin2hex(random_bytes(4));
    config(['orbit.home' => $home, 'cache.default' => 'database']);
    app()->forgetInstance(NodeLocks::class);

    try {
        $lock = app(NodeLocks::class)->lock('node-agent:id:1', 30);

        expect($lock->get())->toBeTrue()
            ->and(glob($home.'/cache/node-locks/*/*/*') ?: [])->not->toBe([])
            ->and(app(NodeLocks::class)->lock('node-agent:id:1', 30)->get())->toBeFalse();

        $lock->release();
    } finally {
        (new Filesystem)->deleteDirectory($home);
    }
});

function nodeAgentExecutor(FakeAgentCommandRunner $runner, ?ManagedUserAccount $account = new ManagedUserAccount('orbit', 'orbit', '/home/orbit'), int $lockWaitSeconds = 120): NodeAgentSshExecutor
{
    if (! Node::query()->whereHas('roles', static fn ($query) => $query->where('role', RoleName::Gateway)->where('status', LifecycleStatus::Active))->exists()) {
        $gateway = Node::query()->create([
            'name' => 'agent-install-gateway',
            'status' => LifecycleStatus::Active,
            'platform' => 'linux',
            'public_ssh_host' => '192.0.2.1',
            'wireguard_ip' => '10.44.0.1',
            'user' => 'orbit',
        ]);
        $gateway->roles()->create(['role' => RoleName::Gateway, 'status' => LifecycleStatus::Active]);
    }

    return new NodeAgentSshExecutor(
        new AgentInstallCertificates,
        new readonly class($account) implements ManagedUserAccountResolver
        {
            public function __construct(private ?ManagedUserAccount $account) {}

            public function resolve(Node $node): ManagedUserAccount
            {
                return $this->account ?? throw new RuntimeException('getent failed');
            }
        },
        app(StorageRootResolver::class),
        app(NodeSettingsNormalizer::class),
        $runner,
        app(NodeLocks::class),
        $lockWaitSeconds,
    );
}

function nodeAgentNode(): Node
{
    return new Node([
        'name' => 'app-prod',
        'platform' => 'linux',
        'user' => 'orbit',
        'architecture' => 'x86_64',
        'wireguard_ip' => '10.44.0.4',
    ]);
}

final class AgentInstallCertificates implements LeafCertificateSigner
{
    public function sign(string $hostname, string $certificateRequest): string
    {
        return '';
    }

    public function rootCertificate(): string
    {
        return "-----BEGIN CERTIFICATE-----\nroot\n-----END CERTIFICATE-----\n";
    }
}

it('gets everything it needs to narrow the unit from the container', function (): void {
    $executor = app(NodeAgentSshExecutor::class);

    expect(new ReflectionProperty($executor, 'storageRoots')->getValue($executor))->toBeInstanceOf(StorageRootResolver::class)
        ->and(new ReflectionProperty($executor, 'nodeSettings')->getValue($executor))->toBeInstanceOf(NodeSettingsNormalizer::class)
        ->and(new ReflectionProperty($executor, 'accounts')->getValue($executor))->toBeInstanceOf(ManagedUserAccountResolver::class);
});

/** @return array{string, Node} A temporary managed home and a saved Node whose Instances live under its `apps`. */
function agent_env_home(string $relative = ''): array
{
    $home = sys_get_temp_dir().'/orbit-agent-env-'.bin2hex(random_bytes(4));
    mkdir($home.'/apps/shop/dev'.$relative, 0o755, true);
    mkdir($home.'/apps/shop/linked'.$relative, 0o755, true);
    mkdir($home.'/outside', 0o755, true);
    $node = Node::query()->create([
        'name' => 'agent-env', 'status' => LifecycleStatus::Active, 'platform' => 'linux', 'user' => 'orbit',
        'architecture' => 'x86_64', 'public_ssh_host' => '192.0.2.45', 'wireguard_ip' => '10.44.0.45',
    ]);
    $project = Project::query()->create(['name' => 'Shop', 'slug' => 'shop', 'repository_url' => 'git@example.test:shop.git', 'default_branch' => 'main', 'root' => ltrim($relative.'/public', '/')]);
    foreach (['dev' => $home.'/apps/shop/dev', 'linked' => $home.'/apps/shop/linked', 'outside' => $home.'/outside'] as $name => $path) {
        Instance::query()->create(['project_id' => $project->id, 'node_id' => $node->id, 'name' => $name, 'checkout_path' => $path, 'status' => 'source_resolved']);
    }

    return [$home, $node];
}

it('sends the agent every Instance checkout in the Instance root', function (string $relative): void {
    [$home, $node] = agent_env_home($relative);
    $runner = nodeAgentConfigured();

    try {
        nodeAgentExecutor($runner, new ManagedUserAccount('orbit', 'orbit', $home))->converge($node);

        expect($runner->calls[0]['input']['checkouts'])->toBe([$home.'/apps/shop/dev'.$relative, $home.'/apps/shop/linked'.$relative]);
    } finally {
        (new Filesystem)->deleteDirectory($home);
    }
})->with(['root public' => '', 'nested Laravel' => '/server/web']);

it('logs the checkouts whose environment the agent could not close and still converges', function (): void {
    [$home, $node] = agent_env_home();
    Log::spy();
    $runner = (new FakeAgentCommandRunner)->answer('agent.configure', ['secret_hash' => str_repeat('d', 64), 'restarted' => true, 'unclosed' => [$home.'/apps/shop/dev']]);

    try {
        nodeAgentExecutor($runner, new ManagedUserAccount('orbit', 'orbit', $home))->converge($node);

        Log::shouldHaveReceived('warning')->withArgs(
            static fn (string $message, array $context): bool => $message === 'The agent converge could not close Instance environments.'
                && $context['checkouts'] === [$home.'/apps/shop/dev'],
        )->once();
        expect($node->fresh()?->agent_secret_hash)->toBe(str_repeat('d', 64));
    } finally {
        (new Filesystem)->deleteDirectory($home);
    }
});
