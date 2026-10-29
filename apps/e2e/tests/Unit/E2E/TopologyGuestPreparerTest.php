<?php

declare(strict_types=1);

use App\E2E\IncusHost;
use App\E2E\TopologyGuestPreparer;
use App\E2E\Value\GuestCommand;
use App\E2E\Value\TopologyTarget;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\Process;
use Tests\Support\FakeIncus;
use Tests\TestCase;

uses(TestCase::class);

/**
 * Every guest command reaches the fake through the host batch helper or one
 * direct `incus exec`; a failure map names the batch label (or `environment`
 * for the direct gateway `.env` placement) that must fail.
 *
 * @param  array<string, int>  $failures
 * @param  list<array{labels:list<string>,instances:list<string>,argv:list<list<string>>}>  $batches
 * @param  list<array{instance:string,argv:list<string>,stdin:?string,timeout:int}>  $execs
 * @param  array<string, string>  $outputs  stdout of a batch label, or stderr of the direct `environment` exec
 */
function fakePreparerGuests(array $failures, array &$batches, array &$execs, array $outputs = []): void
{
    Process::fake(function (PendingProcess $process) use ($failures, &$batches, &$execs, $outputs) {
        $command = $process->command;
        if (($command[0] ?? null) === 'python3' && str_ends_with((string) $command[1], '/resources/host/exec-all.py')) {
            $payload = json_decode((string) $process->input, true, 512, JSON_THROW_ON_ERROR);
            $batch = ['labels' => [], 'instances' => [], 'argv' => [], 'stdin' => []];
            $results = [];
            foreach ($payload['requests'] as $request) {
                $batch['labels'][] = $request['label'];
                $batch['instances'][] = $request['instance'];
                $batch['argv'][] = $request['argv'];
                $batch['stdin'][] = $request['stdin'];
                $results[] = [
                    'label' => $request['label'],
                    'stdout' => $outputs[$request['label']] ?? '',
                    'stderr' => '',
                    'exit_code' => $failures[$request['label']] ?? 0,
                ];
            }
            $batches[] = $batch;

            return Process::result(json_encode($results, JSON_THROW_ON_ERROR));
        }
        if (($command[3] ?? null) === 'list') {
            // The direct exec validates instance ownership from the inventory first.
            return Process::result(json_encode(array_map(
                static fn (string $role): array => [
                    'name' => preparerTarget()->instance($role),
                    'type' => 'virtual-machine',
                    'status' => 'Running',
                    'status_code' => 103,
                    'config' => ['user.orbit.e2e.owner' => 'orbit-e2e'],
                    'devices' => ['root' => ['pool' => IncusHost::POOL]],
                ],
                preparerTarget()->recipe->nodeKeys(),
            ), JSON_THROW_ON_ERROR));
        }
        expect($command[3] ?? null)->toBe('exec');
        $execs[] = [
            'instance' => (string) $command[4],
            'argv' => array_slice($command, 6),
            'stdin' => is_string($process->input) ? $process->input : null,
            'timeout' => $process->timeout,
        ];

        if (($failures['schema-timeout'] ?? null) !== null && in_array('migrate', $command, true)) {
            throw new RuntimeException('simulated timeout with private output');
        }

        return Process::result('', $outputs['environment'] ?? '', $failures['environment'] ?? 0);
    });
}

function preparerTarget(): TopologyTarget
{
    return featureTarget('TST-123');
}

/** Running copies of the template for every Node of the preparer target. */
function settlingGuests(): FakeIncus
{
    $incus = new FakeIncus()->install();
    foreach (preparerTarget()->instances() as $instance) {
        $incus->instances[$instance] = [...$incus->instances[IncusHost::TEMPLATE], 'status' => 'Running', 'config' => ['user.orbit.e2e.owner' => 'orbit-e2e']];
    }

    return $incus;
}

function pnpmPreparer(): TopologyGuestPreparer
{
    $binary = tempnam(sys_get_temp_dir(), 'orbit-agent-test-');
    file_put_contents($binary, 'source-built candidate');
    test()->beforeApplicationDestroyed(static fn () => unlink($binary));

    return new TopologyGuestPreparer(new IncusHost, settleIntervalMicroseconds: 0, agentBinary: $binary);
}

