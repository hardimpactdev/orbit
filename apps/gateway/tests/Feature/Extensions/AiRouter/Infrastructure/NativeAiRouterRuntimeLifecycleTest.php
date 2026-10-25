<?php

declare(strict_types=1);

use App\Actions\Processes\CreateProcessAction;
use App\Domain\Nodes\ManagedUserAccount;
use App\Domain\Nodes\ManagedUserAccountResolver;
use App\Domain\Processes\DesiredProcessState;
use App\Domain\Processes\ProcessOperationException;
use App\Domain\Processes\ProcessRuntimeManager;
use App\Domain\Processes\ProcessTargetResolver;
use App\Domain\Shared\LifecycleStatus;
use App\Domain\Shared\ResourceOperationException;
use App\Infrastructure\AgentCommands\AgentCommandRunner;
use App\Infrastructure\AgentCommands\AgentDelivery;
use App\Infrastructure\Processes\RemoteProcessRuntimeManager;
use App\Infrastructure\Processes\SystemdProcessRenderer;
use App\Models\Node;
use App\Models\Process;
use Orbit\Extensions\AiRouter\Domain\AiRouterProcess;
use Orbit\Extensions\AiRouter\Infrastructure\NativeAiRouterRuntimeLifecycle;
use Orbit\Extensions\AiRouter\Models\AiRouterProcessSource;
use Tests\Support\FakeAgentCommandRunner;
use Tests\Support\ProcessesApiFakeRuntimeManager;

final class CollectorActivationNode
{
    public bool $interruptRemoval = false;

    public string $unit = '';

    public string $candidate = '';

    public string $state = 'active';
}

/** The fake runner holds only the state needed to test Gateway activation intent. */
function collector_unit_agents(CollectorActivationNode $node): FakeAgentCommandRunner
{
    return new FakeAgentCommandRunner()
        ->answer('ai-router.remove-source', static function () use ($node): array {
            if ($node->interruptRemoval) {
                $node->interruptRemoval = false;
                throw new RuntimeException('response lost after source removal');
            }

            return ['removed' => false];
        })
        ->answer('processes.stage-unit', static function (array $input) use ($node): array {
            if ($node->unit === $input['content']) {
                return ['staged' => false, 'previous' => true, 'state' => $node->state];
            }
            $node->candidate = $input['content'];

            return ['staged' => true, 'previous' => $node->unit !== '', 'state' => null];
        })
        ->answer('processes.activate-unit', static function (array $input) use ($node): array {
            if ($input['change'] !== 'reload') {
                $node->unit = $node->candidate;
            }
            $node->state = $input['desired'] === 'running' ? 'active' : 'inactive';

            return ['backup_left' => false];
        })
        ->answer('processes.status', static fn (): array => ['state' => $node->unit === '' ? 'absent' : $node->state]);
}

beforeEach(function (): void {
    $this->runtime = new ProcessesApiFakeRuntimeManager;
    app()->instance(ProcessRuntimeManager::class, $this->runtime);
    $this->node = Node::query()->create([
        'name' => 'collector', 'status' => LifecycleStatus::Active,
        'platform' => 'linux', 'architecture' => 'x86_64', 'user' => 'orbit', 'wireguard_ip' => '10.44.0.8', 'public_ssh_host' => '192.0.2.8',
    ]);
    $this->environment = ['AI_ROUTER_CACHE_HOST' => '10.44.0.9', 'AI_ROUTER_CACHE_PASSWORD' => 'secret'];
    $this->process = app(CreateProcessAction::class)->execute(AiRouterProcess::data($this->node, $this->environment))['process'];
    $this->source = AiRouterProcessSource::query()->create([
        'process_id' => $this->process->id,
        'source_revision' => hash('sha256', 'previous script'),
    ]);
    $this->remote = new CollectorActivationNode;
    app()->instance(AgentCommandRunner::class, collector_unit_agents($this->remote));
    $this->pin = app(AgentDelivery::class)->pin($this->node);
});

