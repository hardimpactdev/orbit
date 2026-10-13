<?php

declare(strict_types=1);

use App\E2E\DiscoveryGuestPreparer;
use App\E2E\HostCapacity;
use App\E2E\IncusHost;
use App\E2E\IncusNetworkLifecycle;
use App\E2E\IssueState;
use App\E2E\OrphanNetworkSweep;
use App\E2E\RoleImagePrograms;
use App\E2E\RoleImages;
use App\E2E\State\StatePaths;
use App\E2E\TopologyAcquirer;
use App\E2E\TopologyComposer;
use App\E2E\TopologyReleaser;
use App\E2E\TopologyVerifier;
use App\E2E\Value\AttemptId;
use App\E2E\Value\FeatureTopology;
use App\E2E\Value\OperationId;
use App\E2E\Value\RoleImage;
use App\E2E\Value\TopologyRecipe;
use App\E2E\Value\TopologyRequest;
use App\E2E\WorktreeSynchronizer;
use Illuminate\Container\Container;
use Illuminate\Process\Factory as ProcessFactory;
use Illuminate\Support\Facades\Facade;
use Tests\Support\FakeIncus;

/** A bootstrapped checkout on the issue's branch, with the real guest scripts. */
function acquirerWorktree(): string
{
    $root = temporaryPath('orbit-acquire-', 5);
    foreach (['apps/gateway/vendor', 'apps/cli/vendor', 'packages/php-sdk/vendor', 'apps/e2e/resources/guest'] as $directory) {
        mkdir($root.'/'.$directory, 0755, true);
    }
    foreach (['apps/gateway/vendor/autoload.php', 'apps/cli/vendor/autoload.php', 'packages/php-sdk/vendor/autoload.php'] as $file) {
        file_put_contents($root.'/'.$file, "<?php\n");
    }
    file_put_contents($root.'/apps/gateway/.env', "APP_KEY=base64:fixture\n");
    file_put_contents($root.'/.gitignore', "/.e2e/\n/apps/gateway/.env\nvendor/\n");
    foreach (WorktreeSynchronizer::GUEST_SCRIPTS as $script) {
        copy(dirname(__DIR__, 3).'/resources/guest/'.$script, $root.'/apps/e2e/resources/guest/'.$script);
        chmod($root.'/apps/e2e/resources/guest/'.$script, 0755);
    }
    exec('git -C '.escapeshellarg($root).' init -q -b task-tst-9 && git -C '.escapeshellarg($root).' add . && git -C '.escapeshellarg($root).' -c user.name=Test -c user.email=test@example.test commit -q -m fixture', result_code: $status);
    expect($status)->toBe(0);

    return $root;
}

/**
 * Answers every guest command of a healthy composition; `$override` answers first.
 *
 * @param  list<string>  $steps  the composition and readiness scripts in the order they ran
 */
function acquirerResponder(FakeIncus $incus, array &$steps, ?Closure $override = null): Closure
{
    return function (string $instance, array $argv, ?string $stdin) use ($incus, &$steps, $override): ?array {
        $script = str_starts_with($argv[0], '/usr/local/bin/') ? substr($argv[0], 15) : null;
        if ($script !== null && $script !== 'verify-topology.sh') {
            $steps[] = $script.' '.implode(' ', array_slice($argv, 1, 1)).' @'.substr($instance, strrpos($instance, '-aaaaaaaa-') + 10);
        }
        $answer = $override === null ? null : $override($instance, $argv, $stdin);
        if ($answer !== null) {
            return $answer;
        }
        if ($argv[0] === 'sh' && str_contains($argv[2] ?? '', 'ip -4 -o addr show')) {
            $address = $incus->instances[$instance]['devices']['eth0']['ipv4.address'];

            return ["2: eth0    inet {$address}/24 scope global eth0\n", '', 0];
        }
        if ($argv[0] === 'cat' || $argv[0] === 'sha256sum') {
            return ['', 'missing', 1];
        }
        if ($argv[0] === 'ssh-keygen') {
            return ["ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAIFixtureGatewayKeyForTopologyTests0000000000 orbit@gateway\n", '', 0];
        }
        if ($argv[0] === 'uname') {
            return ["x86_64\n", '', 0];
        }
        if ($script === 'verify-topology.sh') {
            return [json_encode([
                'probe' => $argv[1], 'passed' => true, 'identity' => $argv[2], 'checked_at' => '2026-10-12T12:00:00Z',
                'expected' => 'ready', 'observed' => 'ready', 'evidence_ref' => "incus://{$argv[3]}/{$argv[1]}",
            ], JSON_THROW_ON_ERROR)."\n", '', 0];
        }

        return null;
    };
}