describe('mount.source', function () {
    it('proves the mount on every checkout role without writing to a guest', function () {
        $batches = [];
        $execs = [];
        fakePreparerGuests([], $batches, $execs);
        $target = preparerTarget();

        new TopologyGuestPreparer(new IncusHost)->assertSourceMounted($target);

        expect($batches)
            ->toHaveCount(1)
            ->and($batches[0]['labels'])
            ->toBe(['mountpoint.gateway', 'mountpoint.app-dev', 'mountpoint.operator'])
            ->and($batches[0]['instances'])
            ->toBe(['local:'.$target->instance('gateway'), 'local:'.$target->instance('app-dev'), 'local:'.$target->instance('operator')])
            ->and($batches[0]['argv'])
            ->each
            ->toBe(['mountpoint', '-q', '--', '/home/orbit/orbit'])
            ->and($execs)
            ->toBe([]);
    });

    it('names every role whose mount is missing', function () {
        $batches = [];
        $execs = [];
        fakePreparerGuests(['mountpoint.gateway' => 32, 'mountpoint.app-dev' => 32], $batches, $execs);

        expect(fn () => new TopologyGuestPreparer(new IncusHost)->assertSourceMounted(preparerTarget()))
            ->toThrow(RuntimeException::class, 'The worktree is not mounted on mountpoint.gateway, mountpoint.app-dev.')
            ->and($execs)
            ->toBe([]);
    });

    it('points the mounted gateway environment at the mounted checkout', function () {
        $batches = [];
        $execs = [];
        fakePreparerGuests([], $batches, $execs);
        $target = preparerTarget();

        new TopologyGuestPreparer(new IncusHost)->placeGatewayEnvironment($target);

        expect($batches)
            ->toBe([])
            ->and($execs)
            ->toHaveCount(1)
            ->and($execs[0]['instance'])
            ->toBe('local:'.$target->instance('gateway'))
            ->and($execs[0]['argv'])
            ->toBe([
                'sh',
                '-ec',
                TopologyGuestPreparer::GATEWAY_ENVIRONMENT_SCRIPT,
                'orbit-e2e',
                '/home/orbit/orbit/apps/gateway/.env',
                '/home/orbit/orbit/apps/gateway',
            ])
            ->and($execs[0]['stdin'])
            ->toBeNull()
            ->and($execs[0]['timeout'])
            ->toBe(30);
    });

    it('rewrites only the checkout line of a bootstrapped gateway environment', function (string $contents) {
        $directory = temporaryDirectory().'/orbit-e2e-env-'.bin2hex(random_bytes(4));
        mkdir($directory);
        $environment = $directory.'/.env';
        file_put_contents($environment, $contents);
        chmod($environment, 0o640);

        try {
            $result = Process::run(['sh', '-ec', TopologyGuestPreparer::GATEWAY_ENVIRONMENT_SCRIPT, 'orbit-e2e', $environment, '/home/orbit/orbit/apps/gateway']);

            expect($result->successful())->toBeTrue()
                ->and(file_get_contents($environment))->toBe("APP_ENV=local\nORBIT_HOME=/home/orbit/.orbit\nORBIT_GATEWAY_CHECKOUT=/home/orbit/orbit/apps/gateway\n")
                ->and(fileperms($environment) & 0o777)->toBe(0o640)
                ->and(glob($directory.'/.env.*'))->toBe([]);
        } finally {
            (new Filesystem)->deleteDirectory($directory);
        }
    })->with([
        'bootstrap default' => ["APP_ENV=local\nORBIT_GATEWAY_CHECKOUT=/home/orbit/orbit-gateway\nORBIT_HOME=/home/orbit/.orbit\n"],
        'no checkout line' => ["APP_ENV=local\nORBIT_HOME=/home/orbit/.orbit\n"],
    ])->skip(PHP_OS_FAMILY !== 'Linux', 'The guest script uses GNU chown and chmod.');

    it('leaves a gateway environment that already names the mounted checkout untouched', function () {
        $directory = temporaryDirectory().'/orbit-e2e-env-'.bin2hex(random_bytes(4));
        mkdir($directory);
        $environment = $directory.'/.env';
        file_put_contents($environment, "ORBIT_GATEWAY_CHECKOUT=/home/orbit/orbit/apps/gateway\nAPP_ENV=local\n");
        $inode = fileinode($environment);

        try {
            $result = Process::run(['sh', '-ec', TopologyGuestPreparer::GATEWAY_ENVIRONMENT_SCRIPT, 'orbit-e2e', $environment, '/home/orbit/orbit/apps/gateway']);

            clearstatcache();
            expect($result->successful())->toBeTrue()
                ->and(fileinode($environment))->toBe($inode)
                ->and(file_get_contents($environment))->toBe("ORBIT_GATEWAY_CHECKOUT=/home/orbit/orbit/apps/gateway\nAPP_ENV=local\n");
        } finally {
            @unlink($environment);
            @rmdir($directory);
        }
    });

    it('fails with the bootstrap remedy when the worktree has no gateway environment', function () {
        $directory = temporaryDirectory().'/orbit-e2e-env-'.bin2hex(random_bytes(4));
        mkdir($directory);

        try {
            $result = Process::run(['sh', '-ec', TopologyGuestPreparer::GATEWAY_ENVIRONMENT_SCRIPT, 'orbit-e2e', $directory.'/.env', '/home/orbit/orbit/apps/gateway']);

            expect($result->exitCode())->toBe(66)
                ->and($result->errorOutput())->toBe("The worktree has no {$directory}/.env; run bin/bootstrap on the host.\n")
                ->and(glob($directory.'/*'))->toBe([]);
        } finally {
            (new Filesystem)->deleteDirectory($directory);
        }
    });

    it('reports the guest error when the gateway environment cannot be prepared', function () {
        $batches = [];
        $execs = [];
        fakePreparerGuests(['environment' => 66], $batches, $execs, ['environment' => "The worktree has no /home/orbit/orbit/apps/gateway/.env; run bin/bootstrap on the host.\n"]);

        expect(fn () => new TopologyGuestPreparer(new IncusHost)->placeGatewayEnvironment(preparerTarget()))
            ->toThrow(
                RuntimeException::class,
                'The Gateway environment of the mounted worktree could not be prepared: The worktree has no /home/orbit/orbit/apps/gateway/.env; run bin/bootstrap on the host.',
            )
            ->and($execs)
            ->toHaveCount(1);
    });

    it('links the orbit CLI onto the PATH of every checkout role as root', function () {
        $batches = [];
        $execs = [];
        fakePreparerGuests([], $batches, $execs);
        $target = preparerTarget();

        new TopologyGuestPreparer(new IncusHost)->exposeOrbitCli($target);

        expect($batches)
            ->toHaveCount(1)
            ->and($batches[0]['labels'])
            ->toBe(['orbit-cli.gateway', 'orbit-cli.app-dev', 'orbit-cli.operator'])
            ->and($batches[0]['instances'])
            ->toBe(['local:'.$target->instance('gateway'), 'local:'.$target->instance('app-dev'), 'local:'.$target->instance('operator')])
            ->and($batches[0]['argv'])
            ->each
            ->toBe(['ln', '-sfn', '/home/orbit/orbit/apps/cli/orbit', '/usr/local/bin/orbit'])
            ->and($execs)
            ->toBe([]);
    });

    it('names every role whose CLI link failed', function () {
        $batches = [];
        $execs = [];
        fakePreparerGuests(['orbit-cli.app-dev' => 1], $batches, $execs);

        expect(fn () => new TopologyGuestPreparer(new IncusHost)->exposeOrbitCli(preparerTarget()))
            ->toThrow(RuntimeException::class, 'The orbit CLI could not be linked onto the PATH on orbit-cli.app-dev.');
    });
});

