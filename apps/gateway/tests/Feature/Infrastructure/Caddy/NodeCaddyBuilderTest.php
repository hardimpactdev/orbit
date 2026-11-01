<?php

declare(strict_types=1);

use App\Domain\Nodes\RoleName;
use App\Domain\Operations\OperationStatus;
use App\Domain\Shared\LifecycleStatus;
use App\Infrastructure\AgentCommands\AgentCommandRunner;
use App\Infrastructure\AgentView\WebSocketClient;
use App\Infrastructure\Caddy\Build\CaddyfileSiteDiff;
use App\Infrastructure\Caddy\Build\CaddyListenerRule;
use App\Infrastructure\Caddy\Build\CaddySite;
use App\Infrastructure\Caddy\Build\ContributedCaddySiteSource;
use App\Infrastructure\Caddy\Build\NodeCaddyBuilder;
use App\Infrastructure\Caddy\Build\NodeCaddyBuildException;
use App\Infrastructure\Caddy\Build\NodeCaddyBuildLock;
use App\Infrastructure\Caddy\Build\NodeCaddyfileRenderer;
use App\Infrastructure\Caddy\Build\NodeCaddySiteSource;
use App\Infrastructure\Caddy\Build\NodeCaddyTransport;
use App\Models\Node;
use App\Models\Operation;
use Illuminate\Filesystem\Filesystem;
use Tests\Support\CaddySiteCertificateFixtures;
use Tests\Support\FakeAgentCommandRunner;
use Tests\Support\FakeRoutePublicationSocket;

beforeEach(function (): void {
    $this->lockDirectory = sys_get_temp_dir().'/orbit-caddy-build-lock-'.bin2hex(random_bytes(6));
    $this->agents = new FakeAgentCommandRunner()->answer('caddy.check-addresses', []);
    app()->instance(AgentCommandRunner::class, $this->agents);
});

afterEach(function (): void {
    new Filesystem()->deleteDirectory($this->lockDirectory);
});

describe('the Gateway Node lock', function (): void {
    it('fails after waiting 30 seconds while another build holds the Node', function (): void {
        $now = 0.0;
        $lock = new NodeCaddyBuildLock(
            directory: $this->lockDirectory,
            clock: function () use (&$now): float {
                return $now;
            },
            wait: function (int $microseconds) use (&$now): void {
                $now += $microseconds / 1_000_000;
            },
        );
        mkdir($this->lockDirectory, 0o700, true);
        $holder = fopen($this->lockDirectory.'/node-7.lock', 'c+');
        flock($holder, LOCK_EX);

        try {
            expect(fn () => $lock->run(7, 'app-dev', static fn (): string => 'built'))
                ->toThrow(fn (NodeCaddyBuildException $exception) => expect($exception->stage)->toBe('gateway-lock'));
            expect($now)->toBeGreaterThanOrEqual(30.0);
        } finally {
            flock($holder, LOCK_UN);
            fclose($holder);
        }

        expect($lock->run(7, 'app-dev', static fn (): string => 'built'))->toBe('built')
            ->and($lock->run(8, 'other', static fn (): string => 'other'))->toBe('other');
    });

    it('lets a nested build for the same Node reuse the held lock', function (): void {
        $lock = new NodeCaddyBuildLock($this->lockDirectory);

        expect($lock->run(7, 'app-dev', static fn (): string => $lock->run(7, 'app-dev', static fn (): string => 'nested')))->toBe('nested')
            ->and(fileperms($this->lockDirectory) & 0o777)->toBe(0o700)
            ->and(fileperms($this->lockDirectory.'/node-7.lock') & 0o777)->toBe(0o600);
    });
});

