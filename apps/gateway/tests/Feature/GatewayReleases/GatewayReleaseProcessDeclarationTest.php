<?php

declare(strict_types=1);

use App\Domain\Gateway\GatewayServingHost;
use App\Domain\GatewayReleases\GatewayReleaseDrain;
use App\Domain\GatewayReleases\GatewayReleaseException;
use App\Domain\GatewayReleases\GatewayReleaseExecutionOwner;
use App\Domain\Processes\ProcessTarget;
use App\Infrastructure\GatewayReleases\GatewayReleaseProcessDeclaration;
use App\Infrastructure\GatewayReleases\SystemdGatewayReleaseDrain;
use App\Infrastructure\Processes\CommandResult;
use App\Infrastructure\Processes\ProcessInvocation;
use App\Infrastructure\Processes\ProcessRunner;
use App\Infrastructure\Processes\SystemdProcessRenderer;
use App\Models\GatewayRelease;
use App\Models\Node;
use App\Models\Process;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Redis;
use Tests\Support\GatewayReleasePipeline;

pest()->group('subprocess');

beforeEach(function (): void {
    $this->pipeline = new GatewayReleasePipeline(adopted: true);
    $this->source = $this->pipeline->adopt();
    $this->target = $this->pipeline->fixture->commit('Staged Process continuity');
    $this->pipeline->bindLeaves();
    $this->ordinary = $this->pipeline->fixture->base.'/ordinary';
    $this->unitsPath = $this->pipeline->fixture->base.'/units';
    $this->proc = $this->pipeline->fixture->base.'/proc';
    $this->cgroups = $this->pipeline->fixture->base.'/cgroups';
    mkdir($this->ordinary, 0750);
    mkdir($this->unitsPath, 0755);
    mkdir($this->proc.'/3945', 0700, true);
    $user = posix_getpwuid(posix_geteuid())['name'];
    $this->node = Node::query()->create(['name' => 'declaration-test', 'status' => 'active', 'platform' => 'linux', 'public_ssh_host' => '127.0.0.1', 'user' => $user]);
    app(GatewayServingHost::class)->remember($this->node);
    $this->process = Process::query()->create([
        'owner_type' => Node::class, 'owner_id' => $this->node->id, 'name' => 'ordinary-native',
        'runtime' => 'systemd', 'working_directory' => $this->ordinary,
        'runtime_config' => ['command' => ['/usr/bin/sleep', 'infinity'], 'environment' => []],
        'restart_policy' => 'always', 'desired_state' => 'running', 'status' => 'active',
    ]);
    $renderer = new SystemdProcessRenderer;
    $this->unit = $renderer->unitName($this->process);
    file_put_contents($this->unitsPath.'/'.$this->unit, $renderer->render($this->process, new ProcessTarget($this->node, $user, $this->ordinary)));
    chmod($this->unitsPath.'/'.$this->unit, 0644);
    $group = '/system.slice/'.$this->unit;
    mkdir($this->cgroups.$group, 0700, true);
    file_put_contents($this->cgroups.$group.'/cgroup.procs', "3945\n");
    file_put_contents($this->cgroups.$group.'/cgroup.events', "populated 1\nfrozen 0\n");
    file_put_contents($this->cgroups.$group.'/cgroup.stat', "nr_descendants 0\n");
    $fields = array_fill(0, 20, '0');
    $fields[0] = 'S';
    $fields[19] = '12345';
    file_put_contents($this->proc.'/3945/stat', '3945 (sleep) '.implode(' ', $fields));
    file_put_contents($this->proc.'/3945/cmdline', "/usr/bin/sleep\0infinity\0");
    file_put_contents($this->proc.'/3945/environ', "DECLARATION_FIXTURE=do-not-report\0");
    symlink('/usr/bin/sleep', $this->proc.'/3945/exe');
    symlink($this->ordinary, $this->proc.'/3945/cwd');
    $this->observer = new ProcessDeclarationObservationRunner;
    $this->observer->ordinaryUnit = $this->unit;
    $this->observer->properties = [
        'Id' => $this->unit, 'LoadState' => 'loaded', 'ActiveState' => 'active', 'SubState' => 'running',
        'MainPID' => '3945', 'ControlGroup' => $group, 'InvocationID' => str_repeat('a', 32),
        'User' => $user, 'WorkingDirectory' => $this->ordinary,
        'ExecStart' => '{ path=/usr/bin/sleep ; argv[]=/usr/bin/sleep infinity ; pid=3945 ; }',
        'FragmentPath' => $this->unitsPath.'/'.$this->unit, 'DropInPaths' => '', 'NeedDaemonReload' => 'no', 'Transient' => 'no',
    ];
    app()->instance(GatewayReleaseProcessDeclaration::class, new GatewayReleaseProcessDeclaration(
        $this->observer, $this->pipeline->fixture->layout, app(GatewayServingHost::class),
        $this->cgroups, $this->proc, [$this->unitsPath], posix_geteuid(),
    ));
    $this->hold = $this->pipeline->home.'/hold.json';
    $this->holdData = ['target_sha' => $this->target, 'source_sha' => $this->source, 'units' => $this->observer->writers];
    $this->writeHold = function (): void {
        file_put_contents($this->hold, json_encode($this->holdData, JSON_THROW_ON_ERROR));
        chmod($this->hold, 0644);
    };
    ($this->writeHold)();
    app()->instance(GatewayReleaseDrain::class, new SystemdGatewayReleaseDrain(
        $this->observer, $this->pipeline->fixture->layout, $this->hold, $this->cgroups, posix_geteuid(),
    ));
    config(['queue.connections.gateway.driver' => 'redis', 'queue.connections.gateway.connection' => 'gateway']);
    $redis = Mockery::mock();
    Redis::shouldReceive('connection')->with('gateway')->andReturn($redis);
    $redis->shouldReceive('zcard')->andReturn(0)->byDefault();
    $this->redis = $redis;
    $this->declaration = function (): array {
        $code = Artisan::call('gateway:release:inspect-process', ['commit' => $this->target, 'process' => (string) $this->process->id]);
        $output = Artisan::output();
        expect($code)->toBe(0, $output);
        expect($output)->not->toContain('do-not-report');

        return json_decode($output, true)['observation'];
    };
});

