<?php

declare(strict_types=1);

use App\Domain\Doctor\DoctorInspectionException;
use App\Domain\Nodes\RoleName;
use App\Domain\Shared\LifecycleStatus;
use App\Infrastructure\AgentCommands\AgentRunnerFailure;
use App\Infrastructure\Caddy\Build\NodeCaddyBuildLock;
use App\Infrastructure\Caddy\Build\NodeCaddyfileRenderer;
use App\Infrastructure\Caddy\Build\NodeCaddyLiveReader;
use App\Infrastructure\Caddy\Build\NodeCaddyTransport;
use App\Infrastructure\Doctor\NativeCaddyBuildInspector;
use App\Infrastructure\Processes\CommandResult;
use App\Models\Node;
use Illuminate\Filesystem\Filesystem;
use Tests\Support\CaddySiteCertificateFixtures;
use Tests\Support\FakeAgentCommandRunner;

beforeEach(function (): void {
    $this->agents = new FakeAgentCommandRunner()->answer('caddy.read-live', ['content' => '']);
    $this->lockDirectory = sys_get_temp_dir().'/orbit-caddy-build-inspector-'.bin2hex(random_bytes(6));
});

afterEach(function (): void {
    new Filesystem()->deleteDirectory($this->lockDirectory);
});

it('matches a live Caddyfile that is byte for byte the fresh build', function (): void {
    $node = caddy_build_inspector_websocket_node();
    $render = app(NodeCaddyfileRenderer::class)->render($node);
    $this->live = $render->content;
    $this->agents->answer('caddy.read-live', ['content' => $this->live]);

    $observation = caddy_build_inspector($this)->inspect($node);

    expect($observation?->matches)->toBeTrue()
        ->and($observation?->expectedVersion)->toBe($render->version)
        ->and($observation?->liveVersion)->toBe($render->version)
        ->and($observation?->sources)->toBe(['websocket'])
        ->and($this->agents->commands())->toBe(['caddy.read-live']);
});

it('reports a hand edit of a built Caddyfile with the digest of the live file', function (): void {
    $node = caddy_build_inspector_websocket_node();
    $render = app(NodeCaddyfileRenderer::class)->render($node);
    $this->live = $render->content."\nhand.example.test {\n    respond hi\n}\n";
    $this->agents->answer('caddy.read-live', ['content' => $this->live]);

    $observation = caddy_build_inspector($this)->inspect($node);

    expect($observation?->matches)->toBeFalse()
        ->and($observation?->expectedVersion)->toBe($render->version)
        ->and($observation?->liveVersion)->toBe(NodeCaddyfileRenderer::version($this->live));
});

it('reports a live Caddyfile that no build wrote as not built', function (string $live): void {
    $node = caddy_build_inspector_websocket_node();
    $this->live = $live;
    $this->agents->answer('caddy.read-live', ['content' => $this->live]);

    $observation = caddy_build_inspector($this)->inspect($node);

    expect($observation?->matches)->toBeFalse()
        ->and($observation?->liveVersion)->toBeNull();
})->with([
    'a foreign file' => ["hand.example.test {\n    respond hi\n}\n"],
    'no file' => [''],
]);

it('compares a Node whose Caddy role renders no site yet', function (): void {
    $node = caddy_build_inspector_node('app-dev');
    $node->roles()->create(['role' => RoleName::AppDev, 'status' => LifecycleStatus::Active]);
    $render = app(NodeCaddyfileRenderer::class)->render($node);
    $this->live = $render->content;
    $this->agents->answer('caddy.read-live', ['content' => $this->live]);

    $observation = caddy_build_inspector($this)->inspect($node);

    expect($observation?->matches)->toBeTrue()
        ->and($observation?->sources)->toBe([]);
});

