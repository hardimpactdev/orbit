<?php

declare(strict_types=1);

use App\Domain\Hibernation\RuntimeHibernation;
use App\Domain\Instances\InstanceContainer;
use App\Domain\Instances\Power\AgentActivity;
use App\Domain\Instances\Power\ContainerIdleSweep;
use App\Domain\Instances\Power\InstanceAgentActivity;
use App\Domain\Instances\Power\InstanceSleep;
use App\Domain\Instances\Power\NodeFileTimes;
use App\Domain\Projects\ProjectType;
use App\Models\Instance;
use App\Models\Node;
use App\Models\Operation;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Queue;

uses(LazilyRefreshDatabase::class);
require_once __DIR__.'/../Domain/Instances/ContainerInstanceHelpers.php';

/** One clone whose access log is 200 s old and whose T3 is idle: asleep under a 120 s window, awake under the hour. */
beforeEach(function (): void {
    Queue::fake();
    $node = containerDefaultNode();
    $project = containerDefaultProject(ProjectType::LaravelApp, '8.5');
    $default = Instance::query()->create(['node_id' => $node->id, 'project_id' => $project->id, 'name' => 'default',
        'checkout_path' => '/workspace/default', 'root' => 'public', 'status' => 'active', 'first_setup_pending' => false, 'source_is_laravel' => true]);
    app(InstanceContainer::class)->reserve($default, $node)->update(['power_state' => 'running', 'seed_commit' => str_repeat('a', 40)]);
    $this->clone = Instance::query()->forceCreate(['node_id' => $node->id, 'project_id' => $project->id, 'name' => 'clone',
        'checkout_path' => '/workspace/clone', 'root' => 'public', 'status' => 'active', 'first_setup_pending' => false, 'source_is_laravel' => true]);
    app(InstanceContainer::class)->reserve($this->clone, $node, $default)->update(['power_state' => 'running']);
    $this->reads = 0;
    $log = RuntimeHibernation::accessLogPath(RuntimeHibernation::key($this->clone->id));
    $files = new class($this, $log) implements NodeFileTimes
    {
        public function __construct(private object $test, private string $log) {}

        public function read(Node $node, array $paths): ?array
        {
            $this->test->reads++;

            return array_combine($paths, array_map(fn (string $path): ?int => $path === $this->log ? now()->getTimestamp() - 200 : null, $paths));
        }
    };
    $agent = new class implements InstanceAgentActivity
    {
        public function read(Instance $instance): ?AgentActivity
        {
            return AgentActivity::read(false, null);
        }
    };
    app()->bind(ContainerIdleSweep::class, fn (): ContainerIdleSweep => new ContainerIdleSweep($files, app(InstanceSleep::class), [$agent], 120));
});

function sleepsAdmitted(): int
{
    return Operation::query()->where('kind', InstanceSleep::Kind)->count();
}

it('runs only the checkout sweep while the container idle sleep is off', function (): void {
    config(['orbit.hibernation.container_sleep' => false]);

    $this->artisan('orbit:runtime-hibernator')->assertSuccessful()
        ->expectsOutputToContain('Halted [0] idle app-dev Instance runtime groups.')
        ->doesntExpectOutputToContain('development containers');

    expect($this->reads)->toBe(0)->and(sleepsAdmitted())->toBe(0);
});

it('runs both sweeps once the operator turned the container idle sleep on', function (): void {
    config(['orbit.hibernation.container_sleep' => true]);

    $this->artisan('orbit:runtime-hibernator')->assertSuccessful()
        ->expectsOutputToContain('Halted [0] idle app-dev Instance runtime groups.')
        ->expectsOutputToContain('Put [1] idle development containers to sleep.');

    expect(sleepsAdmitted())->toBe(1);
});

it('lists every decision in a dry run, changes nothing, and skips the checkout sweep, whatever the switch', function (bool $on): void {
    config(['orbit.hibernation.container_sleep' => $on]);
    $default = Instance::query()->where('name', 'default')->sole();

    $this->artisan('orbit:runtime-hibernator --dry-run')->assertSuccessful()
        ->expectsTable(['Instance', 'Name', 'Decision', 'Reason', 'Operation'], [
            [$default->id, 'default', 'skip', 'default', ''], [$this->clone->id, 'clone', 'sleep', 'idle', ''],
        ])
        ->expectsOutputToContain('Would put [1] idle development containers to sleep.')
        ->doesntExpectOutputToContain('Halted');

    expect(sleepsAdmitted())->toBe(0);
})->with([false, true]);

it('acts on named Instances with the window of the run while the switch is off, and leaves the checkout sweep alone', function (?string $idle, int $sleeps): void {
    config(['orbit.hibernation.container_sleep' => false]);

    $this->artisan('orbit:runtime-hibernator --instance='.$this->clone->id.($idle === null ? '' : ' --idle-seconds='.$idle))->assertSuccessful()
        ->doesntExpectOutputToContain('Halted');

    expect(sleepsAdmitted())->toBe($sleeps);
})->with(['the run window' => ['180', 1], 'a window longer than the log age' => ['600', 0]]);

it('refuses a window of its own without named Instances or below a minute', function (string $options): void {
    $this->artisan('orbit:runtime-hibernator '.$options)->assertExitCode(2);

    expect($this->reads)->toBe(0)->and(sleepsAdmitted())->toBe(0);
})->with(['--idle-seconds=120', '--instance=7 --idle-seconds=0', '--instance=7 --idle-seconds=59', '--instance=7 --idle-seconds=abc']);
