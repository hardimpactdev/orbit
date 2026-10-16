<?php

declare(strict_types=1);

use App\Domain\GatewayReleases\GatewayReleaseException;
use App\Infrastructure\GatewayReleases\SystemdGatewayReleaseDrain;
use App\Infrastructure\Processes\CommandResult;
use App\Infrastructure\Processes\ProcessInvocation;
use App\Infrastructure\Processes\ProcessRunner;
use Illuminate\Support\Facades\Redis;
use Tests\Support\GatewayReleasePipeline;

pest()->group('subprocess');

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
})->with(['tasks', 'task-vms', 'builds', 'operations']);

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