/** @return array{acquirer:TopologyAcquirer, releaser:TopologyReleaser, incus:FakeIncus, worktree:string, request:TopologyRequest, images:array<string,string>} */
function acquirerFixture(?Closure $override = null, array &$steps = [], bool $images = true): array
{
    $incus = new FakeIncus()->install();
    $incus->respond(acquirerResponder($incus, $steps, $override));
    $fingerprints = [];
    if ($images) {
        foreach (RoleImage::cases() as $image) {
            $fingerprints[$image->value] = $incus->addImage($image->alias(), $image->instanceType());
        }
    }
    $worktree = acquirerWorktree();
    $paths = StatePaths::forPrimary($worktree);
    $operation = new OperationId(str_repeat('c', 32));
    $host = new IncusHost(pool: 'orbit-e2e');
    $networks = new IncusNetworkLifecycle($host);
    $guests = new DiscoveryGuestPreparer($host);

    return [
        'acquirer' => new TopologyAcquirer(
            host: $host,
            networks: $networks,
            images: new RoleImages($host, new RoleImagePrograms($worktree)),
            composer: new TopologyComposer($host, $guests),
            synchronizer: new WorktreeSynchronizer($host, $worktree, $operation),
            verifier: new TopologyVerifier($host, 1, 0),
            guests: $guests,
            capacity: new HostCapacity($host, 24),
            hostPaths: $paths,
            operation: $operation,
            repositoryRoot: $worktree,
            attempts: static fn (): AttemptId => attemptId(),
        ),
        'releaser' => new TopologyReleaser($host, $networks, $paths, $operation, new OrphanNetworkSweep($host, $networks, $paths, $operation)),
        'incus' => $incus,
        'worktree' => $worktree,
        'request' => new TopologyRequest('TST-9', $worktree),
        'images' => $fingerprints,
    ];
}