describe('build error details', function (): void {
    it('bounds the detail of every stage to 2,000 bytes on a character boundary', function (string $stage): void {
        $exception = new NodeCaddyBuildException('app-dev', $stage, str_repeat('é', 1500));

        expect(strlen($exception->detail))->toBeLessThanOrEqual(NodeCaddyBuildException::MaxDetailBytes)
            ->and(mb_check_encoding($exception->detail, 'UTF-8'))->toBeTrue()
            ->and($exception->detail)->toEndWith('é…')
            ->and($exception->details()['message'])->toBe($exception->detail);
    })->with(['render', 'validate']);

    it('keeps a short detail unchanged', function (): void {
        expect(new NodeCaddyBuildException('app-dev', 'reload', 'Caddy did not reload.')->detail)->toBe('Caddy did not reload.');
    });
});

describe('the listen address check', function (): void {
    it('sends only the listen addresses to the read-only command', function (): void {
        $node = node_caddy_builder_node('app-dev', '10.44.0.3');

        node_caddy_builder($this)->checkListenAddresses($node);

        expect($this->agents->calls)->toHaveCount(1)
            ->and($this->agents->calls[0]['command'])->toBe('caddy.check-addresses')
            ->and($this->agents->calls[0]['input'])->toBe(['listen_addresses' => ['10.44.0.3']]);
    });

    it('names the missing address at stage addresses', function (): void {
        $node = node_caddy_builder_node('app-dev', '10.44.0.3');
        $this->agents->refuse('caddy.check-addresses', 'caddy.publication_failed', ['stage' => 'addresses', 'message' => 'The build binds 10.44.0.3, which is not an address on this Node. Correct the stored WireGuard or LAN address of the Node, then build again.']);

        expect(fn () => node_caddy_builder($this)->checkListenAddresses($node))
            ->toThrow(function (NodeCaddyBuildException $exception): void {
                expect($exception->stage)->toBe('addresses')
                    ->and($exception->detail)->toStartWith('The build binds 10.44.0.3, which is not an address on this Node.');
            });
    });

    it('refuses a render problem before it contacts the Node', function (): void {
        $node = node_caddy_builder_node('app-dev', '10.44.0.3');

        expect(fn () => node_caddy_builder($this, duplicate: true)->checkListenAddresses($node))
            ->toThrow(fn (NodeCaddyBuildException $exception) => expect($exception->stage)->toBe('render'));

        expect($this->agents->calls)->toBe([]);
    });
});

describe('the site diff', function (): void {
    it('compares two Caddyfiles site by site, ignoring comments and indentation', function (): void {
        $live = <<<'CADDY'
            {
                auto_https disable_certs
            }
            # hand edit
            reverb.orbit {
                bind 10.44.0.1
                reverse_proxy 127.0.0.1:8080
            }
            https://shop.test {
              bind 0.0.0.0
              respond ok
            }
            hand.example.com {
                respond hi
            }
            CADDY;
        $build = <<<'CADDY'
            # Managed by Orbit: Node Caddy build
            {
                auto_https disable_certs
            }

            # orbit: app-dev route-1-router
            https://shop.test {
                bind 0.0.0.0
                respond ok
            }

            # orbit: websocket reverb.orbit
            reverb.orbit {
                bind 0.0.0.0
                reverse_proxy 127.0.0.1:8080
            }

            # orbit: gateway gateway.orbit
            gateway.orbit, 10.44.0.1 {
                handle {
                    respond gateway
                }
            }
            CADDY;

        expect(CaddyfileSiteDiff::compare($live, $build))->toBe([
            ['address' => '{ }', 'status' => 'same', 'removed' => [], 'added' => []],
            ['address' => 'https://shop.test', 'status' => 'same', 'removed' => [], 'added' => []],
            ['address' => 'reverb.orbit', 'status' => 'changed', 'removed' => ['bind 10.44.0.1'], 'added' => ['bind 0.0.0.0']],
            ['address' => 'gateway.orbit, 10.44.0.1', 'status' => 'build only', 'removed' => [], 'added' => []],
            ['address' => 'hand.example.com', 'status' => 'live only', 'removed' => [], 'added' => []],
        ]);
    });
});

