<?php

declare(strict_types=1);

use App\E2E\TopologyGuestPreparer;
use App\E2E\IncusHost;
use App\E2E\Value\GuestCommand;
use App\E2E\Value\TopologyTarget;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\Process;
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
                    'type' => $role === 'operator' ? 'container' : 'virtual-machine',
                    'status' => 'Running',
                    'status_code' => 103,
                    'config' => ['user.orbit.e2e.owner' => 'orbit-e2e'],
                    'devices' => ['root' => ['pool' => 'default']],
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
