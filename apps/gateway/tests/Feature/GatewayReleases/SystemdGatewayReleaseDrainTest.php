<?php

declare(strict_types=1);

use App\Domain\GatewayReleases\GatewayReleaseException;
use App\Infrastructure\GatewayReleases\SystemdGatewayReleaseDrain;
use App\Infrastructure\Processes\CommandResult;
use App\Infrastructure\Processes\NativeProcessRunner;
use App\Infrastructure\Processes\ProcessInvocation;
use App\Infrastructure\Processes\ProcessRunner;
use Illuminate\Support\Facades\Redis;
use Tests\Support\GatewayReleasePipeline;

beforeEach(function (): void {
    $this->pipeline = new GatewayReleasePipeline;
    $this->source = $this->pipeline->adopt();
    $this->target = str_repeat('a', 40);
    $this->hold = $this->pipeline->home.'/hold.json';
    $this->observer = new DrainObservationRunner;
    file_put_contents($this->hold, json_encode(['target_sha' => $this->target, 'source_sha' => $this->source, 'units' => $this->observer->units]));
    chmod($this->hold, 0644);
    config(['queue.connections.gateway.driver' => 'redis', 'queue.connections.gateway.connection' => 'gateway']);
    $this->redis = Mockery::mock();
    Redis::shouldReceive('connection')->with('gateway')->andReturn($this->redis);
    $this->redis->shouldReceive('zcard')->andReturn(0)->byDefault();
    $this->drain = new SystemdGatewayReleaseDrain($this->observer, $this->pipeline->fixture->layout, $this->hold, $this->pipeline->home.'/cgroups', posix_getuid());
});

afterEach(function (): void {
    $this->pipeline->cleanup();
});

it('accepts a complete stopped masked inventory and empty reservations without changing services', function (): void {
    $this->drain->assertHeld($this->target);

    expect($this->observer->verbs)->toBe(['list-unit-files', 'list-units', 'show', 'show', 'show', '-eo']);
});

it('fails closed on changed or incomplete live observations', function (string $fault): void {
    $this->observer->fault = $fault;

    expect(fn () => $this->drain->assertHeld($this->target))->toThrow(GatewayReleaseException::class);
})->with(['active', 'unmasked', 'pid', 'cgroup', 'command-failed', 'truncated', 'inventory', 'php', 'malformed-process', 'template-unmasked']);

it('refuses unreadable or populated descendant cgroups', function (string $events): void {
    $this->observer->fault = 'cgroup';
    mkdir($this->pipeline->home.'/cgroups/system.slice/writer', 0700, true);
    file_put_contents($this->pipeline->home.'/cgroups/system.slice/writer/cgroup.events', $events);

    expect(fn () => $this->drain->assertHeld($this->target))->toThrow(GatewayReleaseException::class, 'cgroup');
})->with(['populated 1', 'frozen 0']);

it('refuses stale or writable manifests', function (string $fault): void {
    if ($fault === 'writable') {
        chmod($this->hold, 0666);
    } elseif ($fault === 'symlink') {
        rename($this->hold, $this->hold.'.saved');
        symlink($this->hold.'.saved', $this->hold);
    } else {
        $data = json_decode(file_get_contents($this->hold), true);
        $data[$fault] = str_repeat('b', 40);
        file_put_contents($this->hold, json_encode($data));
    }

    expect(fn () => $this->drain->assertHeld($this->target))->toThrow(GatewayReleaseException::class);
})->with(['writable', 'symlink', 'target_sha', 'source_sha']);

it('refuses reservations on every queue without consuming or clearing work', function (string $queue): void {
    $this->redis->shouldReceive('zcard')->with('queues:'.$queue.':reserved')->andReturn(1);

    expect(fn () => $this->drain->assertHeld($this->target))->toThrow(GatewayReleaseException::class, "Queue [{$queue}]");
})->with(['tasks', 'problems', 'task-vms', 'builds', 'builds-optional', 'operations']);

it('refuses hidden or unobservable process owners', function (string $mount): void {
    $path = $this->pipeline->home.'/proc-mounts';
    file_put_contents($path, $mount);
    $drain = new SystemdGatewayReleaseDrain($this->observer, $this->pipeline->fixture->layout, $this->hold, $this->pipeline->home.'/cgroups', posix_getuid(), $path);
    expect(fn () => $drain->assertHeld($this->target))->toThrow(GatewayReleaseException::class, 'visibility');
})->with(['proc /proc proc rw,hidepid=2 0 0', 'proc /proc proc rw,hidepid=invisible 0 0', 'unreadable inventory']);

it('redacts connection errors', function (): void {
    $this->redis->shouldReceive('zcard')->andThrow(new RuntimeException('password=do-not-report'));

    try {
        $this->drain->assertHeld($this->target);
        test()->fail('The observation should refuse');
    } catch (GatewayReleaseException $e) {
        expect($e->getMessage())->not->toContain('do-not-report')->toContain('could not be read');
    }
});