it('skips a Node that serves no Caddy site and holds no Caddy role, without contacting it', function (Closure $prepare): void {
    $node = $prepare(caddy_build_inspector_node('vpn'));

    expect(caddy_build_inspector($this)->inspect($node))->toBeNull()
        ->and($this->agents->calls)->toBe([]);
})->with([
    'a Node without Caddy roles' => [static function (Node $node): Node {
        $node->roles()->create(['role' => RoleName::Vpn, 'status' => LifecycleStatus::Active]);

        return $node;
    }],
    'a Node whose Caddy role is being removed' => [static function (Node $node): Node {
        $node->roles()->create(['role' => RoleName::AppDev, 'status' => LifecycleStatus::Removing]);

        return $node;
    }],
    'a Node that is not Linux' => [static function (Node $node): Node {
        $node->update(['platform' => 'darwin']);
        $node->roles()->create(['role' => RoleName::AppDev, 'status' => LifecycleStatus::Active]);

        return $node;
    }],
]);

it('fails closed when the live Caddyfile cannot be read', function (CommandResult $result): void {
    $node = caddy_build_inspector_websocket_node();
    $this->agents->answer('caddy.read-live', static fn (): array => throw new AgentRunnerFailure('caddy.read-live', 'transport', $result->stderr));

    expect(fn () => caddy_build_inspector($this)->inspect($node))->toThrow(DoctorInspectionException::class);
})->with([
    'a failed read' => [new CommandResult(1, '', 'sudo: a password is required', 1, false)],
    'a truncated read' => [new CommandResult(0, 'partial', '', 1, true)],
]);

it('reports a Node as not compared when a build keeps its lock past the short wait, and reads with a short timeout', function (): void {
    $node = caddy_build_inspector_websocket_node();
    $this->live = app(NodeCaddyfileRenderer::class)->render($node)->content."# hand edit\n";
    $this->agents->answer('caddy.read-live', ['content' => $this->live]);
    mkdir($this->lockDirectory, 0o700, true);
    $held = fopen("{$this->lockDirectory}/node-{$node->id}.lock", 'c+');
    flock($held, LOCK_EX);
    $now = 0.0;
    $waited = 0;
    $lock = new NodeCaddyBuildLock($this->lockDirectory, clock: function () use (&$now): float {
        return $now;
    }, wait: function (int $microseconds) use (&$now, &$waited): void {
        $now += $microseconds / 1_000_000;
        $waited += $microseconds;
    });

    try {
        $observation = caddy_build_inspector($this, $lock)->inspect($node);

        expect($observation?->building)->toBeTrue()
            ->and($observation?->matches)->toBeFalse()
            ->and($waited)->toBeGreaterThanOrEqual((int) (NativeCaddyBuildInspector::LockWaitSeconds * 1_000_000))->toBeLessThan((int) (NativeCaddyBuildInspector::LockWaitSeconds * 1_000_000) + 100_000)
            ->and($this->agents->calls)->toBe([]);
    } finally {
        flock($held, LOCK_UN);
        fclose($held);
    }

    $observation = caddy_build_inspector($this, $lock)->inspect($node);

    expect($observation?->building)->toBeFalse()
        ->and($observation?->matches)->toBeFalse()
        ->and($this->agents->commands())->toBe(['caddy.read-live']);
});

function caddy_build_inspector(object $test, ?NodeCaddyBuildLock $lock = null): NativeCaddyBuildInspector
{
    return new NativeCaddyBuildInspector(
        app(NodeCaddyfileRenderer::class),
        new NodeCaddyLiveReader(new NodeCaddyTransport($test->agents)),
        $lock ?? new NodeCaddyBuildLock($test->lockDirectory),
    );
}

function caddy_build_inspector_node(string $name): Node
{
    return Node::query()->create([
        'name' => $name,
        'status' => LifecycleStatus::Active,
        'platform' => 'linux',
        'public_ssh_host' => "{$name}.example.test",
        'wireguard_ip' => '10.44.0.7',
        'user' => 'orbit',
    ]);
}

function caddy_build_inspector_websocket_node(): Node
{
    $node = caddy_build_inspector_node('services');
    $node->roles()->create(['role' => RoleName::WebSocket, 'status' => LifecycleStatus::Active]);
    CaddySiteCertificateFixtures::recordAll($node);

    return $node;
}
