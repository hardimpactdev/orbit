<?php

declare(strict_types=1);

use App\Actions\GatewayReleases\PrepareGatewayReleaseAction;
use App\Domain\GatewayReleases\GatewayReleaseAgent;
use App\Domain\GatewayReleases\GatewayReleaseCommit;
use App\Domain\GatewayReleases\GatewayReleaseException;
use App\Domain\GatewayReleases\GatewayReleaseInventory;
use App\Domain\GatewayReleases\GatewayReleaseLayout;
use App\Domain\GatewayReleases\GatewayReleaseObserver;
use App\Domain\GatewayReleases\GatewayReleaseWebBuild;
use App\Domain\GitHub\RepositoryReadAccess;
use App\Domain\Nodes\NodeProvisioningException;
use App\Domain\Nodes\RoleName;
use App\Domain\Shared\LifecycleStatus;
use App\Infrastructure\GatewayReleases\GatewayReleaseBuilder;
use App\Infrastructure\GatewayReleases\GatewayReleaseCommands;
use App\Infrastructure\GatewayReleases\GatewayReleaseLock;
use App\Infrastructure\Processes\ProcessRunner;
use App\Infrastructure\Ssh\SshExecutor;
use App\Models\Node;
use Illuminate\Support\Facades\Http;
use Tests\Feature\GitHub\GitHubTestSupport;
use Tests\Support\FakeAgentCommandRunner;

beforeEach(function (): void {
    $node = Node::query()->create(['name' => 'gateway', 'public_ssh_host' => '192.0.2.2', 'status' => LifecycleStatus::Active]);
    $node->roles()->create(['role' => RoleName::Gateway, 'status' => LifecycleStatus::Active]);
    $this->sha = str_repeat('a', 40);
    $this->agents = new FakeAgentCommandRunner;
    $this->commands = new GatewayReleaseCommands($this->agents);
    $this->steps = [];
    // The layout holds a prepared release only when a test says so.
    $this->held = null;
    $this->layout = new GatewayReleaseLayout('/home/orbit/orbit/apps/gateway', new readonly class($this) implements GatewayReleaseObserver
    {
        public function __construct(private object $test) {}

        public function inspect(GatewayReleaseLayout $layout, ?string $runningApplication = null): GatewayReleaseInventory
        {
            $commits = $this->test->held === null ? [] : [GatewayReleaseCommit::id($this->test->held) => $this->test->held];

            return new GatewayReleaseInventory(null, 'unreadable', false, $commits, [], array_keys($commits));
        }
    });
    // The commit's published release; a test that names none makes the release agent refuse.
    $this->published = $this->sha;
    $this->agent = new class($this) implements GatewayReleaseAgent
    {
        public function __construct(private object $test) {}

        public function fetch(string $revision, Node $gateway): array
        {
            $revision = GatewayReleaseCommit::parse($revision);
            $this->test->steps[] = 'fetch:'.$gateway->name;
            if ($this->test->published === null || ! str_starts_with($this->test->published, $revision)) {
                throw new GatewayReleaseException('agent-delivery', 'gateway.release_agent_delivery_unavailable', 'No published release yet.', 503);
            }

            return ['sha' => $this->test->published, 'manifest' => '{}'];
        }

        public function install(array $delivery, GatewayReleaseLayout $layout, Node $gateway): void
        {
            $this->test->steps[] = 'install';
        }
    };
    $this->web = Mockery::mock(GatewayReleaseWebBuild::class);
    $this->builder = new GatewayReleaseBuilder(
        layout: $this->layout,
        commands: $this->commands,
        readAccess: app(RepositoryReadAccess::class),
        web: $this->web,
        agent: $this->agent,
        checkoutAccess: function (string $application): void {
            $this->steps[] = 'access:'.$application;
        },
        stepLock: '/home/orbit/.orbit/gateway-release-step.lock',
        minimumFreeBytes: 3072,
        reservedBytes: static fn (): int => 512,
    );
    $this->agents->answer('gateway.inspect-release-preparation', ['sha' => $this->sha, 'origin' => null])
        ->answer('gateway.prepare-release', function (): array {
            $this->steps[] = 'prepare';

            return ['sha' => $this->sha, 'reused' => false];
        })
        ->answer('gateway.finish-release', function (): array {
            $this->steps[] = 'finish';

            return ['changed' => true];
        });
    Http::preventStrayRequests();
});