afterEach(function (): void {
    $this->pipeline->cleanup();
});

it('stages with a declared unrelated Process continuously active whether listed in the hold inventory or not', function (bool $included): void {
    $this->holdData['unrelated_processes'] = [($this->declaration)()];
    if ($included) {
        $this->holdData['units'][] = $this->unit;
    }
    ($this->writeHold)();
    $before = $this->observer->properties;
    $attributes = $this->process->fresh()->getAttributes();
    $unitBytes = file_get_contents($this->unitsPath.'/'.$this->unit);

    expect(Artisan::call('gateway:release:stage', ['commit' => $this->target]))->toBe(0, Artisan::output());
    expect(GatewayRelease::query()->sole()->outcome)->toBe('paused');
    expect($this->observer->properties)->toBe($before);
    expect($this->process->fresh()->getAttributes())->toBe($attributes);
    expect(file_get_contents($this->unitsPath.'/'.$this->unit))->toBe($unitBytes);
    expect(file_get_contents($this->proc.'/3945/cmdline'))->toBe("/usr/bin/sleep\0infinity\0");
})->with(['included' => true, 'excluded' => false]);

it('retains conservative handling without an unrelated Process declaration', function (bool $included): void {
    if ($included) {
        $this->holdData['units'][] = $this->unit;
        ($this->writeHold)();
    }
    expect(Artisan::call('gateway:release:stage', ['commit' => $this->target]))->toBe(1);
    expect(json_decode(Artisan::output(), true)['error_code'])->toBe('gateway.release_not_drained');
    expect(GatewayRelease::query()->count())->toBe(0);
    expect($this->observer->properties['MainPID'])->toBe('3945');
})->with([true, false]);

it('refuses a stale or malformed declaration before recording a stage', function (string $fault): void {
    $declaration = ($this->declaration)();
    if ($fault === 'duplicate') {
        $this->holdData['unrelated_processes'] = [$declaration, $declaration];
    } else {
        $declaration[$fault] = match ($fault) {
            'process_id', 'node_id' => 999999,
            'unit' => 'php8.5-fpm.service',
            'extra' => 'unreviewed',
            default => str_repeat('b', str_ends_with($fault, '_sha256') ? 64 : 40),
        };
        $this->holdData['unrelated_processes'] = [$declaration];
    }
    ($this->writeHold)();
    expect(Artisan::call('gateway:release:stage', ['commit' => $this->target]))->toBe(1);
    expect(json_decode(Artisan::output(), true)['error_code'])->toBe('gateway.release_not_drained');
    expect(GatewayRelease::query()->count())->toBe(0);
})->with(['source_sha', 'target_sha', 'spec_sha256', 'unit_sha256', 'runtime_sha256', 'process_id', 'node_id', 'unit', 'extra', 'duplicate']);

