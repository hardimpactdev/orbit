<?php

declare(strict_types=1);

use App\Actions\Processes\CreateProcessAction;
use App\Domain\Nodes\ManagedUserAccount;
use App\Domain\Nodes\ManagedUserAccountResolver;
use App\Domain\Processes\DesiredProcessState;
use App\Domain\Processes\ProcessOperationException;
use App\Domain\Processes\ProcessRuntimeManager;
use App\Domain\Processes\ProcessTargetResolver;
use App\Domain\ProxyCli\ProxyCliProcess;
use App\Domain\Shared\LifecycleStatus;
use App\Infrastructure\Processes\CommandResult;
use App\Infrastructure\Processes\RemoteProcessRuntimeManager;
use App\Infrastructure\Processes\SystemdProcessRenderer;
use App\Infrastructure\ProxyCli\NativeProxyCliRuntimeLifecycle;
use App\Infrastructure\ProxyCli\ProxyCliSourcePublisher;
use App\Infrastructure\Ssh\KnownHostsStore;
use App\Infrastructure\Ssh\RemoteCommand;
use App\Infrastructure\Ssh\SshConnection;
use App\Infrastructure\Ssh\SshExecutor;
use App\Infrastructure\Ssh\SshKeyProvider;
use App\Models\Node;
use App\Models\Process;
use App\Models\ProxyCliProcessSource;
use Tests\Support\ProcessesApiFakeRuntimeManager;

final class CollectorActivationSsh implements SshExecutor
{
    public string $source = 'previous script';

    public bool $interruptPublication = false;

    public string $unit = '';

    public string $state = 'active';

    /** @var list<RemoteCommand> */
    public array $commands = [];

    public function execute(SshConnection $connection, RemoteCommand $command): CommandResult
    {
        $this->commands[] = $command;
        $stdout = '';
        if (in_array('sha256sum', $command->arguments, true)) {
            $stdout = hash('sha256', $this->source).'  /var/lib/orbit/proxycli/server.py';
        } elseif ($command->arguments === ['sudo', 'bash', '-seu']) {
            $script = (string) file_get_contents(resource_path('proxycli/server.py'));
            $stdout = $script === $this->source ? ProxyCliSourcePublisher::Unchanged : ProxyCliSourcePublisher::Published;
            $this->source = $script;
            if ($this->interruptPublication) {
                $this->interruptPublication = false;
                throw new RuntimeException('response lost after publication');
            }
        } elseif (in_array('cat', $command->arguments, true)) {
            $stdout = $this->unit;
        } elseif (in_array('is-active', $command->arguments, true)) {
            $stdout = $this->state;
        }

        return new CommandResult(0, $stdout, '', 0, false);
    }
}

beforeEach(function (): void {
    $this->runtime = new ProcessesApiFakeRuntimeManager;
    app()->instance(ProcessRuntimeManager::class, $this->runtime);
    $this->node = Node::query()->create([
        'name' => 'collector', 'status' => LifecycleStatus::Active,
        'platform' => 'linux', 'user' => 'orbit', 'wireguard_ip' => '10.44.0.8', 'public_ssh_host' => '192.0.2.8',
    ]);
    $this->environment = ['PROXYCLI_CACHE_HOST' => '10.44.0.9', 'PROXYCLI_CACHE_PASSWORD' => 'secret'];
    $this->process = app(CreateProcessAction::class)->execute(ProxyCliProcess::data($this->node, $this->environment))['process'];
    $this->source = ProxyCliProcessSource::query()->create([
        'process_id' => $this->process->id,
        'source_revision' => hash('sha256', 'previous script'),
    ]);
    $keys = Mockery::mock(SshKeyProvider::class);
    $keys->shouldReceive('privateKeyPath')->andReturn('/tmp/test-key');
    app()->instance(SshKeyProvider::class, $keys);
    $hosts = Mockery::mock(KnownHostsStore::class);
    $hosts->shouldReceive('path')->andReturn('/tmp/test-known-hosts');
    app()->instance(KnownHostsStore::class, $hosts);
    $this->ssh = new CollectorActivationSsh;
    app()->instance(SshExecutor::class, $this->ssh);
});

it('recovers source publication with a lost response and leaves completed retries untouched', function (bool $legacy): void {
    if ($legacy) {
        $this->source->source_revision = null;
        $this->source->save();
        $this->process->systemd_activation_hash = null;
        $this->process->save();
    }
    $this->ssh->interruptPublication = true;
    $lifecycle = app(NativeProxyCliRuntimeLifecycle::class);
    expect(fn () => $lifecycle->converge($this->node, $this->environment))->toThrow(RuntimeException::class, 'response lost');
    expect($this->source->refresh()->source_revision)->toBeNull()
        ->and($this->source->refresh()->activation_pending)->toBeTrue()
        ->and($this->runtime->restarted)->toBeEmpty();
    $lifecycle->converge($this->node, $this->environment);
    expect($this->runtime->restarted)->toBe([$this->process->id])
        ->and($this->source->refresh()->source_revision)->toBe(hash('sha256', $this->ssh->source))
        ->and($this->source->refresh()->activation_pending)->toBeFalse()
        ->and(Process::query()->where('name', ProxyCliProcess::NAME)->sole()->id)->toBe($this->process->id);
    $lifecycle->converge($this->node, $this->environment);
    expect($this->runtime->restarted)->toBe([$this->process->id]);
})->with([false, true]);