describe('prepare.schema', function () {
    it('runs the current migration resource on the Gateway before readiness', function (): void {
        $batches = [];
        $execs = [];
        fakePreparerGuests([], $batches, $execs);
        $target = preparerTarget();

        new TopologyGuestPreparer(new IncusHost)->prepareGatewaySchema($target);

        expect($batches)
            ->toBe([])
            ->and($execs)
            ->toHaveCount(1)
            ->and($execs[0]['instance'])
            ->toBe('local:'.$target->instance('gateway'))
            ->and($execs[0]['argv'])
            ->toBe([
                ...GuestCommand::ORBIT_USER_PREFIX,
                'env',
                '-C',
                '/home/orbit/orbit/apps/gateway',
                'ORBIT_GATEWAY_CHECKOUT=/home/orbit/orbit/apps/gateway',
                'DB_DATABASE=/home/orbit/.orbit/gateway.sqlite',
                'php',
                'artisan',
                'migrate',
                '--force',
                '--no-interaction',
            ])
            ->and($execs[0]['stdin'])
            ->toBeNull()
            ->and($execs[0]['timeout'])
            ->toBe(900);
    });

    it('reports a migration failure without guest output', function (): void {
        $batches = [];
        $execs = [];
        fakePreparerGuests(['environment' => 73], $batches, $execs);

        expect(fn () => new TopologyGuestPreparer(new IncusHost)->prepareGatewaySchema(preparerTarget()))
            ->toThrow(RuntimeException::class, 'Gateway schema preparation failed with exit code 73.')
            ->and($execs)
            ->toHaveCount(1);
    });

    it('reports a migration timeout without guest output', function (): void {
        $batches = [];
        $execs = [];
        fakePreparerGuests(['schema-timeout' => 1], $batches, $execs);

        expect(fn () => new TopologyGuestPreparer(new IncusHost)->prepareGatewaySchema(preparerTarget()))
            ->toThrow(
                RuntimeException::class,
                'Gateway schema preparation could not complete: Incus command timed out or could not run: Incus guest command could not run.',
            )
            ->and($execs)
            ->toHaveCount(1);
    });
});

