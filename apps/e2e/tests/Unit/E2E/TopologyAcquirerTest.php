<?php

declare(strict_types=1);

use App\E2E\HostCapacity;
use App\E2E\IncusHost;
use App\E2E\IncusNetworkLifecycle;
use App\E2E\IssueState;
use App\E2E\OrphanNetworkSweep;
use App\E2E\RouterLanFirewallFixture;
use App\E2E\State\StatePaths;
use App\E2E\TopologyAcquirer;
use App\E2E\TopologyComposer;
use App\E2E\TopologyGuestPreparer;
use App\E2E\TopologyNodePrograms;
use App\E2E\TopologyReleaser;
use App\E2E\TopologyVerifier;
use App\E2E\Value\AttemptId;
use App\E2E\Value\OperationId;
use App\E2E\Value\SourceState;
use App\E2E\Value\TopologyConstructionInputs;
use App\E2E\Value\TopologyRecipe;
use App\E2E\Value\TopologyRecord;
use App\E2E\Value\TopologyRequest;
use App\E2E\Value\TopologyTarget;
use App\E2E\Value\VerificationReport;
use App\E2E\WorktreeSynchronizer;
use Illuminate\Container\Container;
use Illuminate\Process\Factory as ProcessFactory;
use Illuminate\Support\Facades\Facade;
use Symfony\Component\Process\Process;
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
    file_put_contents($root.'/apps/gateway/artisan', <<<'PHP'
<?php
$programs = [['name' => 'bootstrap', 'arguments' => ['true', 'bootstrap'], 'input' => null], ['name' => 'caddy-package', 'arguments' => ['true', 'caddy-package'], 'input' => null], ['name' => 'vpn-prerequisites', 'arguments' => ['true', 'vpn-prerequisites'], 'input' => null]];
echo json_encode(['agent' => ['path' => dirname(__DIR__, 2).'/apps/agent/orbit-agent', 'sha256' => str_repeat('a', 64)], 'programs' => array_map(static fn (array $p): array => $p + ['fingerprint' => hash('sha256', json_encode(['arguments' => $p['arguments'], 'input' => $p['input']], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE))], $programs)]);
PHP);
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
        if ($argv[0] === 'sh' && str_contains($argv[2] ?? '', 'boot_id')) {
            return ["boot-ready\n", '', 0];
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

/** @return array{acquirer:TopologyAcquirer, releaser:TopologyReleaser, incus:FakeIncus, worktree:string, request:TopologyRequest} */
function acquirerFixture(?Closure $override = null, array &$steps = [], bool $template = true): array
{
    $incus = new FakeIncus()->install();
    $incus->respond(acquirerResponder($incus, $steps, $override));
    if (! $template) {
        unset($incus->instances[IncusHost::TEMPLATE]);
    }
    $worktree = acquirerWorktree();
    $paths = StatePaths::forPrimary($worktree);
    $operation = new OperationId(str_repeat('c', 32));
    $host = new IncusHost;
    $networks = new IncusNetworkLifecycle($host);
    $guests = new TopologyGuestPreparer($host, settleIntervalMicroseconds: 0);

    return [
        'acquirer' => new TopologyAcquirer(
            host: $host,
            networks: $networks,
            composer: new TopologyComposer($host, $guests, new TopologyNodePrograms($worktree)),
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
    ];
}

describe('TopologyAcquirer', function () {
    it('rechecks fresh Incus ownership and lease before every fixture guest operation', function (string $change) {
        ['acquirer' => $acquirer, 'incus' => $incus, 'worktree' => $worktree] = acquirerFixture();
        new Process(['git', 'branch', '-m', 'task-1791'], $worktree)->mustRun();
        $request = new TopologyRequest(RouterLanFirewallFixture::ISSUE, $worktree);
        $recipe = TopologyRecipe::compose(['app-dev', 'app-prod']);
        $target = TopologyTarget::for($request->issue, new AttemptId(RouterLanFirewallFixture::ATTEMPT), $recipe);
        $topology = new TopologyRecord(TopologyConstructionInputs::create($target, 1), new SourceState(str_repeat('a', 40), str_repeat('a', 40)), new VerificationReport(true, ['nodes.registered' => verificationProbeFixture()]));
        $state = IssueState::forWorktree($request->issue, $worktree);
        $state->writeAttempt($topology->attempt, new OperationId(str_repeat('c', 32)), $recipe);
        $state->writeTopology($topology);
        $state->writeMountToken();
        foreach ($topology->instances as $instance) {
            $incus->instances[$instance] = ['type' => 'virtual-machine', 'status' => 'Running', 'config' => [
                'user.orbit.e2e.owner' => 'orbit-e2e', 'user.orbit.e2e.issue' => $request->issue,
                'user.orbit.e2e.attempt' => $topology->attempt->value,
            ], 'devices' => ['root' => ['type' => 'disk', 'pool' => IncusHost::POOL, 'path' => '/'], 'eth0' => ['type' => 'nic', 'network' => $topology->network]]];
        }
        $incus->respond(function (string $instance, array $argv) use ($incus, $state, $recipe, $change): array {
            if ($change === 'owner') {
                $incus->instances[$instance]['config']['user.orbit.e2e.attempt'] = str_repeat('b', 32);
            } elseif ($change === 'lease') {
                $state->writeAttempt(new AttemptId(RouterLanFirewallFixture::ATTEMPT), new OperationId(str_repeat('b', 32)), $recipe);
            } else {
                $state->writeMountToken();
            }

            return [json_encode(['nodes' => [
                ['id' => 2, 'name' => 'app-dev', 'status' => 'active', 'roles' => ['router'], 'cluster_id' => 1, 'lan_ip' => '10.232.1.11'],
                ['id' => 3, 'name' => 'app-prod', 'status' => 'active', 'roles' => [], 'cluster_id' => 1, 'lan_ip' => '10.232.1.12'],
                ['id' => 4, 'name' => 'operator', 'status' => 'active', 'roles' => [], 'cluster_id' => null],
            ]], JSON_THROW_ON_ERROR), '', 0];
        });

        expect(fn () => $acquirer->firewallFixture($request, 'prepare', static function (): void {}))
            ->toThrow(RuntimeException::class, $change === 'owner' ? 'identity does not match' : 'lease or mount identity changed');
        expect($incus->execs)->toHaveCount(1)
            ->and($incus->execs[0]['argv'])->toContain('runuser', 'node:list');
    })->with(['owner', 'lease', 'mount']);

    beforeEach(function () {
        $container = new Container;
        $container->instance(ProcessFactory::class, new ProcessFactory);
        Facade::clearResolvedInstances();
        Facade::setFacadeApplication($container);
    });

    it('composes the declared Nodes from template copies and records the topology', function () {
        $steps = [];
        ['acquirer' => $acquirer, 'incus' => $incus, 'worktree' => $worktree, 'request' => $request] = acquirerFixture(steps: $steps);
        $template = $incus->instances[IncusHost::TEMPLATE];
        $timings = [];

        $topology = $acquirer->acquire($request, TopologyRecipe::compose(['app-dev']), function (string $step, float $seconds) use (&$timings): void {
            $timings[] = $step;
        });

        $dns = array_values(array_filter($incus->execs, static fn (array $exec): bool => $exec['argv'][0] === 'bash' && str_contains($exec['argv'][2] ?? '', 'orbit-public-upstream.conf')));
        expect($dns)->toHaveCount(3);
        expect(array_search($dns[0], $incus->execs, true))->toBeLessThan(array_find_key($incus->execs, static fn (array $exec): bool => str_contains($exec['argv'][2] ?? '', 'boot_id')));
        expect($dns[0]['argv'])->toBe(['bash', '-ceu', file_get_contents(dirname(__DIR__, 3).'/resources/guest/public-dns.sh')]);
        $programs = array_values(array_filter($incus->execs, static fn (array $exec): bool => in_array('true', $exec['argv'], true)));
        expect(array_map(static fn (array $exec): array => array_slice($exec['argv'], 10), $programs))->toBe([['true', 'caddy-package'], ['true', 'vpn-prerequisites']]);
        $agent = array_find_key($incus->execs, static fn (array $exec): bool => $exec['argv'][0] === 'install' && end($exec['argv']) === '/usr/local/bin/orbit-agent');
        expect($agent)->toBeInt()->toBeLessThan(array_search($programs[0], $incus->execs, true))
            ->and($incus->execs[$agent]['argv'])->toBe(['install', '-D', '-m', '0755', '-o', 'root', '-g', 'root', '--', '/home/orbit/orbit/apps/agent/orbit-agent', '/usr/local/bin/orbit-agent']);

        $guests = [];
        foreach ($incus->guests() as $name => $guest) {
            $guests[substr($name, strlen('orbit-e2e-tst-9-aaaaaaaa-'))] = [$guest['type'], $guest['config']['volatile.base_image'], $guest['devices']['root']['pool'], isset($guest['devices']['orbit-source'])];
        }
        expect($guests)->toBe([
            'gateway' => ['virtual-machine', $template['config']['volatile.base_image'], IncusHost::POOL, true],
            'app-dev' => ['virtual-machine', $template['config']['volatile.base_image'], IncusHost::POOL, true],
            'operator' => ['virtual-machine', $template['config']['volatile.base_image'], IncusHost::POOL, true],
        ])
            ->and(array_map(static fn (array $call): string => $call[1], $incus->callsOf('copy')))
            ->toBe(array_fill(0, 3, 'local:'.IncusHost::TEMPLATE))
            ->and($incus->instances[IncusHost::TEMPLATE])->toBe($template)
            ->and($incus->instances['orbit-e2e-tst-9-aaaaaaaa-operator']['devices']['orbit-source'])
            ->toMatchArray(['source' => realpath($worktree), 'path' => '/home/orbit/orbit'])
            ->and($steps)->toBe([
                'prepare-node.sh configure-runtime @gateway',
                'prepare-node.sh configure-runtime @app-dev',
                'prepare-node.sh configure-runtime @operator',
                'converge-gateway.sh prerequisites @gateway',
                'converge-gateway.sh bootstrap @gateway',
                'prepare-node.sh gateway-authorize @app-dev',
                'prepare-node.sh gateway-authorize @operator',
                'converge-node.sh provision @gateway',
                'converge-node.sh provision @gateway',
                'converge-operator.sh grant @gateway',
                'converge-operator.sh configure-cli @operator',
            ])
            ->and($timings)->toBe(['reserve', 'start', 'scripts', 'bootstrap.gateway', 'enroll.app-dev', 'enroll.operator', 'compose', 'readiness'])
            ->and($topology->toArray()['nodes'])->toBe(['gateway', 'app-dev', 'operator'])
            ->and(array_keys($topology->construction->nodes))->toBe(['gateway', 'app-dev', 'operator'])
            ->and(IssueState::forWorktree('TST-9', $worktree)->requireTopology()->toArray())->toBe($topology->toArray())
            ->and(array_column($incus->firewall, 'operation'))->toBe(['ensure']);
    });

    it('releases a composed topology without touching the template', function (): void {
        extract(acquirerFixture());
        $template = $incus->instances[IncusHost::TEMPLATE];

        $acquirer->acquire($request, TopologyRecipe::compose(['app-dev']));
        expect(array_column($incus->guests(), 'type'))->toBe(['virtual-machine', 'virtual-machine', 'virtual-machine'])
            ->and($incus->callsOf('init'))->toBe([])
            ->and($incus->callsOf('publish'))->toBe([]);
        $acquirer->verify($request);
        $releaser->release($request);

        expect($incus->guests())->toBe([])
            ->and($incus->instances)->toBe([IncusHost::TEMPLATE => $template])
            ->and(array_filter($incus->calls, static fn (array $call): bool => in_array('local:'.IncusHost::TEMPLATE, $call, true) && $call[0] !== 'copy'))->toBe([]);
    });

    it('fails composition and cleans up when a Gateway VPN program fails', function (): void {
        extract(acquirerFixture(static fn (string $instance, array $argv): ?array => in_array('vpn-prerequisites', $argv, true) ? ['', 'failed', 1] : null));

        expect(fn () => $acquirer->acquire($request, TopologyRecipe::compose(['app-dev'])))
            ->toThrow(RuntimeException::class, 'Gateway convergence program [vpn-prerequisites] failed.');
        expect($incus->guests())->toBe([]);
    });

    it('reports recipe pnpm repair failure before enrollment and cleans its guests', function (): void {
        extract(acquirerFixture(static fn (string $instance, array $argv, ?string $stdin): ?array => in_array('recipe.install-javascript', $argv, true) ? [json_encode(['ok' => false, 'error' => ['code' => 'recipe.operation_failed', 'message' => 'pinned artifact digest mismatch', 'details' => []]], JSON_THROW_ON_ERROR), '', 0] : null));

        expect(fn () => $acquirer->acquire($request, TopologyRecipe::compose(['app-dev'])))
            ->toThrow(RuntimeException::class, 'Recipe pnpm preparation failed');
        expect($incus->guests())->toBe([]);
    });

    it('enrolls every declared Node with its catalog role, TLD, and WireGuard address', function () {
        $steps = [];
        ['acquirer' => $acquirer, 'incus' => $incus, 'request' => $request] = acquirerFixture(steps: $steps);

        $acquirer->acquire($request, TopologyRecipe::fromOption('app-dev,app-prod,app-prod-2'));
        $enrollments = array_values(array_filter($incus->execs, static fn (array $exec): bool => str_contains($exec['argv'][0], 'converge-node.sh')));

        expect(array_map(static fn (array $exec): array => [$exec['argv'][2], ...array_slice($exec['argv'], 5)], $enrollments))
            ->toBe([
                ['app-dev', '10.44.0.2', 'app-dev', 'beast'],
                ['app-prod', '10.44.0.3', 'app-prod', 'app-prod'],
                ['app-prod-2', '10.44.0.4', 'app-prod', 'app-prod-2'],
                ['operator', '10.44.0.5'],
            ])
            ->and($incus->guests())->toHaveCount(5);
    });

    it('adds each declared extra role from the operator after every Node is enrolled', function () {
        $steps = [];
        ['acquirer' => $acquirer, 'incus' => $incus, 'request' => $request] = acquirerFixture(steps: $steps);
        $timings = [];

        $acquirer->acquire($request, TopologyRecipe::fromOption('app-dev,app-prod+ingress,app-prod-2+ingress'), function (string $step) use (&$timings): void {
            $timings[] = $step;
        });

        expect(array_slice($steps, -4))->toBe([
            'converge-operator.sh grant @gateway',
            'converge-operator.sh configure-cli @operator',
            'converge-operator.sh role-add @operator',
            'converge-operator.sh role-add @operator',
        ])
            ->and($timings)->toContain('roles.app-prod')->toContain('roles.app-prod-2')
            ->and($timings)->not->toContain('roles.app-dev')
            ->and(array_values(array_map(
                static fn (array $exec): array => array_slice($exec['argv'], 1),
                array_filter($incus->execs, static fn (array $exec): bool => ($exec['argv'][1] ?? null) === 'role-add'),
            )))->toBe([
                ['role-add', 'app-prod', 'ingress', 'e2e-app-prod'],
                ['role-add', 'app-prod-2', 'ingress', 'e2e-app-prod-2'],
            ]);
    });

    it('rolls back the network and names the copy failure when the host has no template', function () {
        ['acquirer' => $acquirer, 'incus' => $incus, 'worktree' => $worktree, 'request' => $request] = acquirerFixture(template: false);

        expect(fn () => $acquirer->acquire($request, TopologyRecipe::compose(['app-dev'])))
            ->toThrow(RuntimeException::class, 'Step reserve failed: Incus guest copy batch failed for gateway: Error: Instance not found; app-dev: Error: Instance not found; operator: Error: Instance not found.')
            ->and($incus->instances)->toBe([])
            ->and($incus->networks)->toBe([])
            ->and(IssueState::forWorktree('TST-9', $worktree)->hasAttempt())->toBeFalse();
    });

    it('rolls back every resource and names the step and Orbit error when enrollment fails', function () {
        ['acquirer' => $acquirer, 'incus' => $incus, 'worktree' => $worktree, 'request' => $request] = acquirerFixture(
            fn (string $instance, array $argv): ?array => $argv[0] === '/usr/local/bin/converge-node.sh' && $argv[2] === 'app-dev'
                ? ["Node provisioning failed at step [wireguard-peer-install] with error [vpn.peer_config_failed].\n", "wg-quick failed\n", 1]
                : null,
        );

        expect(fn () => $acquirer->acquire($request, TopologyRecipe::compose(['app-dev'])))
            ->toThrow(RuntimeException::class, 'Step enroll.app-dev failed: guest script converge-node.sh exited 1 on orbit-e2e-tst-9-aaaaaaaa-gateway at Orbit step wireguard-peer-install (vpn.peer_config_failed).')
            ->and($incus->guests())->toBe([])
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
            ->and($incus->guests())->toBe([]);
    });

    it('refuses a second acquisition while the issue holds a topology', function () {
        ['acquirer' => $acquirer, 'request' => $request] = acquirerFixture();
        $acquirer->acquire($request, TopologyRecipe::compose([]));

        expect(fn () => $acquirer->acquire($request, TopologyRecipe::compose([])))
            ->toThrow(RuntimeException::class, 'TST-9 already has topology attempt '.str_repeat('a', 32).'; release it first, or acquire with --replace.');
    });

    it('refuses a worktree that bin/bootstrap has not prepared', function () {
        ['acquirer' => $acquirer, 'incus' => $incus, 'worktree' => $worktree, 'request' => $request] = acquirerFixture();
        unlink($worktree.'/apps/gateway/.env');

        expect(fn () => $acquirer->acquire($request, TopologyRecipe::compose([])))
            ->toThrow(RuntimeException::class, 'The worktree is missing apps/gateway/.env; run bin/bootstrap in the worktree before acquire.')
            ->and($incus->guests())->toBe([]);
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
            ->and($incus->guests())->toBe([])
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
            'devices' => ['root' => ['type' => 'disk', 'pool' => IncusHost::POOL, 'path' => '/']],
        ];
        $incus->instances['orbit-e2e-tst-9-bbbbbbbb-app-dev'] = $incus->instances[$target->instance('app-dev')];

        $result = $releaser->release($request);

        expect($result['released'])->toBe(['deleted:'.$target->instance('app-dev')])
            ->and($result['already_absent'])->toBe([$target->instance('operator'), $target->instance('gateway'), $target->network()])
            ->and(array_keys($incus->guests()))->toBe(['orbit-e2e-tst-9-bbbbbbbb-app-dev']);
    });

    it('refuses to release a guest that another attempt owns', function () {
        ['releaser' => $releaser, 'incus' => $incus, 'worktree' => $worktree, 'request' => $request] = acquirerFixture();
        $recipe = TopologyRecipe::compose([]);
        IssueState::forWorktree('TST-9', $worktree)->writeAttempt(attemptId(), new OperationId(str_repeat('c', 32)), $recipe);
        $incus->instances[featureTarget('TST-9', 'a', $recipe)->instance('gateway')] = [
            'type' => 'virtual-machine',
            'status' => 'Running',
            'config' => ['user.orbit.e2e.owner' => 'orbit-e2e', 'user.orbit.e2e.issue' => 'TST-9', 'user.orbit.e2e.attempt' => str_repeat('b', 32)],
            'devices' => ['root' => ['type' => 'disk', 'pool' => IncusHost::POOL, 'path' => '/']],
        ];

        expect(fn () => $releaser->release($request))
            ->toThrow(RuntimeException::class, 'ownership does not match the issue attempt')
            ->and($incus->guests())->toHaveCount(1)
            ->and(IssueState::forWorktree('TST-9', $worktree)->hasAttempt())->toBeTrue();
    });

    it('records schema 7 without the retired attempt purpose', function () {
        ['acquirer' => $acquirer, 'request' => $request] = acquirerFixture();
        $topology = $acquirer->acquire($request, TopologyRecipe::compose([]));

        expect($topology->toArray())->not->toHaveKey('purpose')
            ->and($topology->toArray()['schema'])->toBe(7);
    });
});