it('refuses changed Process identity and incomplete runtime observations', function (string $fault): void {
    $this->holdData['unrelated_processes'] = [($this->declaration)()];
    ($this->writeHold)();
    match ($fault) {
        'spec' => $this->process->update(['restart_policy' => 'never']),
        'unknown-owner' => $this->process->update(['owner_type' => 'node']),
        'placement' => $this->process->update(['owner_id' => 999999]),
        'serving-node' => app(GatewayServingHost::class)->remember(new Node()->forceFill(['id' => 999999])),
        'preset' => $this->process->update(['runtime_config' => ['command' => ['/usr/bin/sleep', 'infinity'], 'preset' => 'vp-dev']]),
        'pending' => $this->process->forceFill(['systemd_activation_pending' => true])->save(),
        'unit-bytes' => file_put_contents($this->unitsPath.'/'.$this->unit, "\nExecStop=/usr/bin/false\n", FILE_APPEND),
        'unit-mode' => chmod($this->unitsPath.'/'.$this->unit, 0666),
        'pid' => $this->observer->properties['MainPID'] = '3946',
        'invocation' => $this->observer->properties['InvocationID'] = str_repeat('b', 32),
        'reload' => $this->observer->properties['NeedDaemonReload'] = 'yes',
        'drop-in' => $this->observer->properties['DropInPaths'] = '/etc/systemd/system/override.conf',
        'incomplete' => $this->observer->omitProperty = true,
        'truncated' => $this->observer->truncated = true,
        'command-failed' => $this->observer->failed = true,
        'absent-inventory' => $this->observer->listed = false,
        'descendant' => file_put_contents($this->cgroups.'/system.slice/'.$this->unit.'/cgroup.procs', "3945\n3946\n"),
        'nested-cgroup' => file_put_contents($this->cgroups.'/system.slice/'.$this->unit.'/cgroup.stat', "nr_descendants 1\n"),
        'argv' => file_put_contents($this->proc.'/3945/cmdline', "/usr/bin/sleep\01\0"),
        'environment' => file_put_contents($this->proc.'/3945/environ', "CHANGED=do-not-report\0"),
        'unreadable-runtime' => unlink($this->proc.'/3945/stat'),
        'standalone-php' => $this->observer->php = true,
    };
    expect(Artisan::call('gateway:release:stage', ['commit' => $this->target]))->toBe(1);
    $output = Artisan::output();
    expect(json_decode($output, true)['error_code'])->toBe('gateway.release_not_drained');
    expect($output)->not->toContain('do-not-report');
    expect(GatewayRelease::query()->count())->toBe(0);
})->with(['spec', 'unknown-owner', 'placement', 'serving-node', 'preset', 'pending', 'unit-bytes', 'unit-mode', 'pid', 'invocation', 'reload', 'drop-in', 'incomplete', 'truncated', 'command-failed', 'absent-inventory', 'descendant', 'nested-cgroup', 'argv', 'environment', 'unreadable-runtime', 'standalone-php']);

it('does not produce declarations for Gateway consumers', function (string $kind): void {
    if ($kind === 'release-directory') {
        $this->process->update(['working_directory' => $this->pipeline->fixture->layout->currentPath()]);
    } else {
        $this->process->update(['runtime_config' => ['command' => ['/usr/bin/php8.5', 'artisan', $kind], 'environment' => []]]);
    }
    expect(Artisan::call('gateway:release:inspect-process', ['commit' => $this->target, 'process' => (string) $this->process->id]))->toBe(1);
    expect(json_decode(Artisan::output(), true)['error_code'])->toBe('gateway.release_not_drained');
})->with(['horizon', 'schedule:work', 'release-directory']);

it('validates the execution owner before observing a Process', function (): void {
    app()->instance(GatewayReleaseExecutionOwner::class, new class implements GatewayReleaseExecutionOwner
    {
        public function assertAllowed(?string $sha = null): void
        {
            throw new GatewayReleaseException('owner', 'gateway.release_owner_invalid', 'wrong account');
        }
    });
    expect(Artisan::call('gateway:release:inspect-process', ['commit' => $this->target, 'process' => (string) $this->process->id]))->toBe(1);
    expect(json_decode(Artisan::output(), true)['error_code'])->toBe('gateway.release_owner_invalid');
    expect($this->observer->calls)->toBe([]);
});