describe('settle', function () {
    it('stages the source agent and sends exact pins without a guest source mount', function (): void {
        $incus = settlingGuests();
        $incus->respond(static fn (string $instance, array $argv): ?array => $argv[0] === 'sh' ? ["boot-ready\n", '', 0] : null);
        $target = preparerTarget();

        pnpmPreparer()->settle($target);

        $preparations = array_values(array_filter($incus->execs, static fn (array $exec): bool => ($exec['argv'][2] ?? null) === 'recipe.install-javascript'));
        expect($preparations)->toHaveCount(count($target->instances()));
        foreach ($preparations as $preparation) {
            $envelope = json_decode($preparation['stdin'], true, flags: JSON_THROW_ON_ERROR);
            expect($envelope['agent_sha256'])->toBe(hash('sha256', 'source-built candidate'))
                ->and($envelope['deadline_ms'])->toBe(900000)
                ->and($envelope['input']['tools'])->toHaveKeys(['pnpm'])
                ->and($envelope['input']['architecture'])->toBe('amd64')
                ->and($envelope['input']['user'])->toBe('orbit')
                ->and($preparation['argv'][0])->toStartWith('/run/orbit-e2e-pnpm-');
        }
        expect($incus->callsOf('file', 'push'))->toHaveCount(count($target->instances()));
        $cleanups = array_values(array_filter($incus->execs, static fn (array $exec): bool => $exec['argv'][0] === 'rm'));
        expect($cleanups)->toHaveCount(count($target->instances()));
        foreach ($cleanups as $cleanup) {
            expect($cleanup['argv'][3])->toBe(dirname($preparations[0]['argv'][0]));
        }
    });

    it('sets public DNS once, waits through the first-boot reboot, then prepares pnpm', function (): void {
        $incus = settlingGuests();
        $probes = [];
        $incus->respond(function (string $instance, array $argv) use (&$probes): ?array {
            if ($argv[0] !== 'sh' || ! str_contains($argv[2], 'boot_id')) {
                return null;
            }
            $probes[$instance] = ($probes[$instance] ?? 0) + 1;

            return [$probes[$instance] === 1 ? "before-reboot\n" : "after-reboot\n", '', 0];
        });
        $target = preparerTarget();

        pnpmPreparer()->settle($target);

        $kinds = array_map(static fn (array $exec): string => match (true) {
            $exec['argv'][0] === 'bash' => 'dns',
            ($exec['argv'][2] ?? null) === 'recipe.install-javascript' => 'pnpm',
            $exec['argv'][0] === 'dpkg' => 'architecture',
            $exec['argv'][0] === 'mkdir' => 'staging',
            $exec['argv'][0] === 'install' => 'candidate',
            $exec['argv'][0] === 'rm' => 'cleanup',
            default => 'probe',
        }, $incus->execs);
        $guests = count($target->instances());

        expect(array_count_values($kinds))->toBe(['dns' => $guests, 'probe' => 3 * $guests, 'architecture' => $guests, 'staging' => $guests, 'candidate' => $guests, 'pnpm' => $guests, 'cleanup' => $guests])
            ->and(array_slice($kinds, 0, $guests))->toBe(array_fill(0, $guests, 'dns'))
            ->and(array_slice($kinds, -$guests))->toBe(array_fill(0, $guests, 'cleanup'))
            ->and($probes)->toBe(array_fill_keys(array_values($target->instances()), 3));
    });

    it('retries public DNS until every guest accepts it', function (): void {
        $incus = settlingGuests();
        $attempts = 0;
        $incus->respond(function (string $instance, array $argv) use (&$attempts): ?array {
            if ($argv[0] === 'bash' && $instance === preparerTarget()->instance('gateway') && ++$attempts === 1) {
                return ['', 'resolver not ready', 1];
            }

            return $argv[0] === 'sh' ? ["boot\n", '', 0] : null;
        });

        pnpmPreparer()->settle(preparerTarget());

        expect($attempts)->toBe(2)
            ->and(count(array_filter($incus->execs, static fn (array $exec): bool => ($exec['argv'][2] ?? null) === 'recipe.install-javascript')))->toBe(count(preparerTarget()->instances()));
    });

    it('names the guest whose pnpm preparation failed', function (): void {
        $incus = settlingGuests();
        $incus->respond(static fn (string $instance, array $argv): ?array => match (true) {
            $argv[0] === 'sh' => ["boot\n", '', 0],
            ($argv[2] ?? null) === 'recipe.install-javascript' && $instance === preparerTarget()->instance('operator') => ['', 'pinned artifact digest mismatch', 1],
            default => null,
        });

        expect(fn () => pnpmPreparer()->settle(preparerTarget()))
            ->toThrow(RuntimeException::class, 'Recipe pnpm preparation failed on '.preparerTarget()->instance('operator').'.');
    });
});

