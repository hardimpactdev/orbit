<?php

declare(strict_types=1);

use App\Actions\Processes\CreateProcessAction;
use App\Domain\Processes\ProcessOperationException;
use App\Domain\Processes\ProcessRuntimeManager;
use App\Domain\ProxyCli\ProxyCliProcess;
use App\Domain\Shared\LifecycleStatus;
use App\Infrastructure\Processes\CommandResult;
use App\Infrastructure\ProxyCli\NativeProxyCliRuntimeLifecycle;
use App\Infrastructure\ProxyCli\ProxyCliSourcePublisher;
use App\Infrastructure\Ssh\KnownHostsStore;
use App\Infrastructure\Ssh\SshExecutor;
use App\Infrastructure\Ssh\SshKeyProvider;
use App\Models\Node;
use App\Models\Process;
use Tests\Support\ProcessesApiFakeRuntimeManager;

beforeEach(function (): void {
    $this->runtime = new ProcessesApiFakeRuntimeManager;
    app()->instance(ProcessRuntimeManager::class, $this->runtime);
    $this->node = Node::query()->create([
        'name' => 'collector', 'status' => LifecycleStatus::Active,
        'platform' => 'linux', 'user' => 'orbit', 'wireguard_ip' => '10.44.0.8', 'public_ssh_host' => '192.0.2.8',
    ]);
    $this->environment = ['PROXYCLI_CACHE_HOST' => '10.44.0.9', 'PROXYCLI_CACHE_PASSWORD' => 'secret'];
    $this->process = app(CreateProcessAction::class)->execute(ProxyCliProcess::data($this->node, $this->environment))['process'];
    $this->process->proxycli_source_revision = hash('sha256', 'previous script');
    $this->process->save();
    $keys = Mockery::mock(SshKeyProvider::class);
    $keys->shouldReceive('privateKeyPath')->andReturn('/tmp/test-key');
    app()->instance(SshKeyProvider::class, $keys);
    $hosts = Mockery::mock(KnownHostsStore::class);
    $hosts->shouldReceive('path')->andReturn('/tmp/test-known-hosts');
    app()->instance(KnownHostsStore::class, $hosts);
});

it('recovers source publication with a lost response and leaves completed retries untouched', function (): void {
    $ssh = Mockery::mock(SshExecutor::class);
    $ssh->shouldReceive('execute')->once()->andThrow(new RuntimeException('response lost after publication'));
    $ssh->shouldReceive('execute')->twice()->andReturn(new CommandResult(0, ProxyCliSourcePublisher::Unchanged, '', 0, false));
    app()->instance(SshExecutor::class, $ssh);
    $lifecycle = app(NativeProxyCliRuntimeLifecycle::class);
    expect(fn () => $lifecycle->converge($this->node, $this->environment))->toThrow(RuntimeException::class, 'response lost');
    expect($this->process->refresh()->proxycli_source_revision)->toBeNull()
        ->and($this->runtime->restarted)->toBeEmpty();
    $lifecycle->converge($this->node, $this->environment);
    expect($this->runtime->restarted)->toBe([$this->process->id])
        ->and($this->process->refresh()->proxycli_source_revision)->toBe(hash('sha256', (string) file_get_contents(resource_path('proxycli/server.py'))))
        ->and(Process::query()->where('name', ProxyCliProcess::NAME)->sole()->id)->toBe($this->process->id);
    $lifecycle->converge($this->node, $this->environment);
    expect($this->runtime->restarted)->toBe([$this->process->id]);
});

it('retains pending source activation if publication succeeds but Process convergence is interrupted', function (): void {
    $ssh = Mockery::mock(SshExecutor::class);
    $ssh->shouldReceive('execute')->once()->andReturn(new CommandResult(0, ProxyCliSourcePublisher::Published, '', 0, false));
    $ssh->shouldReceive('execute')->twice()->andReturn(new CommandResult(0, ProxyCliSourcePublisher::Unchanged, '', 0, false));
    app()->instance(SshExecutor::class, $ssh);
    $lifecycle = app(NativeProxyCliRuntimeLifecycle::class);
    $this->runtime->failConverge = true;
    expect(fn () => $lifecycle->converge($this->node, $this->environment))->toThrow(ProcessOperationException::class);
    expect($this->process->refresh()->proxycli_source_revision)->toBeNull();
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
    $ssh = Mockery::mock(SshExecutor::class);
    $ssh->shouldReceive('execute')->times(3)->andReturn(new CommandResult(0, ProxyCliSourcePublisher::Unchanged, '', 0, false));
    app()->instance(SshExecutor::class, $ssh);
    $lifecycle = app(NativeProxyCliRuntimeLifecycle::class);
    expect(fn () => $lifecycle->converge($this->node, $this->environment))->toThrow(ProcessOperationException::class, 'restart response lost');
    expect($this->process->refresh()->proxycli_source_revision)->toBeNull();
    $lifecycle->converge($this->node, $this->environment);
    expect($this->process->refresh()->proxycli_source_revision)->toBe(hash('sha256', (string) file_get_contents(resource_path('proxycli/server.py'))));
    $lifecycle->converge($this->node, $this->environment);
});

it('records a stopped collectors source revision without starting it', function (): void {
    $this->process->update(['desired_state' => 'stopped']);
    $ssh = Mockery::mock(SshExecutor::class);
    $ssh->shouldReceive('execute')->twice()->andReturn(new CommandResult(0, ProxyCliSourcePublisher::Unchanged, '', 0, false));
    app()->instance(SshExecutor::class, $ssh);
    $lifecycle = app(NativeProxyCliRuntimeLifecycle::class);
    $lifecycle->converge($this->node, $this->environment);
    $lifecycle->converge($this->node, $this->environment);
    expect($this->process->refresh()->desired_state->value)->toBe('stopped')
        ->and($this->process->proxycli_source_revision)->toBe(hash('sha256', (string) file_get_contents(resource_path('proxycli/server.py'))))
        ->and($this->runtime->restarted)->toBeEmpty()
        ->and($this->runtime->started)->toBeEmpty();
});
