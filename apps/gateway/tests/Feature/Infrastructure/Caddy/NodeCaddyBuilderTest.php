<?php

declare(strict_types=1);

use App\Domain\Nodes\RoleName;
use App\Domain\Shared\LifecycleStatus;
use App\Infrastructure\AgentCommands\AgentRunnerFailure;
use App\Infrastructure\Caddy\Build\CaddyfileSiteDiff;
use App\Infrastructure\Caddy\Build\CaddyListenerRule;
use App\Infrastructure\Caddy\Build\CaddySite;
use App\Infrastructure\Caddy\Build\ContributedCaddySiteSource;
use App\Infrastructure\Caddy\Build\NodeCaddyBuilder;
use App\Infrastructure\Caddy\Build\NodeCaddyBuildException;
use App\Infrastructure\Caddy\Build\NodeCaddyBuildLock;
use App\Infrastructure\Caddy\Build\NodeCaddyBuildResult;
use App\Infrastructure\Caddy\Build\NodeCaddyBuilds;
use App\Infrastructure\Caddy\Build\NodeCaddyfile;
use App\Infrastructure\Caddy\Build\NodeCaddyfileRenderer;
use App\Infrastructure\Caddy\Build\NodeCaddySiteSource;
use App\Infrastructure\Caddy\Build\NodeCaddyTransport;
use App\Models\Node;
use Illuminate\Filesystem\Filesystem;
use Tests\Support\CaddySiteCertificateFixtures;
use Tests\Support\FakeAgentCommandRunner;

beforeEach(function (): void {
    $this->lockDirectory = sys_get_temp_dir().'/orbit-caddy-build-lock-'.bin2hex(random_bytes(6));
    $this->agents = new FakeAgentCommandRunner()->answer('caddy.publish', ['result' => 'published'])->answer('caddy.check-addresses', []);
});

afterEach(function (): void {
    new Filesystem()->deleteDirectory($this->lockDirectory);
});