it('orders the release agent, native preparation, web, access, and completion with the configured reservation', function (): void {
    GitHubTestSupport::storeApp();
    $this->web->shouldReceive('install')->once()->with('aaaaaaaaaaaa', $this->sha)->andReturnUsing(function (): bool {
        $this->steps[] = 'web';

        return true;
    });

    $release = $this->builder->prepare($this->sha);

    expect($release->sha)->toBe($this->sha)->and($release->id)->toBe('aaaaaaaaaaaa')->and($release->reused)->toBeFalse()
        ->and($release->path)->toBe('/home/orbit/releases/aaaaaaaaaaaa')->and($release->durationMs)->toBeGreaterThanOrEqual(0);
    expect($this->steps)->toBe(['fetch:gateway', 'prepare', 'install', 'web', 'access:/home/orbit/releases/aaaaaaaaaaaa/apps/gateway', 'finish']);
    expect($this->agents->calls)->toBe([
        ['node' => 'gateway', 'command' => 'gateway.inspect-release-preparation', 'input' => ['checkout' => $this->layout->applicationPath, 'revision' => $this->sha, 'minimum_free_bytes' => 3072, 'reserved_bytes' => 512]],
        ['node' => 'gateway', 'command' => 'gateway.prepare-release', 'input' => ['checkout' => $this->layout->applicationPath, 'revision' => $this->sha, 'minimum_free_bytes' => 3072, 'reserved_bytes' => 512, 'git_read' => null, 'step_lock' => '/home/orbit/.orbit/gateway-release-step.lock']],
        ['node' => 'gateway', 'command' => 'gateway.finish-release', 'input' => ['checkout' => $this->layout->applicationPath, 'sha' => $this->sha, 'step_lock' => '/home/orbit/.orbit/gateway-release-step.lock']],
    ]);
    Http::assertNothingSent();
});

it('acquires repository access only after native inspection asks for an origin', function (): void {
    GitHubTestSupport::storeApp();
    Http::fake([
        'https://api.github.com/repos/acme/shop/installation' => Http::response(['id' => 9]),
        'https://api.github.com/app/installations/9/access_tokens' => Http::response(['token' => 'ghs_release_sentinel'], 201),
    ]);
    $this->agents->answer('gateway.inspect-release-preparation', function (): array {
        Http::assertNothingSent();

        return ['sha' => null, 'origin' => 'git@github.com:acme/shop.git'];
    });

    $this->builder->prepare(substr($this->sha, 0, 9), withWeb: false);

    expect($this->agents->calls[1]['input']['git_read']->githubToken())->toBe('ghs_release_sentinel');
    Http::assertSentCount(2);
});

it('stops before repository access and mutation when native inspection refuses', function (string $code): void {
    GitHubTestSupport::storeApp();
    $this->agents->refuse('gateway.inspect-release-preparation', $code);

    expect(release_failure(fn () => $this->builder->prepare($this->sha))->errorCode)->toBe($code);
    expect($this->agents->commands())->toBe(['gateway.inspect-release-preparation']);
    expect($this->steps)->toBe(['fetch:gateway']);
    Http::assertNothingSent();
})->with(['gateway.release_disk_low', 'gateway.release_layout_missing', 'gateway.release_path_conflict']);

it('installs the agent of a release it already holds before any agent command, and restores its web build', function (): void {
    $this->held = $this->sha;
    $this->web->shouldReceive('install')->once()->with('aaaaaaaaaaaa', $this->sha)->andReturnUsing(function (): bool {
        $this->steps[] = 'web';

        return false;
    });

    expect($this->builder->prepare($this->sha, withWeb: false)->reused)->toBeTrue();
    expect($this->agents->calls)->toBe([]);
    expect($this->steps)->toBe(['fetch:gateway', 'install', 'web']);
});

it('refuses before any agent command or change while the commit has no published release', function (): void {
    $this->published = null;

    $error = release_failure(fn () => $this->builder->prepare($this->sha));

    expect($error->errorCode)->toBe('gateway.release_agent_delivery_unavailable')->and($error->step)->toBe('agent-delivery');
    expect($this->agents->calls)->toBe([]);
    expect($this->steps)->toBe(['fetch:gateway']);
});

it('skips web installation for a new adoption release', function (): void {
    expect($this->builder->prepare($this->sha, withWeb: false)->reused)->toBeFalse();
    expect($this->steps)->toBe(['fetch:gateway', 'prepare', 'install', 'access:/home/orbit/releases/aaaaaaaaaaaa/apps/gateway', 'finish']);
});