final class DrainObservationRunner implements ProcessRunner
{
    /** @var list<string> */
    public array $units = ['orbit-gateway-release-run@.service', 'orbit-gateway-release.timer', 'orbit-process-108-schedule-work.service', 'php8.5-fpm.service'];

    /** @var list<string> */
    public array $verbs = [];

    public string $fault = '';

    public function run(ProcessInvocation $invocation): CommandResult
    {
        $args = $invocation->arguments;
        $this->verbs[] = $args[1];
        if ($args[1] === 'show' && str_contains($args[2], '@.')) {
            throw new RuntimeException('systemctl show rejects an uninstantiated template');
        }
        $out = match ($args[1]) {
            'list-unit-files', 'list-units' => implode("\n", array_map(fn (string $unit): string => $unit.' masked disabled', $this->units)),
            'show' => "LoadState=masked\nActiveState=inactive\nMainPID=0\nControlGroup=\n",
            '-eo' => getmypid()." php8.5\n1 systemd\n",
            default => throw new RuntimeException('Unexpected command'),
        };
        $out = match ($this->fault) {
            'template-unmasked' => str_replace('orbit-gateway-release-run@.service masked', 'orbit-gateway-release-run@.service static', $out),
            'active' => str_replace('ActiveState=inactive', 'ActiveState=active', $out),
            'unmasked' => str_replace('LoadState=masked', 'LoadState=loaded', $out),
            'pid' => str_replace('MainPID=0', 'MainPID=99', $out),
            'cgroup' => str_replace("ControlGroup=\n", "ControlGroup=/system.slice/writer\n", $out),
            'inventory' => $args[1] === 'list-units' ? $out."\norbit-process-999-unknown.service loaded active" : $out,
            'php' => $args[1] === '-eo' ? $out."999999 php8.5\n" : $out,
            'malformed-process' => $args[1] === '-eo' ? 'unreadable' : $out,
            default => $out,
        };

        return new CommandResult($this->fault === 'command-failed' ? 1 : 0, $out, '', 0, $this->fault === 'truncated');
    }
}

it('observes a loaded concrete release runner in addition to its masked template', function (): void {
    $this->observer->units[] = 'orbit-gateway-release-run@42.service';
    file_put_contents($this->hold, json_encode(['target_sha' => $this->target, 'source_sha' => $this->source, 'units' => $this->observer->units]));
    $this->drain->assertHeld($this->target);
    expect($this->observer->verbs)->toBe(['list-unit-files', 'list-units', 'show', 'show', 'show', 'show', '-eo']);
    $this->observer->fault = 'pid';
    expect(fn () => $this->drain->assertHeld($this->target))->toThrow(GatewayReleaseException::class);
});

it('admits a held writer set despite an oversized inventory of irrelevant unit types', function (): void {
    $runner = new BoundedDrainInventoryRunner($this->observer);
    $drain = new SystemdGatewayReleaseDrain($runner, $this->pipeline->fixture->layout, $this->hold, $this->pipeline->home.'/cgroups', posix_getuid());

    $drain->assertHeld($this->target);

    expect($runner->unfilteredBytes['list-units'])->toBeGreaterThan(65_536);
    expect($runner->unfilteredBytes['list-unit-files'])->toBeGreaterThan(65_536);
    expect(array_keys($runner->results))->toContain('list-unit-files', 'list-units', '-eo');
    foreach ($runner->results as $result) {
        expect($result->truncated)->toBeFalse();
    }
});

it('discovers unlisted writers in either relevant inventory despite irrelevant host units', function (string $verb, string $unit): void {
    $runner = new BoundedDrainInventoryRunner($this->observer);
    $runner->additionalUnits[$verb] = [$unit];
    $drain = new SystemdGatewayReleaseDrain($runner, $this->pipeline->fixture->layout, $this->hold, $this->pipeline->home.'/cgroups', posix_getuid());

    expect(fn () => $drain->assertHeld($this->target))
        ->toThrow(GatewayReleaseException::class, 'writer inventory differs');
})->with([
    ['list-unit-files', 'orbit-process-999-unknown.service'],
    ['list-units', 'orbit-process-999-unknown.service'],
    ['list-unit-files', 'orbit-schedule-999.timer'],
    ['list-units', 'orbit-schedule-999.timer'],
    ['list-unit-files', 'orbit-process-999-unknown@.service'],
    ['list-units', 'orbit-gateway-release-run@42.service'],
    ['list-units', 'orbit-fleet-converge.timer'],
    ['list-units', 'orbit-runtime-hibernator.service'],
]);