describe('building a Node', function (): void {
    it('sends the render and Node identity to the agent runner', function (bool $gateway): void {
        $node = node_caddy_builder_node($gateway ? 'gateway' : 'app-dev', '10.44.0.3');
        if ($gateway) {
            $node->roles()->create(['role' => RoleName::Gateway, 'status' => LifecycleStatus::Active]);
            CaddySiteCertificateFixtures::recordAll($node);
        }

        expect(node_caddy_builder($this)->build($node))->toBe(NodeCaddyBuildResult::Published)
            ->and($this->agents->calls)->toHaveCount(1)
            ->and($this->agents->calls[0]['node'])->toBe($node->name)
            ->and($this->agents->calls[0]['command'])->toBe('caddy.publish')
            ->and($this->agents->calls[0]['input']['content'])->toBe(node_caddy_builder_render($node)->content);
    })->with([false, true]);

    it('tells the agent whether a Caddy role on the Node expects Caddy', function (array $roles, string $expected): void {
        $node = node_caddy_builder_node('app-prod', '10.44.0.4');
        foreach ($roles as [$role, $status, $failedStep]) {
            $node->roles()->create(['role' => $role, 'status' => $status, 'failed_step' => $failedStep]);
        }
        $this->agents->answer('caddy.publish', ['result' => 'unchanged']);

        node_caddy_builder($this)->build($node);

        expect($this->agents->calls[0]['input']['unserved'])->toBe($expected === '1');
    })->with([
        'two Caddy roles failed before Caddy was installed' => [[
            [RoleName::AppProd, LifecycleStatus::Failed, 'converge:role-prerequisites'],
            [RoleName::WebSocket, LifecycleStatus::Removing, null],
        ], '1'],
        'an active Caddy role' => [[
            [RoleName::AppProd, LifecycleStatus::Active, null],
            [RoleName::WebSocket, LifecycleStatus::Failed, 'converge:role-prerequisites'],
        ], '0'],
        'a converging Caddy role' => [[
            [RoleName::AppProd, LifecycleStatus::Provisioning, null],
        ], '0'],
        'only an active role without Caddy' => [[
            [RoleName::Metrics, LifecycleStatus::Active, null],
        ], '1'],
    ]);

    it('reads stored state again once it holds the Node lock', function (): void {
        $node = node_caddy_builder_node('app-dev', '10.44.0.3');
        $stale = $node->replicate();
        $stale->id = $node->id;
        $node->update(['name' => 'renamed']);

        node_caddy_builder($this)->build($stale);

        expect($this->agents->calls[0]['input']['content'])->toBe(node_caddy_builder_render($node->fresh() ?? $node)->content);
    });

    it('never contacts the Node when the render has a problem', function (): void {
        $node = node_caddy_builder_node('app-dev', '10.44.0.3');

        expect(fn () => node_caddy_builder($this, duplicate: true)->build($node))
            ->toThrow(function (NodeCaddyBuildException $exception): void {
                expect($exception->details())->toBe([
                    'node' => 'app-dev',
                    'stage' => 'render',
                    'message' => 'The app-dev site one and the app-dev site two both serve shop.test:443 on 10.44.0.3.',
                ]);
            });
        expect($this->agents->calls)->toBe([]);
    });

    it('publishes a valid contribution alongside the existing route', function (): void {
        $node = node_caddy_builder_node('app-prod', '10.44.0.3');
        $renderer = node_caddy_builder_renderer(contributions: [node_caddy_builder_contribution('collector.test')]);
        $builder = new NodeCaddyBuilder($renderer, new NodeCaddyBuildLock($this->lockDirectory), node_caddy_builder_transport($this));

        expect($builder->build($node))->toBe(NodeCaddyBuildResult::Published);
        $content = node_caddy_builder_pushed($this->agents->calls[0]);
        expect($content)->toContain('https://shop.test {', 'https://collector.test {', '# orbit: extension collector.test', 'bind 10.44.0.3');
    });

    it('refuses bad contributions before contacting the Node and can still publish the existing route', function (string $host, string $source, string $address, bool $duplicate): void {
        $node = node_caddy_builder_node('app-prod', $address);
        $contribution = node_caddy_builder_contribution($host, $source);
        $renderer = node_caddy_builder_renderer(contributions: $duplicate ? [$contribution, node_caddy_builder_contribution($host)] : [$contribution]);
        $builder = new NodeCaddyBuilder($renderer, new NodeCaddyBuildLock($this->lockDirectory), node_caddy_builder_transport($this));

        expect(fn () => $builder->build($node))->toThrow(NodeCaddyBuildException::class)
            ->and($this->agents->calls)->toBe([]);

        // No candidate was sent: the live configuration could not have changed. A later valid build still works.
        $node->update(['wireguard_ip' => '10.44.0.3']);
        expect(node_caddy_builder($this)->build($node))->toBe(NodeCaddyBuildResult::Published);
        expect(node_caddy_builder_pushed($this->agents->calls[0]))->toContain('https://shop.test {')->not->toContain('# orbit: extension');
    })->with([
        'collision with an existing route' => ['shop.test', 'extension', '10.44.0.3', false],
        'duplicate across contributions' => ['collector.test', 'extension', '10.44.0.3', true],
        'out of range listener address' => ['collector.test', 'extension', '10.44.0.999', false],
        'injected listener address' => ['collector.test', 'extension', "10.44.0.3\n0.0.0.0", false],
    ]);

    it('reports the failed stage and the last Caddy message', function (): void {
        $node = node_caddy_builder_node('app-dev', '10.44.0.3');
        $this->agents->refuse('caddy.publish', 'caddy.publication_failed', [
            'stage' => 'validate',
            'message' => 'Error: loading certificate: open /etc/caddy/orbit-certificates/route-1/current/cert.pem: no such file',
        ]);

        expect(fn () => node_caddy_builder($this)->build($node))
            ->toThrow(function (NodeCaddyBuildException $exception): void {
                expect($exception->stage)->toBe('validate')
                    ->and($exception->detail)->toBe('Error: loading certificate: open /etc/caddy/orbit-certificates/route-1/current/cert.pem: no such file')
                    ->and($exception->getMessage())->toStartWith('The Caddy build for Node [app-dev] failed at stage [validate]');
            });
    });

    it('maps an agent transport failure to the connect stage', function (): void {
        $node = node_caddy_builder_node('app-dev', '10.44.0.3');
        $this->agents->answer('caddy.publish', static fn (): array => throw new AgentRunnerFailure('caddy.publish', 'transport', 'Connection refused'));

        expect(fn () => node_caddy_builder($this)->build($node))
            ->toThrow(fn (NodeCaddyBuildException $exception) => expect($exception->stage)->toBe('connect'));
    });
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

describe('two publishers on one Node', function (): void {
    it('keep both sites when the second commits while the first build holds the Node', function (): void {
        $node = node_caddy_builder_node('app-dev', '10.44.0.3');
        $node->roles()->create(['role' => RoleName::WebSocket, 'status' => LifecycleStatus::Provisioning]);
        CaddySiteCertificateFixtures::recordAll($node);
        mkdir($this->lockDirectory, 0o700, true);
        // The first publisher's build holds the Node and pushes the state it read: only its own site.
        $first = fopen($this->lockDirectory."/node-{$node->id}.lock", 'c+');
        flock($first, LOCK_EX);
        $firstPush = app(NodeCaddyfileRenderer::class)->render($node)->content;
        // The second publisher commits its state, then requests a build that waits for the first.
        $node->roles()->create(['role' => RoleName::Analytics, 'status' => LifecycleStatus::Provisioning]);
        CaddySiteCertificateFixtures::recordAll($node);
        $waits = 0;
        $lock = new NodeCaddyBuildLock(
            directory: $this->lockDirectory,
            wait: function () use (&$waits, $first): void {
                if (++$waits === 1) {
                    flock($first, LOCK_UN);
                }
            },
        );

        try {
            new NodeCaddyBuilder(app(NodeCaddyfileRenderer::class), $lock, node_caddy_builder_transport($this))->build($node);
        } finally {
            fclose($first);
        }

        $secondPush = node_caddy_builder_pushed($this->agents->calls[0]);

        expect($waits)->toBeGreaterThanOrEqual(1)
            ->and($firstPush)->toContain('# orbit: websocket reverb.orbit')->not->toContain('analytics.orbit')
            ->and($secondPush)->toContain('# orbit: websocket reverb.orbit', '# orbit: analytics analytics.orbit');
    });

    it('push the same Caddyfile whichever publisher builds last', function (): void {
        $orders = [[RoleName::WebSocket, RoleName::Analytics], [RoleName::Analytics, RoleName::WebSocket]];
        $finals = [];

        foreach ($orders as $index => $order) {
            $node = node_caddy_builder_node("app-dev-{$index}", '10.44.0.3');
            $node->update(['name' => 'app-dev']);
            $this->agents->calls = [];

            foreach ($order as $role) {
                $node->roles()->create(['role' => $role, 'status' => LifecycleStatus::Provisioning]);
                CaddySiteCertificateFixtures::recordAll($node);
                new NodeCaddyBuilder(app(NodeCaddyfileRenderer::class), new NodeCaddyBuildLock($this->lockDirectory), node_caddy_builder_transport($this))->build($node);
            }

            $finals[] = node_caddy_builder_pushed($this->agents->calls[1]);
            $node->roles()->delete();
            $node->delete();
        }

        expect($finals[0])->toBe($finals[1])
            ->toContain('# orbit: websocket reverb.orbit', '# orbit: analytics analytics.orbit');
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

    it('bounds a long Caddy message from the agent', function (): void {
        $node = node_caddy_builder_node('app-dev', '10.44.0.3');
        $this->agents->refuse('caddy.publish', 'caddy.publication_failed', ['stage' => 'validate', 'message' => 'Error: '.str_repeat('ü', 3000)]);

        expect(fn () => node_caddy_builder($this)->build($node))
            ->toThrow(function (NodeCaddyBuildException $exception): void {
                expect($exception->stage)->toBe('validate')
                    ->and(strlen($exception->detail))->toBeLessThanOrEqual(2000)
                    ->and(mb_check_encoding($exception->detail, 'UTF-8'))->toBeTrue();
            });
    });

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
    it('builds and pushes the Node without --dry-run', function (): void {
        node_caddy_builder_node('app-dev', '10.44.0.3');
        app()->instance(NodeCaddyBuilds::class, node_caddy_builder($this));

        $this->artisan('orbit:caddy-build', ['node' => 'app-dev'])
            ->expectsOutputToContain('Published a new Caddyfile on Node [app-dev] and reloaded Caddy.')
            ->assertSuccessful();

        expect($this->agents->calls)->toHaveCount(1)
            ->and(node_caddy_builder_pushed($this->agents->calls[0]))->toStartWith(NodeCaddyfileRenderer::Marker);
    });

    it('reports a build that changed nothing', function (): void {
        node_caddy_builder_node('app-dev', '10.44.0.3');
        $this->agents->answer('caddy.publish', ['result' => 'unchanged']);
        app()->instance(NodeCaddyBuilds::class, node_caddy_builder($this));

        $this->artisan('orbit:caddy-build', ['node' => 'app-dev'])
            ->expectsOutputToContain('The Caddyfile on Node [app-dev] is current. Nothing changed.')
            ->assertSuccessful();
    });

    it('fails and names the stage and Caddy message when the build fails', function (): void {
        node_caddy_builder_node('app-dev', '10.44.0.3');
        $this->agents->refuse('caddy.publish', 'caddy.publication_failed', ['stage' => 'validate', 'message' => 'Error: adapting config']);
        app()->instance(NodeCaddyBuilds::class, node_caddy_builder($this));

        $this->artisan('orbit:caddy-build', ['node' => 'app-dev'])
            ->expectsOutputToContain('The Caddy build for Node [app-dev] failed at stage [validate]: Error: adapting config')
            ->assertFailed();
    });

    it('refuses --diff without --dry-run', function (): void {
        node_caddy_builder_node('app-dev', '10.44.0.3');
        app()->instance(NodeCaddyBuilds::class, node_caddy_builder($this));

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
        'platform' => 'linux',
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

/** @param array{input: array<string, mixed>} $call */
function node_caddy_builder_pushed(array $call): string
{
    return $call['input']['content'];
}

function node_caddy_builder_render(Node $node): NodeCaddyfile
{
    return node_caddy_builder_renderer()->render($node);
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