it('retains pending source activation if publication succeeds but Process convergence is interrupted', function (): void {
    $lifecycle = app(NativeProxyCliRuntimeLifecycle::class);
    $this->runtime->failConverge = true;
    expect(fn () => $lifecycle->converge($this->node, $this->environment))->toThrow(ProcessOperationException::class);
    expect($this->source->refresh()->source_revision)->toBeNull()
        ->and($this->source->refresh()->activation_pending)->toBeTrue();
    $this->runtime->failConverge = false;
    $lifecycle->converge($this->node, $this->environment);
    expect($this->runtime->restarted)->toBe([$this->process->id]);
    $lifecycle->converge($this->node, $this->environment);
    expect($this->runtime->restarted)->toBe([$this->process->id]);
});

it('retains source activation intent when restart fails and retries it', function (): void {
    $runtime = Mockery::mock(ProcessRuntimeManager::class);
    $runtime->shouldReceive('status')->andReturn('active');
    $runtime->shouldReceive('assertCanStart')->andReturnNull();
    $runtime->shouldReceive('converge')->andReturnNull();
    $runtime->shouldReceive('restart')->once()->ordered()->andThrow(new ProcessOperationException('restart', 'process.restart_failed', 'restart response lost'));
    $runtime->shouldReceive('restart')->once()->ordered()->andReturnNull();
    app()->instance(ProcessRuntimeManager::class, $runtime);
    $lifecycle = app(NativeProxyCliRuntimeLifecycle::class);
    expect(fn () => $lifecycle->converge($this->node, $this->environment))->toThrow(ProcessOperationException::class, 'restart response lost');
    expect($this->source->refresh()->source_revision)->toBeNull()
        ->and($this->source->refresh()->activation_pending)->toBeTrue();
    $lifecycle->converge($this->node, $this->environment);
    expect($this->source->refresh()->source_revision)->toBe(hash('sha256', $this->ssh->source));
    $lifecycle->converge($this->node, $this->environment);
});

it('records a stopped collectors source revision without starting it', function (): void {
    $this->process->update(['desired_state' => 'stopped']);
    $lifecycle = app(NativeProxyCliRuntimeLifecycle::class);
    $lifecycle->converge($this->node, $this->environment);
    $lifecycle->converge($this->node, $this->environment);
    expect($this->process->refresh()->desired_state->value)->toBe('stopped')
        ->and($this->source->refresh()->source_revision)->toBe(hash('sha256', $this->ssh->source))
        ->and($this->source->refresh()->activation_pending)->toBeFalse()
        ->and($this->runtime->restarted)->toBeEmpty()
        ->and($this->runtime->started)->toBeEmpty();
});

it('leaves an unchanged migrated collector untouched on upgrade', function (string $desired, string $state, bool $legacy): void {
    $this->source->source_revision = $legacy ? null : hash_file('sha256', resource_path('proxycli/server.py'));
    $this->source->save();
    $this->process->systemd_activation_hash = null;
    $this->process->desired_state = DesiredProcessState::from($desired);
    $this->process->save();
    $migration = require database_path('migrations/2026_10_16_074137_move_proxycli_process_sources_to_extension_table.php');
    $migration->down();
    $migration->up();
    $account = new ManagedUserAccount('orbit', 'orbit', '/home/orbit');
    $accounts = Mockery::mock(ManagedUserAccountResolver::class);
    $accounts->shouldReceive('resolve')->andReturn($account);
    app()->instance(ManagedUserAccountResolver::class, $accounts);
    $target = new ProcessTargetResolver()->forInstallation($this->process);
    $this->ssh->unit = new SystemdProcessRenderer()->render($this->process, $target, $account);
    $this->ssh->source = (string) file_get_contents(resource_path('proxycli/server.py'));
    $this->ssh->state = $state;
    app()->instance(ProcessRuntimeManager::class, app(RemoteProcessRuntimeManager::class));
    $lifecycle = app(NativeProxyCliRuntimeLifecycle::class);
    $lifecycle->converge($this->node, $this->environment);
    $lifecycle->converge($this->node, $this->environment);
    expect($this->process->refresh()->systemd_activation_hash)->toBeNull()
        ->and($this->process->systemd_activation_pending)->toBeFalse()
        ->and($this->source->refresh()->activation_pending)->toBeFalse();
    foreach ($this->ssh->commands as $command) {
        expect(array_intersect($command->arguments, ['restart', 'daemon-reload', 'mv', 'install', 'disable', 'stop', 'start']))->toBeEmpty();
    }
})->with([['running', 'active', true], ['stopped', 'inactive', true], ['running', 'active', false], ['stopped', 'inactive', false]]);