it('keeps all writer and queue fences after validating an unrelated Process', function (string $fault): void {
    $this->holdData['unrelated_processes'] = [($this->declaration)()];
    ($this->writeHold)();
    if ($fault === 'reservation') {
        $this->redis->shouldReceive('zcard')->with('queues:tasks:reserved')->andReturn(1);
    } elseif ($fault === 'unknown-unit') {
        $this->observer->writers[] = 'orbit-process-999-unknown.service';
    } elseif ($fault === 'template') {
        $this->observer->unmaskedTemplate = true;
    } else {
        $this->observer->activeWriter = true;
    }
    expect(Artisan::call('gateway:release:stage', ['commit' => $this->target]))->toBe(1);
    expect(json_decode(Artisan::output(), true)['error_code'])->toBe('gateway.release_not_drained');
    expect(GatewayRelease::query()->count())->toBe(0);
    expect($this->observer->properties['MainPID'])->toBe('3945');
})->with(['reservation', 'unknown-unit', 'template', 'active-writer']);

it('refuses malformed declaration collections rather than ignoring them', function (mixed $declarations): void {
    $this->holdData['unrelated_processes'] = $declarations;
    ($this->writeHold)();
    expect(Artisan::call('gateway:release:stage', ['commit' => $this->target]))->toBe(1);
    expect(json_decode(Artisan::output(), true)['error_code'])->toBe('gateway.release_not_drained');
    expect(GatewayRelease::query()->count())->toBe(0);
})->with([
    'null' => [null],
    'wildcard' => ['*'],
    'not a list' => [['process_id' => 1]],
    'not an object' => [['*']],
]);

it('only observes identity and leaves trust and product state to the operator', function (): void {
    $before = $this->process->fresh()->getAttributes();
    $manifest = file_get_contents($this->hold);
    $observation = ($this->declaration)();
    expect($observation['unit'])->toBe($this->unit);
    expect($observation['source_sha'])->toBe($this->source);
    expect($observation['target_sha'])->toBe($this->target);
    expect(file_get_contents($this->hold))->toBe($manifest);
    expect($this->process->fresh()->getAttributes())->toBe($before);
    expect(GatewayRelease::query()->count())->toBe(0);
    expect(is_file($this->pipeline->home.'/gateway-release.paused'))->toBeFalse();
});

final class ProcessDeclarationObservationRunner implements ProcessRunner
{
    public array $writers = ['orbit-gateway-release-run@.service', 'orbit-gateway-release.timer', 'orbit-agent-view.service', 'php8.5-fpm.service'];

    public array $properties = [];

    public array $calls = [];

    public string $ordinaryUnit = '';

    public bool $listed = true;

    public bool $omitProperty = false;

    public bool $truncated = false;

    public bool $failed = false;

    public bool $php = false;

    public bool $activeWriter = false;

    public bool $unmaskedTemplate = false;

    public function run(ProcessInvocation $invocation): CommandResult
    {
        $args = $invocation->arguments;
        $this->calls[] = $args;
        $out = match ($args[1]) {
            'list-unit-files', 'list-units' => implode("\n", array_map(static fn (string $unit): string => $unit.' masked disabled', [...$this->writers, ...($this->listed ? [$this->ordinaryUnit] : [])])),
            'show' => $args[2] === $this->ordinaryUnit
                ? implode("\n", array_map(fn (string $key): string => $key.'='.$this->properties[$key], array_keys($this->omitProperty ? array_diff_key($this->properties, ['ExecStart' => true]) : $this->properties)))
                : "LoadState=masked\nActiveState=inactive\nMainPID=0\nControlGroup=\n",
            '-eo' => getmypid()." php8.5\n3945 sleep\n1 systemd\n".($this->php ? "3946 php8.5\n" : ''),
            default => throw new RuntimeException('Unexpected mutating observation command'),
        };

        if ($this->unmaskedTemplate && $args[1] === 'list-unit-files') {
            $out = str_replace('orbit-gateway-release-run@.service masked', 'orbit-gateway-release-run@.service static', $out);
        }
        if ($this->activeWriter && $args[1] === 'show' && $args[2] !== $this->ordinaryUnit) {
            $out = str_replace('ActiveState=inactive', 'ActiveState=active', $out);
        }

        return new CommandResult($this->failed ? 1 : 0, $out, '', 0, $this->truncated);
    }
}