describe('the build command', function (): void {
    beforeEach(function (): void {
        $this->socket = new FakeRoutePublicationSocket;
        app()->instance(WebSocketClient::class, $this->socket);
        config(['swamp.run_token' => 'fixture-token', 'swamp.address' => '192.0.2.209', 'swamp.ca_path' => '/public/root.pem']);
    });

    it('submits the complete public render to the native Caddy workflow without agent actions', function (): void {
        $node = node_caddy_builder_node('app-dev', '10.44.0.3');
        $node->forceFill(['ssh_host_key_type' => 'ssh-ed25519', 'ssh_host_key' => 'fixture-host-key'])->save();
        $renderer = node_caddy_builder_renderer(contributions: [node_caddy_builder_contribution('collector.test')]);
        app()->instance(NodeCaddyfileRenderer::class, $renderer);

        $this->artisan('orbit:caddy-build', ['node' => 'app-dev'])
            ->expectsOutputToContain('Caddy publication submitted for Node [app-dev]. Operation [')
            ->assertSuccessful();

        $operation = Operation::query()->sole();
        expect($operation->kind)->toBe('route.caddy_converge')
            ->and($operation->status)->toBe(OperationStatus::Running)
            ->and($operation->state['swamp_run_id'])->toBe('a075eb23-7ac4-4487-80d1-57a038a92676')
            ->and($this->socket->sent)->toHaveCount(1)
            ->and($this->socket->sent[0]['payload']['workflowIdOrName'])->toBe('operation-route-caddy')
            ->and($this->socket->sent[0]['payload']['inputs']['intent']['caddy_publication']['caddy']['content'])
            ->toBe($renderer->render($node)->content)->toContain('https://shop.test {', 'https://collector.test {')
            ->and($this->agents->calls)->toBe([]);
    });

    it('refuses publication without stored SSH pins before recording or dispatching an Operation', function (): void {
        node_caddy_builder_node('app-dev', '10.44.0.3');

        $this->artisan('orbit:caddy-build', ['node' => 'app-dev'])
            ->expectsOutputToContain('Official SSH requires the stored target address, user and host key.')
            ->assertFailed();

        expect(Operation::query()->count())->toBe(0)
            ->and($this->socket->sent)->toBe([])->and($this->agents->calls)->toBe([]);
    });

    it('refuses bad contributed renders before recording or dispatching an Operation', function (string $host, string $address, bool $duplicate): void {
        node_caddy_builder_node('app-dev', $address);
        $contribution = node_caddy_builder_contribution($host);
        app()->instance(NodeCaddyfileRenderer::class, node_caddy_builder_renderer(contributions: $duplicate ? [$contribution, $contribution] : [$contribution]));

        $this->artisan('orbit:caddy-build', ['node' => 'app-dev'])
            ->expectsOutputToContain('The admitted Node Caddy configuration cannot be rendered.')
            ->assertFailed();

        expect(Operation::query()->count())->toBe(0)
            ->and($this->socket->sent)->toBe([])->and($this->agents->calls)->toBe([]);
    })->with([
        'collision with an existing route' => ['shop.test', '10.44.0.3', false],
        'duplicate across contributions' => ['collector.test', '10.44.0.3', true],
        'out of range listener address' => ['collector.test', '10.44.0.999', false],
        'injected listener address' => ['collector.test', "10.44.0.3\n0.0.0.0", false],
    ]);

    it('refuses --diff without --dry-run', function (): void {
        node_caddy_builder_node('app-dev', '10.44.0.3');

        $this->artisan('orbit:caddy-build', ['node' => 'app-dev', '--diff' => true])
            ->expectsOutputToContain('--diff only compares a render. Pass --dry-run --diff.')
            ->assertFailed();

        expect($this->agents->calls)->toBe([]);
    });

    it('prints the render of a Node without contacting it', function (): void {
        $node = node_caddy_builder_node('app-dev', '10.44.0.3');
        $node->roles()->create(['role' => RoleName::WebSocket, 'status' => LifecycleStatus::Active]);
        CaddySiteCertificateFixtures::recordAll($node);

        $this->artisan('orbit:caddy-build', ['node' => 'app-dev', '--dry-run' => true])
            ->expectsOutputToContain(NodeCaddyfileRenderer::Marker)
            ->assertSuccessful();
    });

    it('prints each refusal and fails when the render has a problem', function (): void {
        $node = node_caddy_builder_node('app-dev', null);
        $node->roles()->create(['role' => RoleName::Analytics, 'status' => LifecycleStatus::Active]);
        CaddySiteCertificateFixtures::recordAll($node);

        $this->artisan('orbit:caddy-build', ['node' => 'app-dev', '--dry-run' => true])
            ->expectsOutputToContain('Build refused: The analytics site source needs the WireGuard IPv4 address of Node [app-dev].')
            ->assertFailed();
    });

    it('compares the render with the live file read over SSH', function (): void {
        $node = node_caddy_builder_node('app-dev', '10.44.0.3');
        $node->roles()->create(['role' => RoleName::WebSocket, 'status' => LifecycleStatus::Active]);
        CaddySiteCertificateFixtures::recordAll($node);
        $this->agents->answer('caddy.read-live', ['content' => "{\n    auto_https disable_certs\n    order abort first\n    metrics {\n        per_host\n    }\n}\nold.test {\n    respond old\n}\n"]);
        app()->instance(NodeCaddyTransport::class, node_caddy_builder_transport($this));

        $this->artisan('orbit:caddy-build', ['node' => 'app-dev', '--dry-run' => true, '--diff' => true])
            ->expectsOutputToContain('same       { }')
            ->expectsOutputToContain('build only reverb.orbit')
            ->expectsOutputToContain('live only  old.test')
            ->assertSuccessful();

        expect($this->agents->commands())->toBe(['caddy.read-live']);
    });

});