describe('native pnpm preparation failures', function (): void {
    it('refuses malformed or unsuccessful native results and cleans every staged candidate', function (string $output, int $exit): void {
        $incus = settlingGuests();
        $incus->respond(static fn (string $instance, array $argv): ?array => match (true) {
            $argv[0] === 'sh' => ["boot\n", '', 0],
            ($argv[2] ?? null) === 'recipe.install-javascript' => [$output, 'private diagnostics', $exit],
            default => null,
        });

        expect(fn () => pnpmPreparer()->settle(preparerTarget()))->toThrow(RuntimeException::class, 'Recipe pnpm preparation failed on');
        expect(array_filter($incus->execs, static fn (array $exec): bool => $exec['argv'][0] === 'rm'))->toHaveCount(count(preparerTarget()->instances()));
    })->with([
        'invalid JSON' => ['invalid', 0],
        'typed refusal' => ['{"ok":false,"error":{"code":"recipe.failed"}}', 0],
        'missing output' => ['{"ok":true}', 0],
        'invalid output fields' => ['{"ok":true,"data":{"changed":"yes","retired":[]}}', 0],
        'process failure' => ['{"ok":true,"data":{"changed":false,"retired":[]}}', 1],
    ]);

    it('refuses an unsupported architecture before staging any binary', function (string $architecture): void {
        $incus = settlingGuests();
        $incus->respond(static fn (string $instance, array $argv): ?array => match ($argv[0]) {
            'sh' => ["boot\n", '', 0],
            'dpkg' => ["{$architecture}\n", '', 0],
            default => null,
        });

        expect(fn () => pnpmPreparer()->settle(preparerTarget()))->toThrow(RuntimeException::class, 'Cannot determine the pnpm architecture')
            ->and($incus->callsOf('file', 'push'))->toBe([])
            ->and(array_filter($incus->execs, static fn (array $exec): bool => $exec['argv'][0] === 'mkdir'))->toBe([]);
    })->with(['unsupported', 'arm64']);

    it('requires the source build before staging on a guest', function (): void {
        $incus = settlingGuests();
        $incus->respond(static fn (string $instance, array $argv): ?array => $argv[0] === 'sh' ? ["boot\n", '', 0] : null);

        expect(fn () => new TopologyGuestPreparer(new IncusHost, settleIntervalMicroseconds: 0, agentBinary: '/absent-orbit-agent-candidate')->settle(preparerTarget()))
            ->toThrow(RuntimeException::class, 'Build apps/agent with cargo build --release --locked')
            ->and($incus->callsOf('file', 'push'))->toBe([]);
    });

    it('cleans staging when a binary transfer fails before running the command', function (): void {
        $incus = settlingGuests();
        $incus->respond(static fn (string $instance, array $argv): ?array => $argv[0] === 'sh' ? ["boot\n", '', 0] : null);
        $incus->failWhen(static fn (array $argv): ?string => array_slice($argv, 0, 2) === ['file', 'push'] ? 'transfer failed' : null);

        expect(fn () => pnpmPreparer()->settle(preparerTarget()))->toThrow(RuntimeException::class, 'Incus file push batch failed')
            ->and(array_filter($incus->execs, static fn (array $exec): bool => $exec['argv'][0] === 'rm'))->toHaveCount(count(preparerTarget()->instances()))
            ->and(array_filter($incus->execs, static fn (array $exec): bool => ($exec['argv'][2] ?? null) === 'recipe.install-javascript'))->toBe([]);
    });
});