it('keeps the resolved commit and native failure phase and stops dependent steps', function (string $command, string $code, string $phase, int $status): void {
    $this->agents->refuse($command, $code, ['sha' => $this->sha]);

    $error = release_failure(fn () => $this->builder->prepare($this->sha, withWeb: false));

    expect($error->errorCode)->toBe($code)->and($error->step)->toBe($phase)->and($error->status)->toBe($status)->and($error->sha)->toBe($this->sha);
    expect(end($this->agents->calls)['command'])->toBe($command);
})->with([
    ['gateway.prepare-release', 'gateway.release_dependencies_failed', 'dependencies', 500],
    ['gateway.prepare-release', 'gateway.release_commit_unknown', 'fetch', 422],
    ['gateway.prepare-release', 'gateway.release_in_progress', 'lock', 409],
    ['gateway.finish-release', 'gateway.release_configuration_failed', 'configuration', 500],
]);

it('does not finish or grant access after web failure and attaches the resolved commit', function (): void {
    $this->web->shouldReceive('install')->once()->andThrow(new GatewayReleaseException('web', 'gateway.release_web_build_missing', 'Missing artifact.'));

    $error = release_failure(fn () => $this->builder->prepare($this->sha));

    expect($error->errorCode)->toBe('gateway.release_web_build_missing')->and($error->sha)->toBe($this->sha);
    expect($this->steps)->toBe(['fetch:gateway', 'prepare', 'install']);
    expect($this->agents->commands())->toBe(['gateway.inspect-release-preparation', 'gateway.prepare-release']);
});

it('refuses invalid revision syntax without sending a command', function (string $revision): void {
    $error = release_failure(fn () => $this->builder->prepare($revision));

    expect($error->errorCode)->toBe('gateway.release_commit_invalid')->and($error->status)->toBe(422);
    expect($this->agents->calls)->toBe([])->and($this->steps)->toBe([]);
})->with(['main', 'HEAD~1', 'abc12', '--upload-pack=x', 'abcdef1 ']);

it('refreshes through the agent and removes web only after successful native removal', function (): void {
    $this->agents->answer('gateway.refresh-release-configuration', ['changed' => true])
        ->refuse('gateway.remove-release', 'gateway.release_current');
    $this->builder->refreshConfiguration('aaaaaaaaaaaa');
    expect(release_failure(fn () => $this->builder->remove('aaaaaaaaaaaa'))->errorCode)->toBe('gateway.release_current');
    $this->agents->answer('gateway.remove-release', ['changed' => true]);
    $this->web->shouldReceive('remove')->once()->with('aaaaaaaaaaaa')->andReturnUsing(function (): void {
        expect(end($this->agents->calls)['command'])->toBe('gateway.remove-release');
    });

    $this->builder->remove('aaaaaaaaaaaa');

    expect($this->agents->calls[0]['input'])->toBe(['checkout' => $this->layout->applicationPath, 'release' => 'aaaaaaaaaaaa', 'step_lock' => '/home/orbit/.orbit/gateway-release-step.lock']);
});

it('prints the native prepared release as one JSON object', function (): void {
    $directory = sys_get_temp_dir().'/release-intent-'.bin2hex(random_bytes(6));
    $lock = new GatewayReleaseLock($directory.'/gateway-release.lock');
    $this->web->shouldReceive('install')->once()->andReturn(true);
    app()->instance(PrepareGatewayReleaseAction::class, new PrepareGatewayReleaseAction($lock, $this->builder));

    try {
        $this->artisan('gateway:release:prepare', ['commit' => $this->sha])->expectsOutputToContain('"release":"aaaaaaaaaaaa"')->assertExitCode(0);
        $this->artisan('gateway:release:prepare', ['commit' => 'main'])->expectsOutputToContain('"error_code":"gateway.release_commit_invalid"')->assertExitCode(2);
    } finally {
        unlink($directory.'/gateway-release.lock');
        unlink($directory.'/gateway-release-step.lock');
        rmdir($directory);
    }
});

arch('release preparation has no local or SSH execution path')->expect(GatewayReleaseBuilder::class)
    ->not->toUse([ProcessRunner::class, SshExecutor::class, 'file_get_contents', 'file_put_contents', 'symlink', 'chmod', 'unlink', 'disk_free_space']);

it('stops before completion when Caddy access fails and keeps the resolved commit', function (): void {
    $builder = new GatewayReleaseBuilder(
        $this->layout, $this->commands, app(RepositoryReadAccess::class), $this->web, $this->agent,
        static fn () => throw new NodeProvisioningException('checkout', 'gateway.checkout_access_failed', 'Access refused.'),
        '/home/orbit/.orbit/gateway-release-step.lock',
    );

    $error = release_failure(fn () => $builder->prepare($this->sha, withWeb: false));

    expect($error->errorCode)->toBe('gateway.release_access_failed')->and($error->step)->toBe('access')->and($error->sha)->toBe($this->sha);
    expect($this->agents->commands())->toBe(['gateway.inspect-release-preparation', 'gateway.prepare-release']);
});
