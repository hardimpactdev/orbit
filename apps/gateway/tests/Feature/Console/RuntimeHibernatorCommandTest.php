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
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Queue;

uses(LazilyRefreshDatabase::class);
require_once __DIR__.'/../Domain/Instances/ContainerInstanceHelpers.php';

/** One clone whose access log is 200 s old and whose T3 is idle: asleep under a 120 s window, awake under the hour. */
beforeEach(function (): void {
    Queue::fake();
    $this->travelTo(CarbonImmutable::parse('2026-11-14 12:00:00'));
    $node = containerDefaultNode();
    $project = containerDefaultProject(ProjectType::LaravelApp, '8.5');
    $default = Instance::query()->create(['node_id' => $node->id, 'project_id' => $project->id, 'name' => 'default',
        'checkout_path' => '/workspace/default', 'root' => 'public', 'status' => 'active', 'first_setup_pending' => false, 'source_is_laravel' => true]);
    app(InstanceContainer::class)->reserve($default, $node)->update(['power_state' => 'running', 'seed_commit' => str_repeat('a', 40)]);
    $this->clone = Instance::query()->forceCreate(['node_id' => $node->id, 'project_id' => $project->id, 'name' => 'clone',
        'checkout_path' => '/workspace/clone', 'root' => 'public', 'status' => 'active', 'first_setup_pending' => false, 'source_is_laravel' => true]);
    app(InstanceContainer::class)->reserve($this->clone, $node, $default)->update(['power_state' => 'running', 'idle_watch_started_at' => now()->subHours(2)]);
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

it('prints the value in force and each row\'s decision under every value in a dry run, changes nothing, and skips the checkout sweep', function (mixed $value, string $setting, int $sleeps): void {
    config(['orbit.hibernation.container_sleep' => $value]);
    $default = Instance::query()->where('name', 'default')->sole();

    $this->artisan('orbit:runtime-hibernator --dry-run')->assertSuccessful()
        ->expectsOutputToContain("ORBIT_HIBERNATION_CONTAINER_SLEEP is [{$setting}].")
        ->expectsTable(['Instance', 'Name', 'false', 'without_t3', 'true', 'Watched since', 'Last HTTP', 'Last T3', 'Last wake', 'Last deploy'], [
            [$default->id, 'default', 'skip: off', 'skip: watch_starting', 'skip: watch_starting', '-', '-', 'no T3 server', '-', '-'],
            [$this->clone->id, 'clone', 'skip: off', 'skip: t3_server', 'sleep', '2026-11-14T10:00:00Z', '2026-11-14T11:56:40Z', '-', '-', '-'],
        ])
        ->expectsOutputToContain("Would put [{$sleeps}] idle development containers to sleep.")
        ->doesntExpectOutputToContain('Halted');

    expect(sleepsAdmitted())->toBe(0)
        ->and(Instance::query()->where('name', 'default')->sole()->runtime()->firstOrFail()->idle_watch_started_at)->toBeNull();
})->with([[false, 'false', 0], ['without_t3', 'without_t3', 0], [true, 'true', 1]]);

it('puts only Instances without a T3 server to sleep under without_t3', function (): void {
    config(['orbit.hibernation.container_sleep' => 'without_t3']);
    $default = Instance::query()->where('name', 'default')->sole();
    $default->runtime()->update(['idle_watch_started_at' => now()->subHours(2)]);

    $this->artisan('orbit:runtime-hibernator')->assertSuccessful()
        ->expectsOutputToContain('Put [1] idle development containers to sleep.');

    expect(Operation::query()->where('kind', InstanceSleep::Kind)->sole()->target_id)->toBe($default->id);
});

it('fails each run on a value outside the setting, names it, and puts nothing to sleep', function (bool $dryRun): void {
    config(['orbit.hibernation.container_sleep' => 'sometimes']);

    $this->artisan('orbit:runtime-hibernator'.($dryRun ? ' --dry-run' : ''))->assertFailed()
        ->expectsOutputToContain('ORBIT_HIBERNATION_CONTAINER_SLEEP must be false, without_t3 or true, not [sometimes].');

    expect($this->reads)->toBe(0)->and(sleepsAdmitted())->toBe(0);
})->with(['acting' => [false], 'dry run' => [true]]);

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
