<?php

declare(strict_types=1);

use App\Actions\Instances\RenameInstanceAction;
use App\Data\Instances\RenameInstanceData;
use App\Domain\AgentView\AgentNodeView;
use App\Domain\AgentView\AgentStateView;
use App\Domain\AgentView\AgentViewFreshness;
use App\Domain\AgentView\AgentViewSubscriberHealth;
use App\Domain\Instances\InstanceState;
use App\Domain\Nodes\RoleName;
use App\Domain\Shared\ResourceOperationException;
use App\Models\Instance;
use App\Models\Node;
use App\Models\NodeFact;
use App\Models\Project;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Queue;

uses(LazilyRefreshDatabase::class);

/** Rename records the branch that the Node agent reports for the Instance's checkout or linked worktree. */
beforeEach(function (): void {
    Queue::fake();
    $this->freezeTime();
    $this->node = Node::withoutEvents(fn (): Node => Node::query()->create(['name' => 'rename-node', 'status' => 'active', 'platform' => 'linux',
        'architecture' => 'x86_64', 'wireguard_ip' => '10.44.0.9', 'public_ssh_host' => '10.44.0.9', 'user' => 'orbit',
        'ssh_host_key_type' => 'ssh-ed25519', 'ssh_host_key' => 'fixture-public-key', 'ssh_host_fingerprint' => 'fixture']));
    $this->node->roles()->create(['role' => RoleName::AppDev, 'status' => 'active']);
    $this->project = Project::query()->create(['name' => 'Rename fixture', 'slug' => 'rename-fixture', 'type' => 'node-package',
        'repository_url' => 'https://github.com/example/fixture.git', 'default_branch' => 'main', 'root' => '.']);
    $this->instance = fn (string $layout): Instance => Instance::query()->create(['project_id' => $this->project->id,
        'node_id' => $this->node->id, 'name' => 'ps-server', 'checkout_path' => '/home/orbit/apps/rename-fixture/ps-server',
        'status' => InstanceState::Active, 'source_layout' => $layout, 'branch' => 'powersync/server',
        'branch_override' => 'powersync/server', 'starting_commit' => str_repeat('a', 40)]);
    // The agent's latest report of the checkout: a linked worktree of the default checkout, on another branch than recorded.
    $this->report = function (Instance $instance, string $branch, bool $linked): void {
        $path = $instance->checkout_path;
        NodeFact::query()->updateOrCreate(['node_id' => $this->node->id, 'kind' => 'checkout', 'subject' => $path], [
            'digest' => str_repeat('a', 64), 'observed_at' => now(), 'received_at' => now(),
            'data' => ['application' => $path, 'top_level' => $path,
                'common_dir' => $linked ? '/home/orbit/apps/rename-fixture/default/.git' : $path.'/.git', 'linked' => $linked,
                'worktrees' => [$path], 'origin' => 'https://github.com/example/fixture.git', 'owner' => 'orbit', 'head' => str_repeat('c', 40),
                'branch' => $branch, 'default_branch' => 'main', 'artisan' => 'absent', 'composer' => null, 'env' => null]]);
    };
    $view = Mockery::mock(AgentStateView::class);
    $clock = (float) now()->format('U.u');
    $view->shouldReceive('subscriber')->andReturn(new AgentViewSubscriberHealth($clock, true, true, 1));
    $view->shouldReceive('node')->andReturn(new AgentNodeView($this->node->id, AgentViewFreshness::Fresh, receivedAt: $clock));
    $view->shouldReceive('lastSeen')->andReturn(null);
    app()->instance(AgentStateView::class, $view);
    $this->rename = fn (Instance $instance, string $branch): Instance => app(RenameInstanceAction::class)
        ->execute($instance->fresh(), new RenameInstanceData($branch));
});

function renameRefusal(Closure $call): string
{
    try {
        $call();
    } catch (ResourceOperationException $exception) {
        return $exception->errorCode;
    }
    test()->fail('Expected a refusal.');
}

it('records the branch a linked worktree has checked out', function (): void {
    $instance = ($this->instance)('worktree');
    ($this->report)($instance, 'fix/date-independent-powersync-tests', true);

    $renamed = ($this->rename)($instance, 'fix/date-independent-powersync-tests');

    expect($renamed->only(['branch', 'branch_override', 'name', 'source_layout', 'checkout_path', 'starting_commit']))->toBe([
        'branch' => 'fix/date-independent-powersync-tests', 'branch_override' => 'fix/date-independent-powersync-tests',
        'name' => 'ps-server', 'source_layout' => 'worktree', 'checkout_path' => '/home/orbit/apps/rename-fixture/ps-server',
        'starting_commit' => str_repeat('a', 40),
    ]);
});

it('refuses a branch the linked worktree has not checked out and keeps its record', function (): void {
    $instance = ($this->instance)('worktree');
    ($this->report)($instance, 'fix/date-independent-powersync-tests', true);

    expect(renameRefusal(fn () => ($this->rename)($instance, 'restrict-production-websocket-csp')))->toBe('instance.branch_not_checked_out')
        ->and($instance->fresh()->only(['branch', 'branch_override']))->toBe(['branch' => 'powersync/server', 'branch_override' => 'powersync/server']);
});

it('records the branch of a checkout as before', function (): void {
    $instance = ($this->instance)('checkout');
    ($this->report)($instance, 'feature', false);

    expect(renameRefusal(fn () => ($this->rename)($instance, 'other')))->toBe('instance.branch_not_checked_out')
        ->and(($this->rename)($instance, 'feature')->only(['branch', 'branch_override']))->toBe(['branch' => 'feature', 'branch_override' => 'feature']);
});

it('still refuses an Instance outside development', function (): void {
    $instance = ($this->instance)('worktree');
    ($this->report)($instance, 'feature', true);
    $this->node->roles()->update(['role' => RoleName::AppProd]);

    expect(renameRefusal(fn () => ($this->rename)($instance, 'feature')))->toBe('instance.rename_unsupported')
        ->and($instance->fresh()->branch)->toBe('powersync/server');
});