function node_caddy_builder_node(string $name, ?string $wireguardIp): Node
{
    return Node::query()->create([
        'name' => $name,
        'status' => LifecycleStatus::Active,
        'platform' => 'linux', 'architecture' => 'x86_64',
        'public_ssh_host' => "{$name}.example.test",
        'wireguard_ip' => $wireguardIp,
        'user' => 'orbit',
    ]);
}

/** @param list<ContributedCaddySiteSource> $contributions */
function node_caddy_builder_renderer(bool $duplicate = false, array $contributions = []): NodeCaddyfileRenderer
{
    $source = new readonly class($duplicate) implements NodeCaddySiteSource
    {
        public function __construct(private bool $duplicate) {}

        public function order(): int
        {
            return 0;
        }

        public function sites(Node $node): array
        {
            $site = static fn (string $name): CaddySite => new CaddySite(
                source: 'app-dev',
                name: $name,
                listener: CaddyListenerRule::Wildcard,
                hosts: ['shop.test'],
                port: 443,
                body: "https://shop.test {\n    bind 0.0.0.0\n    respond {$node->name}\n}\n",
            );

            return $this->duplicate ? [$site('one'), $site('two')] : [$site('one')];
        }
    };

    return new NodeCaddyfileRenderer([$source], $contributions);
}

function node_caddy_builder_contribution(string $host, string $source = 'extension'): ContributedCaddySiteSource
{
    return new readonly class($host, $source) implements ContributedCaddySiteSource
    {
        public function __construct(private string $host, private string $source) {}

        public function order(): int
        {
            return 70;
        }

        public function certificateSlug(): ?string
        {
            return null;
        }

        public function sites(Node $node): array
        {
            return [new CaddySite(
                source: $this->source,
                name: $this->host,
                listener: CaddyListenerRule::Shared,
                hosts: [$this->host],
                port: 443,
                body: "https://{$this->host} {\n    bind __BIND__\n    respond collector\n}\n",
                bindPlaceholder: '__BIND__',
            )];
        }
    };
}

function node_caddy_builder_transport(object $test): NodeCaddyTransport
{
    return new NodeCaddyTransport($test->agents);
}

function node_caddy_builder(object $test, bool $duplicate = false): NodeCaddyBuilder
{
    return new NodeCaddyBuilder(
        node_caddy_builder_renderer($duplicate),
        new NodeCaddyBuildLock($test->lockDirectory),
        node_caddy_builder_transport($test),
    );
}