it('recovers source removal with a lost response and leaves completed retries untouched', function (bool $legacy): void {
    if ($legacy) {
        $this->source->source_revision = null;
        $this->source->save();
        $this->process->systemd_activation_hash = null;
        $this->process->save();
    }
    $this->remote->interruptRemoval = true;
    $lifecycle = app(NativeAiRouterRuntimeLifecycle::class);
    expect(fn () => $lifecycle->converge($this->node, $this->environment))->toThrow(RuntimeException::class, 'response lost');
    expect($this->source->refresh()->source_revision)->toBeNull()
        ->and($this->source->refresh()->activation_pending)->toBeTrue()
        ->and($this->runtime->restarted)->toBeEmpty();
    $lifecycle->converge($this->node, $this->environment);
    expect($this->runtime->restarted)->toBeEmpty()
        ->and($this->source->refresh()->source_revision)->toBe($this->pin)
        ->and($this->source->refresh()->activation_pending)->toBeFalse()
        ->and(Process::query()->where('name', AiRouterProcess::NAME)->sole()->id)->toBe($this->process->id);
    $lifecycle->converge($this->node, $this->environment);
    expect($this->runtime->restarted)->toBeEmpty();
})->with([false, true]);

it('retains pending source activation if source removal succeeds but Process convergence is interrupted', function (): void {
    $lifecycle = app(NativeAiRouterRuntimeLifecycle::class);
    $this->runtime->failConverge = true;
    expect(fn () => $lifecycle->converge($this->node, $this->environment))->toThrow(ProcessOperationException::class);
    expect($this->source->refresh()->source_revision)->toBeNull()
        ->and($this->source->refresh()->activation_pending)->toBeTrue();
    $this->runtime->failConverge = false;
    $lifecycle->converge($this->node, $this->environment);
    expect($this->runtime->restarted)->toBeEmpty();
    $lifecycle->converge($this->node, $this->environment);
    expect($this->runtime->restarted)->toBeEmpty();
});

it('retains source activation intent when unit convergence fails and retries it', function (): void {
    $runtime = Mockery::mock(ProcessRuntimeManager::class);
    $runtime->shouldReceive('status')->andReturn('active');
    $runtime->shouldReceive('assertCanStart')->andReturnNull();
    $runtime->shouldReceive('converge')->once()->ordered()->andThrow(new ProcessOperationException('start', 'process.start_failed', 'activation response lost'));
    $runtime->shouldReceive('converge')->twice()->ordered()->andReturnNull();
    $runtime->shouldNotReceive('restart');
    app()->instance(ProcessRuntimeManager::class, $runtime);
    $lifecycle = app(NativeAiRouterRuntimeLifecycle::class);
    expect(fn () => $lifecycle->converge($this->node, $this->environment))->toThrow(ProcessOperationException::class, 'activation response lost');
    expect($this->source->refresh()->source_revision)->toBeNull()
        ->and($this->source->refresh()->activation_pending)->toBeTrue();
    $lifecycle->converge($this->node, $this->environment);
    expect($this->source->refresh()->source_revision)->toBe($this->pin);
    $lifecycle->converge($this->node, $this->environment);
});

it('records a stopped collectors source revision without starting it', function (): void {
    $this->process->update(['desired_state' => 'stopped']);
    $lifecycle = app(NativeAiRouterRuntimeLifecycle::class);
    $lifecycle->converge($this->node, $this->environment);
    $lifecycle->converge($this->node, $this->environment);
    expect($this->process->refresh()->desired_state->value)->toBe('stopped')
        ->and($this->source->refresh()->source_revision)->toBe($this->pin)
        ->and($this->source->refresh()->activation_pending)->toBeFalse()
        ->and($this->runtime->restarted)->toBeEmpty()
        ->and($this->runtime->started)->toBeEmpty();
});