describe('TopologyAcquirer', function () {
    beforeEach(function () {
        $container = new Container;
        $container->instance(ProcessFactory::class, new ProcessFactory);
        Facade::clearResolvedInstances();
        Facade::setFacadeApplication($container);
    });

    it('composes the declared Nodes from their role images and records the topology', function () {
        $steps = [];
        ['acquirer' => $acquirer, 'incus' => $incus, 'worktree' => $worktree, 'request' => $request, 'images' => $images] = acquirerFixture(steps: $steps);
        $timings = [];

        $topology = $acquirer->acquire($request, TopologyRecipe::compose(['app-dev']), function (string $step, float $seconds) use (&$timings): void {
            $timings[] = $step;
        });

        $guests = [];
        foreach ($incus->instances as $name => $guest) {
            $guests[substr($name, strlen('orbit-e2e-tst-9-aaaaaaaa-'))] = [$guest['type'], $guest['config']['volatile.base_image'], isset($guest['devices']['orbit-source'])];
        }
        expect($guests)->toBe([
            'gateway' => ['virtual-machine', $images['gateway'], true],
            'app-dev' => ['virtual-machine', $images['app-dev'], true],
            'operator' => ['container', $images['operator'], true],
        ])
            ->and($incus->instances['orbit-e2e-tst-9-aaaaaaaa-operator']['devices']['orbit-source'])
            ->toMatchArray(['source' => realpath($worktree), 'path' => '/home/orbit/orbit', 'shift' => 'true'])
            ->and($steps)->toBe([
                'converge-gateway.sh prerequisites @gateway',
                'converge-gateway.sh bootstrap @gateway',
                'prepare-node.sh gateway-authorize @app-dev',
                'prepare-node.sh gateway-authorize @operator',
                'converge-app-dev.sh app-dev @gateway',
                'converge-operator.sh provision @gateway',
                'converge-operator.sh grant @gateway',
                'converge-operator.sh configure-cli @operator',
            ])
            ->and($timings)->toBe(['reserve', 'start', 'scripts', 'bootstrap.gateway', 'enroll.app-dev', 'enroll.operator', 'compose', 'readiness'])
            ->and($topology->toArray()['nodes'])->toBe(['gateway', 'app-dev', 'operator'])
            ->and($topology->construction->images)->toBe(['gateway' => $images['gateway'], 'app-dev' => $images['app-dev'], 'operator' => $images['operator']])
            ->and(IssueState::forWorktree('TST-9', $worktree)->requireTopology()->toArray())->toBe($topology->toArray())
            ->and(array_column($incus->firewall, 'operation'))->toBe(['ensure']);
    });

    it('enrolls every declared production Node with its own WireGuard address', function () {
        $steps = [];
        ['acquirer' => $acquirer, 'incus' => $incus, 'request' => $request] = acquirerFixture(steps: $steps);

        $acquirer->acquire($request, TopologyRecipe::compose(TopologyRecipe::WORKLOADS));
        $enrollments = array_values(array_filter($incus->execs, static fn (array $exec): bool => str_contains($exec['argv'][0], 'converge-app-')));

        expect(array_map(static fn (array $exec): array => [basename($exec['argv'][0]), $exec['argv'][1], $exec['argv'][4] ?? null], $enrollments))
            ->toBe([
                ['converge-app-dev.sh', 'app-dev', null],
                ['converge-app-prod.sh', 'app-prod', '10.44.0.3'],
                ['converge-app-prod.sh', 'app-prod-2', '10.44.0.4'],
            ])
            ->and(array_keys($incus->instances))->toHaveCount(5);
    });

    it('adds each declared extra role from the operator after every Node is enrolled', function () {
        $steps = [];
        ['acquirer' => $acquirer, 'request' => $request] = acquirerFixture(steps: $steps);
        $timings = [];

        $acquirer->acquire($request, TopologyRecipe::fromOption('app-dev,app-prod+websocket+ingress'), function (string $step) use (&$timings): void {
            $timings[] = $step;
        });

        expect(array_slice($steps, -4))->toBe([
            'converge-operator.sh grant @gateway',
            'converge-operator.sh configure-cli @operator',
            'converge-operator.sh role-add @operator',
            'converge-operator.sh role-add @operator',
        ])
            ->and($timings)->toContain('roles.app-prod')
            ->and($timings)->not->toContain('roles.app-dev');
    });

    it('fails before creating anything when a role image is missing and names the build command', function () {
        ['acquirer' => $acquirer, 'incus' => $incus, 'worktree' => $worktree, 'request' => $request] = acquirerFixture(images: false);

        expect(fn () => $acquirer->acquire($request, TopologyRecipe::compose(['app-dev'])))
            ->toThrow(RuntimeException::class, 'Step reserve failed: The role image [orbit-e2e-gateway] is missing; run bin/e2e-topology-images build --role=gateway.')
            ->and($incus->instances)->toBe([])
            ->and($incus->networks)->toBe([])
            ->and(IssueState::forWorktree('TST-9', $worktree)->hasAttempt())->toBeFalse();
    });

    it('rolls back every resource and names the step and Orbit error when enrollment fails', function () {
        ['acquirer' => $acquirer, 'incus' => $incus, 'worktree' => $worktree, 'request' => $request] = acquirerFixture(
            fn (string $instance, array $argv): ?array => $argv[0] === '/usr/local/bin/converge-app-dev.sh'
                ? ["Node provisioning failed at step [wireguard-peer-install] with error [vpn.peer_config_failed].\n", "wg-quick failed\n", 1]
                : null,
        );

        expect(fn () => $acquirer->acquire($request, TopologyRecipe::compose(['app-dev'])))
            ->toThrow(RuntimeException::class, 'Step enroll.app-dev failed: guest script converge-app-dev.sh exited 1 on orbit-e2e-tst-9-aaaaaaaa-gateway at Orbit step wireguard-peer-install (vpn.peer_config_failed).')
            ->and($incus->instances)->toBe([])
            ->and($incus->networks)->toBe([])
            ->and(array_column($incus->firewall, 'operation'))->toBe(['ensure', 'remove'])
            ->and(IssueState::forWorktree('TST-9', $worktree)->hasAttempt())->toBeFalse();
    });

    it('rolls back when readiness never passes and lists the failed probes', function () {
        ['acquirer' => $acquirer, 'incus' => $incus, 'request' => $request] = acquirerFixture(
            fn (string $instance, array $argv): ?array => ($argv[1] ?? null) === 'nodes.registered' ? ['', "registered Nodes differ\n", 1] : null,
        );

        expect(fn () => $acquirer->acquire($request, TopologyRecipe::compose([])))
            ->toThrow(RuntimeException::class, 'Step readiness failed: Topology readiness verification failed. Failed probes: nodes.registered')
            ->and($incus->instances)->toBe([]);
    });

    it('refuses a second acquisition while the issue holds a topology', function () {
        ['acquirer' => $acquirer, 'request' => $request] = acquirerFixture();
        $acquirer->acquire($request, TopologyRecipe::compose([]));

        expect(fn () => $acquirer->acquire($request, TopologyRecipe::compose([])))
            ->toThrow(RuntimeException::class, 'TST-9 already has discovery attempt '.str_repeat('a', 32).'; release it first.');
    });

    it('refuses a worktree that bin/bootstrap has not prepared', function () {
        ['acquirer' => $acquirer, 'incus' => $incus, 'worktree' => $worktree, 'request' => $request] = acquirerFixture();
        unlink($worktree.'/apps/gateway/.env');

        expect(fn () => $acquirer->acquire($request, TopologyRecipe::compose([])))
            ->toThrow(RuntimeException::class, 'The worktree is missing apps/gateway/.env; run bin/bootstrap in the worktree before discovery.')
            ->and($incus->instances)->toBe([]);
    });

    it('runs one command on an exact Node and refuses an undeclared Node', function () {
        ['acquirer' => $acquirer, 'incus' => $incus, 'request' => $request] = acquirerFixture(
            fn (string $instance, array $argv): ?array => in_array('node:list', $argv, true) ? ["{\"nodes\":[]}\n", '', 0] : null,
        );
        $acquirer->acquire($request, TopologyRecipe::compose([]));

        $result = $acquirer->execute($request, 'operator', ['orbit', 'node:list', '--json']);

        expect($result->stdout)->toBe("{\"nodes\":[]}\n")
            ->and(array_slice(end($incus->execs)['argv'], -3))->toBe(['orbit', 'node:list', '--json'])
            ->and(fn () => $acquirer->execute($request, 'app-dev', ['true']))
            ->toThrow(InvalidArgumentException::class, 'The topology has no Node [app-dev]; it has gateway, operator.');
    });

    it('syncs, verifies, and refuses a mount that changed since the last readiness', function () {
        ['acquirer' => $acquirer, 'worktree' => $worktree, 'request' => $request] = acquirerFixture();
        $first = $acquirer->acquire($request, TopologyRecipe::compose([]));

        $verified = $acquirer->verify($request);
        file_put_contents($worktree.'/notes.txt', "untracked\n");

        expect($verified->source->toArray())->toBe($first->source->toArray())
            ->and(fn () => $acquirer->verify($request))
            ->toThrow(RuntimeException::class, 'differs from the last successful readiness record; run topology sync');

        $synced = $acquirer->sync($request);
        expect($synced->source->dirty)->toBeTrue()
            ->and($acquirer->verify($request)->source->toArray())->toBe($synced->source->toArray());
    });

    it('releases every guest and the network, then forgets the lease', function () {
        ['acquirer' => $acquirer, 'releaser' => $releaser, 'incus' => $incus, 'worktree' => $worktree, 'request' => $request] = acquirerFixture();
        $acquirer->acquire($request, TopologyRecipe::compose(['app-dev']));

        $result = $releaser->release($request);

        expect($result['released'])->toBe([
            'stopped:orbit-e2e-tst-9-aaaaaaaa-operator',
            'stopped:orbit-e2e-tst-9-aaaaaaaa-app-dev',
            'stopped:orbit-e2e-tst-9-aaaaaaaa-gateway',
            'deleted:orbit-e2e-tst-9-aaaaaaaa-operator',
            'deleted:orbit-e2e-tst-9-aaaaaaaa-app-dev',
            'deleted:orbit-e2e-tst-9-aaaaaaaa-gateway',
            'deleted:'.featureTarget('TST-9', 'a', TopologyRecipe::compose(['app-dev']))->network(),
        ])
            ->and($incus->instances)->toBe([])
            ->and($incus->networks)->toBe([])
            ->and(IssueState::forWorktree('TST-9', $worktree)->hasAttempt())->toBeFalse()
            ->and(fn () => $releaser->release($request))->toThrow(RuntimeException::class, 'TST-9 has no active attempt.');
    });

    it('releases the exact guests of a lease that has no topology record', function () {
        ['releaser' => $releaser, 'incus' => $incus, 'worktree' => $worktree, 'request' => $request] = acquirerFixture();
        $recipe = TopologyRecipe::compose(['app-dev']);
        IssueState::forWorktree('TST-9', $worktree)->writeAttempt(attemptId(), new OperationId(str_repeat('c', 32)), $recipe);
        $target = featureTarget('TST-9', 'a', $recipe);
        $incus->instances[$target->instance('app-dev')] = [
            'type' => 'virtual-machine',
            'status' => 'Stopped',
            'config' => ['user.orbit.e2e.owner' => 'orbit-e2e', 'user.orbit.e2e.issue' => 'TST-9', 'user.orbit.e2e.attempt' => str_repeat('a', 32)],
            'devices' => ['root' => ['type' => 'disk', 'pool' => 'orbit-e2e', 'path' => '/']],
        ];
        $incus->instances['orbit-e2e-tst-9-bbbbbbbb-app-dev'] = $incus->instances[$target->instance('app-dev')];

        $result = $releaser->release($request);

        expect($result['released'])->toBe(['deleted:'.$target->instance('app-dev')])
            ->and($result['already_absent'])->toBe([$target->instance('operator'), $target->instance('gateway'), $target->network()])
            ->and(array_keys($incus->instances))->toBe(['orbit-e2e-tst-9-bbbbbbbb-app-dev']);
    });

    it('refuses to release a guest that another attempt owns', function () {
        ['releaser' => $releaser, 'incus' => $incus, 'worktree' => $worktree, 'request' => $request] = acquirerFixture();
        $recipe = TopologyRecipe::compose([]);
        IssueState::forWorktree('TST-9', $worktree)->writeAttempt(attemptId(), new OperationId(str_repeat('c', 32)), $recipe);
        $incus->instances[featureTarget('TST-9', 'a', $recipe)->instance('gateway')] = [
            'type' => 'virtual-machine',
            'status' => 'Running',
            'config' => ['user.orbit.e2e.owner' => 'orbit-e2e', 'user.orbit.e2e.issue' => 'TST-9', 'user.orbit.e2e.attempt' => str_repeat('b', 32)],
            'devices' => ['root' => ['type' => 'disk', 'pool' => 'orbit-e2e', 'path' => '/']],
        ];

        expect(fn () => $releaser->release($request))
            ->toThrow(RuntimeException::class, 'ownership does not match the issue attempt')
            ->and($incus->instances)->toHaveCount(1)
            ->and(IssueState::forWorktree('TST-9', $worktree)->hasAttempt())->toBeTrue();
    });

    it('keeps the feature topology schema the Gateway reads for its acquisition state', function () {
        ['acquirer' => $acquirer, 'request' => $request] = acquirerFixture();
        $topology = $acquirer->acquire($request, TopologyRecipe::compose([]));

        expect($topology->toArray()['purpose'])->toBe('discovery')
            ->and(FeatureTopology::SCHEMA)->toBe(5);
    });
});