it('reaches the active writer fence after complete relevant discovery', function (): void {
    $this->observer->fault = 'active';
    $runner = new BoundedDrainInventoryRunner($this->observer);
    $drain = new SystemdGatewayReleaseDrain($runner, $this->pipeline->fixture->layout, $this->hold, $this->pipeline->home.'/cgroups', posix_getuid());

    expect(fn () => $drain->assertHeld($this->target))
        ->toThrow(GatewayReleaseException::class, 'not masked and completely stopped');
});

it('still refuses capped relevant inventories and complete-looking failed command output', function (string $verb, bool $overflow): void {
    $runner = new BoundedDrainInventoryRunner($this->observer);
    if ($overflow) {
        $runner->additionalUnits[$verb] = array_map(
            static fn (int $id): string => "orbit-process-{$id}-unknown.service",
            range(1000, 3000),
        );
    } else {
        $runner->failedVerb = $verb;
    }
    $drain = new SystemdGatewayReleaseDrain($runner, $this->pipeline->fixture->layout, $this->hold, $this->pipeline->home.'/cgroups', posix_getuid());

    expect(fn () => $drain->assertHeld($this->target))
        ->toThrow(GatewayReleaseException::class, 'failed or returned incomplete output');
    expect($runner->results[$verb]->truncated)->toBe($overflow);
})->with([
    ['list-unit-files', true],
    ['list-units', true],
    ['list-unit-files', false],
    ['list-units', false],
]);

it('refuses a capped all-owner process scan even when PHP is outside the retained tail', function (): void {
    $runner = new BoundedDrainInventoryRunner($this->observer);
    $runner->largeProcessInventory = true;
    $drain = new SystemdGatewayReleaseDrain($runner, $this->pipeline->fixture->layout, $this->hold, $this->pipeline->home.'/cgroups', posix_getuid());

    expect(fn () => $drain->assertHeld($this->target))
        ->toThrow(GatewayReleaseException::class, 'failed or returned incomplete output');
    expect($runner->results['-eo']->truncated)->toBeTrue();
    expect($runner->results['-eo']->stdout)->not->toContain('php8.5');
});

it('retains the standalone PHP fence after complete relevant discovery', function (): void {
    $this->observer->fault = 'php';
    $runner = new BoundedDrainInventoryRunner($this->observer);
    $drain = new SystemdGatewayReleaseDrain($runner, $this->pipeline->fixture->layout, $this->hold, $this->pipeline->home.'/cgroups', posix_getuid());

    expect(fn () => $drain->assertHeld($this->target))
        ->toThrow(GatewayReleaseException::class, 'Another PHP process');
});

/** Synthetic inventory, with the real native runner enforcing its unchanged output cap. */
final class BoundedDrainInventoryRunner implements ProcessRunner
{
    /** @var array<string, list<string>> */
    public array $additionalUnits = [];

    /** @var array<string, int> */
    public array $unfilteredBytes = [];

    /** @var array<string, CommandResult> */
    public array $results = [];

    public string $failedVerb = '';

    public bool $largeProcessInventory = false;

    public function __construct(private DrainObservationRunner $observer) {}

    public function run(ProcessInvocation $invocation): CommandResult
    {
        $verb = $invocation->arguments[1];
        $observed = $this->observer->run($invocation);
        $out = $observed->stdout;
        if (in_array($verb, ['list-unit-files', 'list-units'], true)) {
            $rows = explode("\n", $out);
            foreach ($this->additionalUnits[$verb] ?? [] as $unit) {
                $rows[] = $unit.' masked disabled';
            }
            foreach (range(1, 1400) as $id) {
                $type = $id % 2 === 0 ? 'device' : 'mount';
                $rows[] = "sys-devices-virtual-fixture-{$id}.{$type} loaded active Fixture host inventory";
            }
            $this->unfilteredBytes[$verb] = strlen(implode("\n", $rows));
            foreach ($invocation->arguments as $argument) {
                if (str_starts_with($argument, '--type=')) {
                    $types = explode(',', substr($argument, 7));
                    $rows = array_values(array_filter($rows, static function (string $row) use ($types): bool {
                        $unit = explode(' ', $row)[0];

                        return in_array(pathinfo($unit, PATHINFO_EXTENSION), $types, true);
                    }));
                }
            }
            $out = implode("\n", $rows)."\n";
        } elseif ($verb === '-eo' && $this->largeProcessInventory) {
            $out = "999999 php8.5\n".str_repeat("12345 unrelated\n", 5000);
        }

        return $this->results[$verb] = new NativeProcessRunner()->run(new ProcessInvocation(
            [PHP_BINARY, '-r', 'fwrite(STDOUT, stream_get_contents(STDIN)); exit((int) $argv[1]);', $this->failedVerb === $verb ? '1' : '0'],
            timeout: 30.0,
            input: $out,
            maxOutputBytes: $invocation->maxOutputBytes,
        ));
    }
}