it('leaves an unchanged completed collector untouched', function (string $desired, string $state): void {
    $legacy = false;
    $this->source->source_revision = $legacy ? null : $this->pin;
    $this->source->save();
    $this->process->systemd_activation_hash = null;
    $this->process->desired_state = DesiredProcessState::from($desired);
    $this->process->save();
    if ($legacy) {
        AiRouterProcessSource::query()->whereKey($this->process->id)->delete();
    }
    expect(AiRouterProcessSource::query()->whereKey($this->process->id)->exists())->toBe(! $legacy);
    $account = new ManagedUserAccount('orbit', 'orbit', '/home/orbit');
    $accounts = Mockery::mock(ManagedUserAccountResolver::class);
    $accounts->shouldReceive('resolve')->andReturn($account);
    app()->instance(ManagedUserAccountResolver::class, $accounts);
    $target = new ProcessTargetResolver()->forInstallation($this->process);
    $this->remote->unit = new SystemdProcessRenderer()->render($this->process, $target, $account);
    $this->remote->state = $state;
    $agents = collector_unit_agents($this->remote);
    app()->instance(AgentCommandRunner::class, $agents);
    app()->instance(ProcessRuntimeManager::class, app(RemoteProcessRuntimeManager::class));
    $lifecycle = app(NativeAiRouterRuntimeLifecycle::class);
    $lifecycle->converge($this->node, $this->environment);
    $lifecycle->converge($this->node, $this->environment);
    expect($this->process->refresh()->systemd_activation_hash)->toBeNull()
        ->and($this->process->systemd_activation_pending)->toBeFalse()
        ->and($this->source->refresh()->activation_pending)->toBeFalse()
        ->and($agents->commands())->not->toContain('processes.activate-unit', 'processes.control');
})->with([['running', 'active'], ['stopped', 'inactive']]);

it('activates changed source once through unit convergence and leaves the next converge untouched', function (string $unit): void {
    $accounts = Mockery::mock(ManagedUserAccountResolver::class);
    $account = new ManagedUserAccount('orbit', 'orbit', '/home/orbit');
    $accounts->shouldReceive('resolve')->andReturn($account);
    app()->instance(ManagedUserAccountResolver::class, $accounts);
    $renderer = new SystemdProcessRenderer;
    $target = new ProcessTargetResolver()->forInstallation($this->process);
    $this->remote->unit = match ($unit) {
        'matching' => $renderer->render($this->process, $target, $account),
        'changed' => $renderer->render($this->process, $target, $account)."# previous unit\n",
        'absent' => '',
    };
    $agents = collector_unit_agents($this->remote);
    app()->instance(AgentCommandRunner::class, $agents);
    app()->instance(ProcessRuntimeManager::class, app(RemoteProcessRuntimeManager::class));
    $lifecycle = app(NativeAiRouterRuntimeLifecycle::class);
    $lifecycle->converge($this->node, $this->environment);
    $lifecycle->converge($this->node, $this->environment);
    $starts = array_filter($agents->calls, static fn (array $call): bool => $call['command'] === 'processes.activate-unit' && $call['input']['desired'] === 'running');
    expect($starts)->toHaveCount(1)
        ->and($this->source->refresh()->activation_pending)->toBeFalse()
        ->and($this->process->refresh()->systemd_activation_pending)->toBeFalse();
})->with(['matching', 'changed', 'absent']);

it('removes the owned Process before its source cleanup', function (): void {
    $agents = new FakeAgentCommandRunner()->answer('ai-router.remove-source', function (array $input): array {
        expect($input)->toBe(['retired' => false])
            ->and(Process::query()->whereKey($this->process->id)->exists())->toBeFalse();

        return ['removed' => false];
    });
    app()->instance(AgentCommandRunner::class, $agents);
    app(NativeAiRouterRuntimeLifecycle::class)->remove($this->node);
    expect($agents->commands())->toBe(['ai-router.remove-source']);
});

it('maps native source removal refusals and keeps activation pending', function (): void {
    $agents = new FakeAgentCommandRunner()->refuse('ai-router.remove-source', 'ai-router.source_unsafe');
    app()->instance(AgentCommandRunner::class, $agents);
    expect(fn () => app(NativeAiRouterRuntimeLifecycle::class)->converge($this->node, $this->environment))
        ->toThrow(fn (ResourceOperationException $error) => expect($error->errorCode)->toBe('ai_router.source_removal_failed'));
    expect($this->source->refresh()->activation_pending)->toBeTrue()
        ->and($this->source->source_revision)->toBeNull();
});
