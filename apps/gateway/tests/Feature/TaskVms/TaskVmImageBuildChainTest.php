<?php

declare(strict_types=1);

use App\Domain\Shared\LifecycleStatus;
use App\Domain\TaskVms\TaskVmException;
use App\Domain\TaskVms\TaskVmHost;
use App\Domain\TaskVms\TaskVmImageBuildState;
use App\Domain\TaskVms\TaskVmImageBuildStep;
use App\Infrastructure\Ssh\HostKey;
use App\Infrastructure\Ssh\KnownHostsStore;
use App\Infrastructure\Ssh\RemoteCommand;
use App\Infrastructure\Ssh\SshExecutor;
use App\Infrastructure\Ssh\SshKeyProvider;
use App\Infrastructure\TaskVms\IncusTaskVmBaseImage;
use App\Infrastructure\TaskVms\TaskVmImageBuildChain;
use App\Infrastructure\TaskVms\TaskVmImageBuildSteps;
use App\Jobs\TaskVms\ImageBuild\AwaitImageBuilderBoot;
use App\Jobs\TaskVms\ImageBuild\CleanupImageBuild;
use App\Jobs\TaskVms\ImageBuild\ImageBuildJob;
use App\Jobs\TaskVms\ImageBuild\LaunchImageBuilder;
use App\Jobs\TaskVms\ImageBuild\PromoteImage;
use App\Jobs\TaskVms\ImageBuild\SmokeImage;
use App\Jobs\TaskVms\ImageBuild\UpgradeImagePackages;
use App\Jobs\TaskVms\RefreshTaskVmBaseImages;
use App\Jobs\TaskVms\RefreshTaskVmWorkspaceTemplates;
use App\Jobs\TaskVms\SweepTaskVmHosts;
use App\Models\Node;
use App\Models\TaskVmImageBuild;
use Illuminate\Console\Scheduling\CallbackEvent;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Queue\Job;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Queue\Events\JobTimedOut;
use Illuminate\Queue\TimeoutExceededException;
use Illuminate\Queue\Worker;
use Illuminate\Queue\WorkerOptions;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Sleep;
use Tests\Support\TaskVmSettingsBuilder;

require_once __DIR__.'/../../Support/FakeIncusImageHost.php';

beforeEach(function (): void {
    $this->dispatcher = Bus::getFacadeRoot();
    Bus::fake();
    Sleep::fake();
    $this->travelTo('2026-10-12 03:00:00');
    $this->host = Node::query()->create(['name' => 'beast', 'status' => LifecycleStatus::Active, 'platform' => 'linux', 'architecture' => 'x86_64', 'public_ssh_host' => '192.0.2.7', 'wireguard_ip' => '10.44.0.7', 'user' => 'orbit']);
    $this->settings = TaskVmSettingsBuilder::bind(['hosts' => [TaskVmSettingsBuilder::host($this->host->id)]]);
    $this->incus = new FakeIncusImageHost;
    $this->incus->images = [IMAGE_STOCK => [TaskVmHost::SourceImage], IMAGE_OLD => [TaskVmHost::BaseImage]];
    app()->instance(SshExecutor::class, $this->incus);
    app()->instance(SshKeyProvider::class, new class implements SshKeyProvider
    {
        public function privateKeyPath(): string
        {
            return '/keys/id_ed25519';
        }

        public function publicKey(): string
        {
            return IMAGE_KEY;
        }
    });
    app()->instance(KnownHostsStore::class, new class implements KnownHostsStore
    {
        public function path(): string
        {
            return '/keys/known_hosts';
        }

        public function put(string $host, int $port, HostKey $key): void {}
    });
    $this->chain = app(TaskVmImageBuildChain::class);
    $this->runBuild = function (TaskVmImageBuild $build, bool $resume = false): void {
        $build->refresh();
        $jobs = $this->chain->jobs($build);
        if ($resume) {
            while ($jobs[0]->step() !== $build->current_step || $jobs[0]->program() !== $build->current_program) {
                array_shift($jobs);
            }
        }
        foreach ($jobs as $job) {
            // A queue worker executes the released polling job again, without advancing the chain.
            for ($poll = 0; $poll < 3; $poll++) {
                $job->handle(app(TaskVmImageBuildSteps::class));
                $build->refresh();
                $entry = array_find($build->steps, fn (array $entry): bool => $entry['step'] === $job->step()->value && $entry['program'] === $job->program());
                if ($entry['seconds'] !== null) {
                    break;
                }
            }
        }
    };
});

it('refuses start and resume while a sync already owns the target without host work or row changes', function (string $operation): void {
    $failed = $operation === 'resume' ? TaskVmImageBuild::query()->create([
        'host_node_id' => $this->host->id, 'state' => TaskVmImageBuildState::Failed,
        'current_step' => TaskVmImageBuildStep::UpgradePackages,
    ]) : null;
    if ($failed !== null) {
        $this->incus->instances[$failed->builder_vm_name] = IMAGE_OLD;
    }
    $lock = Cache::lock('task-vms:image-sync:'.$this->host->id, 1500);
    expect($lock->get())->toBeTrue();
    try {
        expect(fn () => $operation === 'start' ? $this->chain->start($this->host) : $this->chain->resume($this->host, $failed->id))
            ->toThrow(TaskVmException::class, 'A base image sync or build admission of Node [beast] is already running.');
        expect($this->incus->commands)->toBe([]);
        expect(TaskVmImageBuild::query()->where('state', TaskVmImageBuildState::Running)->exists())->toBeFalse();
        if ($failed !== null) {
            expect($failed->refresh()->submission_pending_at)->toBeNull();
        }
        Bus::assertNothingDispatched();
    } finally {
        $lock->release();
    }
    $build = $operation === 'start' ? $this->chain->start($this->host) : $this->chain->resume($this->host, $failed->id);
    expect($build->state)->toBe(TaskVmImageBuildState::Running);
})->with(['start', 'resume']);

it('holds sync admission until the caller commits or rolls back, never across queued steps', function (bool $rollback): void {
    $lock = Cache::lock('task-vms:image-sync:'.$this->host->id, 1500);
    $admit = function () use ($lock, $rollback): void {
        $this->chain->start($this->host);
        expect($lock->get())->toBeFalse();
        Bus::assertNothingDispatched();
        if ($rollback) {
            throw new RuntimeException('caller rollback');
        }
    };
    if ($rollback) {
        expect(fn () => DB::transaction($admit))->toThrow(RuntimeException::class, 'caller rollback');
        expect(TaskVmImageBuild::query()->count())->toBe(0);
        Bus::assertNothingDispatched();
    } else {
        DB::transaction($admit);
        expect(TaskVmImageBuild::query()->sole()->state)->toBe(TaskVmImageBuildState::Running);
    }
    expect($lock->get())->toBeTrue();
    $lock->release();
})->with([false, true]);

it('rechecks sync admission before enqueue if the caller outlives the admission lease', function (string $operation): void {
    $failed = $operation === 'resume' ? TaskVmImageBuild::query()->create([
        'host_node_id' => $this->host->id, 'state' => TaskVmImageBuildState::Failed,
        'current_step' => TaskVmImageBuildStep::UpgradePackages,
    ]) : null;
    if ($failed !== null) {
        $this->incus->instances[$failed->builder_vm_name] = IMAGE_OLD;
    }
    $sync = Cache::lock('task-vms:image-sync:'.$this->host->id, 1500, 'later-sync');
    $this->commandsBeforeCommit = [];
    try {
        expect(fn () => DB::transaction(function () use ($operation, $failed, $sync): void {
            $operation === 'start' ? $this->chain->start($this->host) : $this->chain->resume($this->host, $failed->id);
            $this->commandsBeforeCommit = $this->incus->commands;
            // Simulate lease expiry and a sync that passed its check on another connection,
            // where this transaction's running row is not yet visible.
            Cache::lock('task-vms:image-sync:'.$this->host->id)->forceRelease();
            expect($sync->get())->toBeTrue();
        }))->toThrow(TaskVmException::class, 'sync or build admission');
        expect($this->incus->commands)->toBe($this->commandsBeforeCommit);
        $build = TaskVmImageBuild::query()->sole();
        expect($build->state)->toBe(TaskVmImageBuildState::Failed)->and($build->submission_pending_at)->toBeNull();
        expect(Cache::lock('task-vms:image-sync:'.$this->host->id, 1500)->get())->toBeFalse();
        Bus::assertNothingDispatched();
    } finally {
        $sync->release();
    }
})->with(['start', 'resume']);

it('releases sync admission after a rejected row write', function (): void {
    $this->chain->start($this->host);
    expect(fn () => $this->chain->start($this->host))->toThrow(TaskVmException::class, 'already running');
    $lock = Cache::lock('task-vms:image-sync:'.$this->host->id, 1500);
    expect($lock->get())->toBeTrue();
    $lock->release();
});

it('refuses a Node that is not a task VM host before queuing a build', function (): void {
    Node::query()->create(['name' => 'shark', 'status' => LifecycleStatus::Active, 'platform' => 'linux', 'public_ssh_host' => '192.0.2.8', 'wireguard_ip' => '10.44.0.11', 'user' => 'nckrtl']);

    $this->artisan('task-vms:build-image', ['node' => 'shark'])
        ->expectsOutputToContain('[task_vm.unknown_host]')
        ->assertFailed();
    expect($this->incus->commands)->toBe([]);
    Bus::assertNothingDispatched();
});

it('queues the nightly chain dispatcher on builds at 03:00 only while task VMs are enabled', function (): void {
    Artisan::call('schedule:list');
    $refresh = collect(app(Schedule::class)->events())->first(static fn ($event): bool => $event instanceof CallbackEvent && $event->description === RefreshTaskVmBaseImages::class);

    config(['task_vms.enabled' => false]);
    expect($refresh)->not->toBeNull()
        ->and($refresh->expression)->toBe('0 3 * * *')
        ->and($refresh->filtersPass(app()))->toBeFalse()
        ->and([new RefreshTaskVmBaseImages()->connection, new RefreshTaskVmBaseImages()->queue])->toBe(['gateway', 'builds']);

    config(['task_vms.enabled' => true]);
    expect($refresh->filtersPass(app()))->toBeTrue();
});

it('dispatches the ordered chain on builds with one cleanup catch and persists all timings', function (): void {
    $build = $this->chain->start($this->host);
    $first = Bus::dispatched(LaunchImageBuilder::class)->sole();
    $jobs = [$first, ...array_map(unserialize(...), $first->chained)];
    expect($first->chainQueue)->toBe('builds')->and($first->connection)->toBe('gateway')
        ->and($first->chainCatchCallbacks)->toHaveCount(1)
        ->and(array_map(fn ($job) => $job->step()->value, $jobs))->toBe(['launch', 'await-boot', 'upgrade-packages', ...array_fill(0, 10, 'program'), 'prepare-boot', 'clean', 'publish', 'smoke', 'promote'])
        ->and($build->state)->toBe(TaskVmImageBuildState::Running);
    ($this->runBuild)($build);
    expect($build->state)->toBe(TaskVmImageBuildState::Succeeded)
        ->and($build->source_fingerprint)->toBe(IMAGE_OLD)
        ->and($build->image_fingerprint)->toBe(IMAGE_NEW)
        ->and($this->incus->instances)->toBe([]);
    $launch = collect($this->incus->commands)->first(fn ($command): bool => $command->arguments[5] === 'launch');
    expect(array_slice($launch->arguments, -3))->toBe(['--', IMAGE_OLD, $build->builder_vm_name])
        ->and(json_decode($launch->input, true)['config']['cloud-init.user-data'])->toContain('"uid": 1000')->not->toContain('package_upgrade')->not->toContain('"packages"');
    $upgrade = collect($this->incus->guest)->first(fn ($call): bool => str_contains(implode(' ', $call->argv), 'full-upgrade'));
    expect(implode(' ', $upgrade->argv))->toContain('openssh-server')->toContain('libnss3')->toContain('bind9-dnsutils');
    expect(implode(' ', $upgrade->argv))->toContain('apt-get -o DPkg::Lock::Timeout=300 -y full-upgrade', 'apt-get -o DPkg::Lock::Timeout=300 -y install');
    $update = collect($this->incus->guest)->first(fn ($call): bool => str_contains(implode(' ', $call->argv), 'wait_seconds='));
    expect(implode(' ', $update->argv))->toContain('Could not get lock', '300');
    expect((new UpgradeImagePackages($build->id))->timeout)->toBeGreaterThan(1440);
    foreach ($build->steps as $step) {
        expect($step['started_at'])->not->toBeNull()->and($step['seconds'])->toBeNumeric()->toBeGreaterThanOrEqual(0)->and($step['error'])->toBeNull();
    }
    Bus::assertChained([RefreshTaskVmWorkspaceTemplates::class]);
});

it('uses stock when requested or on the first build', function (bool $fromStock, bool $first): void {
    if ($first) {
        unset($this->incus->images[IMAGE_OLD]);
    }
    $build = $this->chain->start($this->host, $fromStock);
    ($this->runBuild)($build);
    expect($build->source_alias)->toBe(TaskVmHost::SourceImage)->and($build->source_fingerprint)->toBe(IMAGE_STOCK)->and($build->state)->toBe(TaskVmImageBuildState::Succeeded);
})->with([[true, false], [false, true]]);

it('retains the failed builder after catch and refreshes templates against the current alias', function (): void {
    $build = $this->chain->start($this->host);
    $this->incus->fail = 'program';
    try {
        ($this->runBuild)($build);
    } catch (TaskVmException $error) {
        $first = Bus::dispatched(LaunchImageBuilder::class)->sole();
        $first->invokeChainCatchCallbacks($error);
    }
    Bus::assertNotDispatched(CleanupImageBuild::class);
    Bus::assertDispatchedTimes(RefreshTaskVmWorkspaceTemplates::class, 1);
    $build->refresh();
    expect($build->state)->toBe(TaskVmImageBuildState::Failed)->and($build->current_program)->toBe('bootstrap')->and($build->steps[3]['error'])->toContain('failed');
    $this->travel(1)->hours();
    app(IncusTaskVmBaseImage::class)->sweep($this->settings->host($this->host->id), $this->host);
    expect($this->incus->instances)->toHaveKey($build->builder_vm_name)
        ->and($this->incus->images[IMAGE_OLD])->toContain(TaskVmHost::BaseImage);
    $this->incus->fail = null;
    $this->chain->resume($this->host, $build->id);
    ($this->runBuild)($build, true);
    expect($build->state)->toBe(TaskVmImageBuildState::Succeeded);
});

it('queues a workspace template refresh when the image chain fails without promoting', function (): void {
    $build = $this->chain->start($this->host);
    $this->incus->fail = 'program';
    try {
        ($this->runBuild)($build);
    } catch (TaskVmException $error) {
        Bus::dispatched(LaunchImageBuilder::class)->sole()->invokeChainCatchCallbacks($error);
    }
    Bus::assertDispatchedTimes(RefreshTaskVmWorkspaceTemplates::class, 1);
    expect($this->incus->images[IMAGE_OLD])->toContain(TaskVmHost::BaseImage);
});

it('recovers a missing failure refresh from durable state after a crash or rejected enqueue', function (bool $enqueueRejected): void {
    $build = $this->chain->start($this->host);
    $this->incus->fail = 'program';
    expect(fn () => ($this->runBuild)($build))->toThrow(TaskVmException::class);
    $first = Bus::dispatched(LaunchImageBuilder::class)->sole();
    if ($enqueueRejected) {
        Bus::shouldReceive('dispatch')->once()->andThrow(new RuntimeException('refresh enqueue rejected'));
        expect(fn () => $first->invokeChainCatchCallbacks(new RuntimeException('step failed')))
            ->toThrow(RuntimeException::class, 'refresh enqueue rejected');
    }
    // A rejected dispatch can leave Laravel's unique lease; recovery works after its bounded expiry.
    if ($enqueueRejected) {
        $this->travel(3601)->seconds();
    }
    // Otherwise simulate death after the failed row was saved and before catch could enqueue.
    Bus::swap($this->dispatcher);
    Bus::fake();
    $this->chain->recoverTemplateRefresh();
    Bus::assertDispatchedTimes(RefreshTaskVmWorkspaceTemplates::class, 1);
    expect($build->refresh()->state)->toBe(TaskVmImageBuildState::Failed);
    // Recovery is deliberately repeatable; the refresh's Laravel uniqueness absorbs duplicates.
    $this->chain->recoverTemplateRefresh();
    Bus::assertDispatchedTimes(RefreshTaskVmWorkspaceTemplates::class, 1);
    $this->travel(3601)->seconds();
    $this->chain->recoverTemplateRefresh();
    Bus::assertDispatchedTimes(RefreshTaskVmWorkspaceTemplates::class, 2);
    $this->chain->resume($this->host, $build->id);
    Bus::fake();
    $this->chain->recoverTemplateRefresh();
    Bus::assertNotDispatched(RefreshTaskVmWorkspaceTemplates::class);
})->with(['crash before enqueue' => false, 'broker failure after failed write' => true]);

it('resumes publish after deletion took effect but its reply was lost without republishing', function (): void {
    $build = $this->chain->start($this->host);
    $error = new RuntimeException('Lost reply after builder deletion');
    $this->incus->afterExecute = function (RemoteCommand $command) use ($build, $error): void {
        if (($command->arguments[5] ?? null) === 'delete' && $command->arguments[array_key_last($command->arguments)] === $build->builder_vm_name) {
            $this->incus->afterExecute = null;
            expect($this->incus->instances)->not->toHaveKey($build->builder_vm_name);
            throw $error;
        }
    };
    expect(fn () => ($this->runBuild)($build))->toThrow(RuntimeException::class, 'Lost reply after builder deletion');
    Bus::dispatched(LaunchImageBuilder::class)->sole()->invokeChainCatchCallbacks($error);
    expect($build->refresh()->state)->toBe(TaskVmImageBuildState::Failed)
        ->and($build->current_step)->toBe(TaskVmImageBuildStep::Publish)
        ->and($build->image_fingerprint)->toBe(IMAGE_NEW)
        ->and($this->incus->images[IMAGE_OLD])->toContain(TaskVmHost::BaseImage);
    Bus::assertNotDispatched(CleanupImageBuild::class);
    $publications = count(array_filter($this->incus->commands, static fn ($command): bool => ($command->arguments[5] ?? null) === 'publish'));
    expect($publications)->toBe(1);
    $this->chain->resume($this->host, $build->id);
    ($this->runBuild)($build, true);
    expect($build->state)->toBe(TaskVmImageBuildState::Succeeded)
        ->and($this->incus->images[IMAGE_NEW])->toContain(TaskVmHost::BaseImage)
        ->and(count(array_filter($this->incus->commands, static fn ($command): bool => ($command->arguments[5] ?? null) === 'publish')))->toBe($publications);
});

it('resumes publish with its builder when publication failed before producing an image', function (): void {
    $build = $this->chain->start($this->host);
    $this->incus->fail = 'publish';
    expect(fn () => ($this->runBuild)($build))->toThrow(TaskVmException::class);
    expect($build->refresh()->current_step)->toBe(TaskVmImageBuildStep::Publish)
        ->and($build->image_fingerprint)->toBeNull()
        ->and($this->incus->instances)->toHaveKey($build->builder_vm_name);
    $this->incus->fail = null;
    $this->chain->resume($this->host, $build->id);
    ($this->runBuild)($build, true);
    expect($build->state)->toBe(TaskVmImageBuildState::Succeeded);
});

it('refuses publish resume when its required builder or recorded image is missing', function (?string $fingerprint, string $message): void {
    $build = TaskVmImageBuild::query()->create([
        'host_node_id' => $this->host->id, 'state' => TaskVmImageBuildState::Failed,
        'current_step' => TaskVmImageBuildStep::Publish, 'image_fingerprint' => $fingerprint,
    ]);
    expect(fn () => $this->chain->resume($this->host, $build->id))->toThrow(TaskVmException::class, $message);
})->with([[null, 'builder still exists'], [IMAGE_NEW, 'published image no longer exists']]);

it('resumes smoke and promote failures using the published image after the builder is deleted', function (string $failure, TaskVmImageBuildStep $step): void {
    $build = $this->chain->start($this->host);
    $this->incus->fail = $failure;
    expect(fn () => ($this->runBuild)($build))->toThrow(TaskVmException::class);
    expect($build->refresh()->current_step)->toBe($step)
        ->and($this->incus->instances)->not->toHaveKey($build->builder_vm_name);
    $this->incus->fail = null;
    $this->chain->resume($this->host, $build->id);
    ($this->runBuild)($build, true);
    expect($build->state)->toBe(TaskVmImageBuildState::Succeeded)
        ->and($this->incus->images[IMAGE_NEW])->toContain(TaskVmHost::BaseImage);
})->with([
    'smoke' => ['lean boot', TaskVmImageBuildStep::Smoke],
    'promote' => ['query', TaskVmImageBuildStep::Promote],
]);

it('refuses a post-publish resume when its published image was discarded', function (): void {
    $build = $this->chain->start($this->host);
    $this->incus->fail = 'lean boot';
    expect(fn () => ($this->runBuild)($build))->toThrow(TaskVmException::class);
    $this->incus->fail = null;
    unset($this->incus->images[IMAGE_NEW]);
    expect(fn () => $this->chain->resume($this->host, $build->id))->toThrow(TaskVmException::class, 'published image no longer exists');
});

it('resumes a failed step while its builder exists, before cleanup, and skips completed steps', function (): void {
    $build = $this->chain->start($this->host);
    $this->incus->fail = 'program';
    expect(fn () => ($this->runBuild)($build))->toThrow(TaskVmException::class);
    $build->refresh();
    $launchCalls = count($this->incus->commands);
    $this->incus->fail = null;
    $this->chain->resume($this->host, $build->id);
    app()->call([new CleanupImageBuild($build->id), 'handle']);
    expect($this->incus->instances)->toHaveKey($build->builder_vm_name);
    ($this->runBuild)($build, true);
    expect($build->state)->toBe(TaskVmImageBuildState::Succeeded)->and($build->steps[3]['error'])->toBeNull()->and(count($this->incus->commands))->toBeGreaterThan($launchCalls);
});

it('releases boot polling and fails at its 600 second deadline without fixed tries', function (): void {
    $build = $this->chain->start($this->host);
    (new LaunchImageBuilder($build->id))->handle(app(TaskVmImageBuildSteps::class));
    $this->incus->bootRunning = true;
    $poll = new AwaitImageBuilderBoot($build->id);
    $queueJob = Mockery::mock(Job::class);
    $queueJob->shouldReceive('release')->once()->with(5);
    $queueJob->shouldReceive('fail')->once()->with(Mockery::type(TaskVmException::class));
    $poll->setJob($queueJob);
    $poll->handle(app(TaskVmImageBuildSteps::class));
    expect($poll->tries)->toBe(0)->and(method_exists($poll, 'retryUntil'))->toBeFalse();
    $this->travel(601)->seconds();
    expect(fn () => $poll->handle(app(TaskVmImageBuildSteps::class)))->toThrow(TaskVmException::class, '600 seconds');
});

it('starts polling deadlines at execution even after more than 600 seconds in the queue', function (TaskVmImageBuildStep $previous, TaskVmImageBuildStep $polling): void {
    Bus::swap($this->dispatcher);
    $build = $this->chain->start($this->host);
    $worker = app('queue.worker');
    $options = new WorkerOptions(sleep: 0, maxTries: 1);
    for ($turn = 0; $turn < 50 && $build->refresh()->current_step !== $previous; $turn++) {
        $worker->runNextJob('gateway', 'builds', $options);
        $this->travel(5)->seconds();
    }
    expect($build->refresh()->current_step)->toBe($previous);
    // The next polling payload is already on the queue, behind a long-running build job.
    $this->travel(1200)->seconds();
    $this->incus->bootRunning = true;
    $started = now()->toISOString();
    $worker->runNextJob('gateway', 'builds', $options);
    expect($build->refresh()->state)->toBe(TaskVmImageBuildState::Running)
        ->and($build->current_step)->toBe($polling)
        ->and(array_column($build->steps, 'started_at'))->toContain($started);
    $this->travel(601)->seconds();
    $worker->runNextJob('gateway', 'builds', $options);
    expect($build->refresh()->state)->toBe(TaskVmImageBuildState::Failed)
        ->and($build->current_step)->toBe($polling)
        ->and($build->steps[array_key_last($build->steps)]['error'])->toContain('600 seconds');
})->with([
    'boot' => [TaskVmImageBuildStep::Launch, TaskVmImageBuildStep::AwaitBoot],
    'smoke' => [TaskVmImageBuildStep::Publish, TaskVmImageBuildStep::Smoke],
]);

it('discards a retained failed builder when the next build launches on the host', function (): void {
    $failed = $this->chain->start($this->host);
    $this->incus->fail = 'program';
    expect(fn () => ($this->runBuild)($failed))->toThrow(TaskVmException::class);
    $this->incus->fail = null;
    $next = $this->chain->start($this->host);
    (new LaunchImageBuilder($next->id))->handle(app(TaskVmImageBuildSteps::class));
    expect($this->incus->instances)->not->toHaveKey($failed->builder_vm_name)
        ->toHaveKey($next->builder_vm_name);
    expect(fn () => $this->chain->resume($this->host, $failed->id))->toThrow(TaskVmException::class);
});

it('refuses a second running build on the host', function (): void {
    $this->chain->start($this->host);
    expect(fn () => $this->chain->start($this->host))->toThrow(TaskVmException::class, 'already running');
});

it('dispatches the nightly source and resets to stock on Sunday', function (string $date, bool $stock): void {
    $this->travelTo($date);
    app()->call([new RefreshTaskVmBaseImages, 'handle']);
    $build = TaskVmImageBuild::query()->sole();
    expect($build->from_stock)->toBe($stock);
    (new LaunchImageBuilder($build->id))->handle(app(TaskVmImageBuildSteps::class));
    expect($build->refresh()->source_fingerprint)->toBe($stock ? IMAGE_STOCK : IMAGE_OLD);
})->with([['2026-10-11 03:00:00', true], ['2026-10-12 03:00:00', false]]);

it('does nothing nightly until the first host has a base image', function (): void {
    unset($this->incus->images[IMAGE_OLD]);
    app()->call([new RefreshTaskVmBaseImages, 'handle']);
    expect(TaskVmImageBuild::query()->count())->toBe(0);
});

it('watches command progress and supports from-stock and resume', function (): void {
    Sleep::whenFakingSleep(function (): void {
        $build = TaskVmImageBuild::query()->sole();
        ($this->runBuild)($build);
    });
    $this->artisan('task-vms:build-image', ['node' => 'beast', '--from-stock' => true])->expectsOutputToContain('succeeded')->assertSuccessful();
    expect(TaskVmImageBuild::query()->sole()->source_fingerprint)->toBe(IMAGE_STOCK);
});

it('prints the failed step and error and exits nonzero', function (): void {
    $this->incus->fail = 'clean';
    Sleep::whenFakingSleep(function (): void {
        try {
            ($this->runBuild)(TaskVmImageBuild::query()->sole());
        } catch (TaskVmException) {
        }
    });
    $this->artisan('task-vms:build-image', ['node' => 'beast'])->expectsOutputToContain('Build failed at clean:')->assertFailed();
});

it('cleans failed publish, smoke and promote steps without moving the old alias', function (string $operation, string $step): void {
    $build = $this->chain->start($this->host);
    $this->incus->fail = $operation;
    expect(fn () => ($this->runBuild)($build))->toThrow(TaskVmException::class);
    $build->refresh();
    expect($build->current_step->value)->toBe($step)->and($build->state)->toBe(TaskVmImageBuildState::Failed);
    app()->call([new CleanupImageBuild($build->id), 'handle']);
    expect($this->incus->instances)->toBe([])->and($this->incus->images)->not->toHaveKey(IMAGE_NEW)
        ->and($this->incus->images[IMAGE_OLD])->toContain(TaskVmHost::BaseImage);
})->with([['publish', 'publish'], ['lean boot', 'smoke'], ['query', 'promote']]);

it('keeps a promoted image if pruning fails', function (): void {
    $this->incus->images[IMAGE_OLD][] = 'orbit-task-base-20261010030000';
    $build = $this->chain->start($this->host);
    $this->incus->fail = 'image delete';
    expect(fn () => ($this->runBuild)($build))->toThrow(TaskVmException::class);
    app()->call([new CleanupImageBuild($build->id), 'handle']);
    expect($this->incus->images[IMAGE_NEW])->toContain(TaskVmHost::BaseImage);
});

it('continues the command from its failed program instead of launching again', function (): void {
    $build = $this->chain->start($this->host);
    $this->incus->fail = 'program';
    expect(fn () => ($this->runBuild)($build))->toThrow(TaskVmException::class);
    $this->incus->fail = null;
    Sleep::whenFakingSleep(function (): void {
        ($this->runBuild)(TaskVmImageBuild::query()->sole(), true);
    });
    $this->artisan('task-vms:build-image', ['node' => 'beast', '--resume' => (string) $build->id])->expectsOutputToContain('succeeded')->assertSuccessful();
    expect(collect($this->incus->commands)->filter(fn ($command): bool => $command->arguments[5] === 'launch'))->toHaveCount(2);
});

it('refuses invalid or unavailable command resumes', function (): void {
    $this->artisan('task-vms:build-image', ['node' => 'beast', '--resume' => '99999'])->expectsOutputToContain('does not belong to this host')->assertFailed();
    $this->artisan('task-vms:build-image', ['node' => 'beast', '--resume' => 'bad'])->expectsOutputToContain('--resume requires a build id')->assertFailed();
});

it('does not repeat a completed step when its job is delivered twice', function (): void {
    $build = $this->chain->start($this->host);
    $job = new LaunchImageBuilder($build->id);
    $job->handle(app(TaskVmImageBuildSteps::class));
    $calls = count($this->incus->commands);
    $job->handle(app(TaskVmImageBuildSteps::class));
    expect(count($this->incus->commands))->toBe($calls)->and($build->refresh()->steps)->toHaveCount(1);
});

it('runs the serialized chain through the database queue worker, including polling releases', function (): void {
    Bus::swap($this->dispatcher);
    $build = $this->chain->start($this->host);
    $worker = app('queue.worker');
    $options = new WorkerOptions(sleep: 0, maxTries: 1);
    for ($turn = 0; $turn < 50 && $build->refresh()->state === TaskVmImageBuildState::Running; $turn++) {
        $worker->runNextJob('gateway', 'builds', $options);
        $this->travel(5)->seconds();
    }
    expect($build->refresh()->state)->toBe(TaskVmImageBuildState::Succeeded)
        ->and($build->steps)->toHaveCount(18)
        ->and($this->incus->instances)->toBe([]);
});

it('keeps success when promote is redelivered after saving success but before acknowledgement', function (): void {
    $build = $this->chain->start($this->host);
    foreach ($this->chain->jobs($build) as $command) {
        if ($command instanceof PromoteImage) {
            break;
        }
        do {
            $command->handle(app(TaskVmImageBuildSteps::class));
            $build->refresh();
            $entry = array_find($build->steps, fn (array $entry): bool => $entry['step'] === $command->step()->value && $entry['program'] === $command->program());
        } while ($entry['seconds'] === null);
    }
    $first = Bus::dispatched(LaunchImageBuilder::class)->sole();
    $command->chainCatchCallbacks = $first->chainCatchCallbacks;
    Bus::swap($this->dispatcher);
    Bus::dispatch($command->onConnection('gateway')->onQueue('builds'));
    $delivery = app('queue')->connection('gateway')->pop('builds');
    expect($delivery)->not->toBeNull();
    $reserved = unserialize($delivery->payload()['data']['command']);
    $reserved->setJob($delivery);
    // Run the actual promoted payload but omit CallQueuedHandler's final acknowledgement.
    app()->call([$reserved, 'handle']);
    $saved = $build->refresh()->getAttributes();
    expect($build->state)->toBe(TaskVmImageBuildState::Succeeded);
    // Also protect success from legacy one-attempt payloads that fail before handle on redelivery.
    $payload = $delivery->payload();
    $payload['maxTries'] = 1;
    DB::table('jobs')->where('id', $delivery->getJobId())->update([
        'reserved_at' => now()->subHours(2)->timestamp, 'payload' => json_encode($payload, JSON_THROW_ON_ERROR),
    ]);
    Artisan::call('queue:work', ['connection' => 'gateway', '--queue' => 'builds', '--once' => true, '--tries' => 1, '--sleep' => 0]);
    expect($build->refresh()->getAttributes())->toBe($saved)
        ->and(DB::table('failed_jobs')->count())->toBe(1);
});

it('replays a completed launch after a crash before successor dispatch and finishes the chain', function (): void {
    Bus::swap($this->dispatcher);
    $build = $this->chain->start($this->host);
    $delivery = Queue::connection('gateway')->pop('builds');
    $command = unserialize($delivery->payload()['data']['command']);
    $command->setJob($delivery);
    app()->call([$command, 'handle']);
    expect($build->refresh()->current_step)->toBe(TaskVmImageBuildStep::Launch)
        ->and($build->steps[0]['seconds'])->not->toBeNull()
        ->and(DB::table('jobs')->count())->toBe(1);
    $calls = count($this->incus->commands);
    DB::table('jobs')->where('id', $delivery->getJobId())->update(['reserved_at' => now()->subHours(2)->timestamp]);
    Artisan::call('queue:work', ['connection' => 'gateway', '--queue' => 'builds', '--once' => true, '--tries' => 1]);
    expect(count($this->incus->commands))->toBe($calls)
        ->and(DB::table('jobs')->value('payload'))->toContain('AwaitImageBuilderBoot');
    for ($turn = 0; $turn < 50 && $build->refresh()->state === TaskVmImageBuildState::Running; $turn++) {
        app('queue.worker')->runNextJob('gateway', 'builds', new WorkerOptions(sleep: 0, maxTries: 1));
        $this->travel(5)->seconds();
    }
    expect($build->refresh()->state)->toBe(TaskVmImageBuildState::Succeeded);
});

it('makes a completed launch resumable when the real worker times out before successor acceptance', function (): void {
    // Timeout rolls back the failure-provider connection to level zero. Keep its real rollback
    // behavior without rolling back RefreshDatabase's unrelated outer fixture transaction.
    config(['database.connections.timeout-failures' => config('database.connections.'.config('database.default')),
        'queue.failed.database' => 'timeout-failures']);
    Schema::connection('timeout-failures')->create('failed_jobs', function (Blueprint $table): void {
        $table->id();
        $table->string('uuid')->unique();
        $table->string('connection');
        $table->string('queue');
        $table->longText('payload');
        $table->longText('exception');
        $table->timestamp('failed_at');
    });
    Bus::swap($this->dispatcher);
    $build = $this->chain->start($this->host);
    $timedOut = false;
    app('events')->listen(JobTimedOut::class, function () use (&$timedOut): void {
        $timedOut = true;
    });
    Queue::createPayloadUsing(function (string $connection, string $queue, array $payload) use ($build): array {
        if ($payload['displayName'] === AwaitImageBuilderBoot::class) {
            expect($build->refresh()->steps[0]['seconds'])->not->toBeNull()
                ->and($build->steps[0]['error'])->toBeNull()
                ->and($build->state)->toBe(TaskVmImageBuildState::Running);
            // SIGALRM hits the real worker's registered handler before the successor is accepted.
            posix_kill(getmypid(), SIGALRM);
            pcntl_signal_dispatch();
            throw new RuntimeException('timeout handler did not interrupt successor enqueue');
        }

        return [];
    });
    $killOnTimeout = Worker::$killOnTimeout;
    $signalHandler = pcntl_signal_get_handler(SIGALRM);
    $asyncSignals = pcntl_async_signals();
    // Laravel's supported test switch retains its full timeout failure path, without killing Pest.
    Worker::$killOnTimeout = false;
    try {
        Artisan::call('queue:work', ['connection' => 'gateway', '--queue' => 'builds', '--max-jobs' => 1, '--tries' => 1, '--sleep' => 0, '--memory' => 4096]);
    } finally {
        Worker::$killOnTimeout = $killOnTimeout;
        Queue::createPayloadUsing(null);
        pcntl_alarm(0);
        pcntl_signal(SIGALRM, $signalHandler);
        pcntl_async_signals($asyncSignals);
    }
    expect($timedOut)->toBeTrue()
        ->and($build->refresh()->state)->toBe(TaskVmImageBuildState::Failed)
        ->and($build->current_step)->toBe(TaskVmImageBuildStep::Launch)
        ->and($build->steps[0]['error'])->toContain('timed out')
        ->and(DB::connection('timeout-failures')->table('failed_jobs')->count())->toBe(1)
        ->and(DB::connection('timeout-failures')->table('failed_jobs')->value('exception'))->toContain(TimeoutExceededException::class);
    $queued = DB::table('jobs')->get()->pluck('payload')->implode('\n');
    expect($queued)->not->toContain('AwaitImageBuilderBoot', 'LaunchImageBuilder');

    $this->chain->resume($this->host, $build->id);
    for ($turn = 0; $turn < 50 && $build->refresh()->state === TaskVmImageBuildState::Running; $turn++) {
        app('queue.worker')->runNextJob('gateway', 'builds', new WorkerOptions(sleep: 0, maxTries: 1));
        $this->travel(5)->seconds();
    }
    expect($build->refresh()->state)->toBe(TaskVmImageBuildState::Succeeded)
        ->and($build->attempt)->toBe(2);
});

it('fences completed-step timeout callbacks from successful builds, successors and resumed attempts', function (string $boundary): void {
    $build = $this->chain->start($this->host);
    Bus::dispatched(LaunchImageBuilder::class)->sole()->handle(app(TaskVmImageBuildSteps::class));
    $delivery = Mockery::mock(Job::class);
    $delivery->shouldReceive('resolveName')->andReturn(LaunchImageBuilder::class);
    $error = TimeoutExceededException::forJob($delivery);
    if ($boundary === 'success') {
        ($this->runBuild)($build);
    } elseif ($boundary === 'successor') {
        (new AwaitImageBuilderBoot($build->id))->handle(app(TaskVmImageBuildSteps::class));
    } else {
        ImageBuildJob::recordFailure($build->id, 1, TaskVmImageBuildStep::Launch, null, $error);
        $this->chain->resume($this->host, $build->id);
        Bus::dispatched(LaunchImageBuilder::class)->last()->handle(app(TaskVmImageBuildSteps::class));
    }
    $saved = $build->refresh()->getAttributes();
    ImageBuildJob::recordFailure($build->id, 1, TaskVmImageBuildStep::Launch, null, $error);
    expect($build->refresh()->getAttributes())->toBe($saved);
})->with(['success', 'successor', 'resume']);

it('writes a pre-handle originating failure before a rejected chain refresh enqueue', function (): void {
    Bus::swap($this->dispatcher);
    $build = $this->chain->start($this->host);
    $row = DB::table('jobs')->first();
    $payload = json_decode($row->payload, true, flags: JSON_THROW_ON_ERROR);
    $payload['maxTries'] = 1;
    DB::table('jobs')->where('id', $row->id)->update(['attempts' => 1, 'payload' => json_encode($payload, JSON_THROW_ON_ERROR)]);
    Queue::createPayloadUsing(static function (string $connection, string $queue, array $payload): array {
        if ($payload['displayName'] === RefreshTaskVmWorkspaceTemplates::class) {
            throw new RuntimeException('refresh enqueue rejected');
        }

        return [];
    });
    try {
        Artisan::call('queue:work', ['connection' => 'gateway', '--queue' => 'builds', '--once' => true, '--tries' => 1]);
    } finally {
        Queue::createPayloadUsing(null);
    }
    expect($build->refresh()->state)->toBe(TaskVmImageBuildState::Failed)
        ->and($build->current_step)->toBe(TaskVmImageBuildStep::Launch)
        ->and($build->steps[0]['error'])->toContain('attempted too many times')
        ->and(DB::table('failed_jobs')->count())->toBe(1);
    $this->travel(3601)->seconds();
    $this->chain->recoverTemplateRefresh();
    expect(DB::table('jobs')->value('payload'))->toContain('RefreshTaskVmWorkspaceTemplates');
});

it('ignores an exhausted old delivery after resume without overwriting the resumed step', function (bool $advance): void {
    $build = $this->chain->start($this->host);
    $first = Bus::dispatched(LaunchImageBuilder::class)->sole();
    $first->handle(app(TaskVmImageBuildSteps::class));
    $old = new UpgradeImagePackages($build->id);
    $old->chainCatchCallbacks = $first->chainCatchCallbacks;
    $this->incus->fail = 'exec';
    expect(fn () => $old->handle(app(TaskVmImageBuildSteps::class)))->toThrow(TaskVmException::class);
    expect($build->refresh()->state)->toBe(TaskVmImageBuildState::Failed)
        ->and($build->current_step)->toBe(TaskVmImageBuildStep::UpgradePackages)
        ->and($build->steps[1]['error'])->toContain('failed');
    $this->incus->fail = null;
    $this->chain->resume($this->host, $build->id);
    $build->refresh();
    if ($advance) {
        $jobs = $this->chain->jobs($build);
        $jobs[2]->handle(app(TaskVmImageBuildSteps::class));
        $jobs[3]->handle(app(TaskVmImageBuildSteps::class));
        $build->refresh();
        expect($build->current_program)->toBe('bootstrap');
    }
    $saved = $build->getAttributes();
    Bus::swap($this->dispatcher);
    Bus::dispatch($old->onConnection('gateway')->onQueue('builds'));
    $row = DB::table('jobs')->first();
    $payload = json_decode($row->payload, true, flags: JSON_THROW_ON_ERROR);
    $payload['maxTries'] = 1;
    DB::table('jobs')->where('id', $row->id)->update(['attempts' => 1, 'payload' => json_encode($payload, JSON_THROW_ON_ERROR)]);
    Artisan::call('queue:work', ['connection' => 'gateway', '--queue' => 'builds', '--once' => true, '--tries' => 1, '--sleep' => 0]);
    expect($build->refresh()->getAttributes())->toBe($saved)
        ->and($build->steps[1]['error'])->toBeNull()
        ->and(DB::table('failed_jobs')->count())->toBe(1);
})->with(['resumed at same step' => false, 'advanced to later step' => true]);

it('attributes a pre-handle worker failure to its originating step, not the mutable current step', function (): void {
    $build = $this->chain->start($this->host);
    Bus::dispatched(LaunchImageBuilder::class)->sole()->handle(app(TaskVmImageBuildSteps::class));
    Bus::swap($this->dispatcher);
    Bus::dispatch((new UpgradeImagePackages($build->id))->onConnection('gateway')->onQueue('builds'));
    $row = DB::table('jobs')->first();
    $payload = json_decode($row->payload, true, flags: JSON_THROW_ON_ERROR);
    $payload['maxTries'] = 1;
    DB::table('jobs')->where('id', $row->id)->update(['attempts' => 1, 'payload' => json_encode($payload, JSON_THROW_ON_ERROR)]);
    Artisan::call('queue:work', ['connection' => 'gateway', '--queue' => 'builds', '--once' => true, '--tries' => 1, '--sleep' => 0]);
    expect($build->refresh()->state)->toBe(TaskVmImageBuildState::Failed)
        ->and($build->current_step)->toBe(TaskVmImageBuildStep::UpgradePackages)
        ->and($build->steps[0]['error'])->toBeNull()
        ->and($build->steps[1]['error'])->toContain('attempted too many times');
});

it('fails a boot error through the worker and retains its builder after the catch runs', function (): void {
    Bus::swap($this->dispatcher);
    $build = $this->chain->start($this->host);
    $this->incus->fail = 'cloud-init';
    $worker = app('queue.worker');
    $options = new WorkerOptions(sleep: 0, maxTries: 1);
    $worker->runNextJob('gateway', 'builds', $options);
    $worker->runNextJob('gateway', 'builds', $options);
    expect($build->refresh()->state)->toBe(TaskVmImageBuildState::Failed)
        ->and($build->current_step)->toBe(TaskVmImageBuildStep::AwaitBoot)
        ->and(DB::table('jobs')->count())->toBe(1);
    $worker->runNextJob('gateway', 'builds', $options);
    expect($this->incus->instances)->toHaveKey($build->builder_vm_name)
        ->and(DB::table('jobs')->where('payload', 'like', '%CleanupImageBuild%')->count())->toBe(0)
        ->and($this->incus->images[IMAGE_OLD])->toContain(TaskVmHost::BaseImage);
});

it('adds and runs Pi only when the Gateway pins its artifact', function (): void {
    $path = tempnam(sys_get_temp_dir(), 'orbit-chain-pi-');
    file_put_contents($path, 'pinned Pi executable');
    try {
        TaskVmSettingsBuilder::bind(['hosts' => $this->settings->hosts, 'piArtifactPath' => $path, 'piArtifactSha256' => hash_file('sha256', $path)]);
        $this->chain = app(TaskVmImageBuildChain::class);
        $build = $this->chain->start($this->host);
        ($this->runBuild)($build);
        expect(array_column($build->steps, 'program'))->toContain('pi-artifact')
            ->and($build->state)->toBe(TaskVmImageBuildState::Succeeded);
    } finally {
        unlink($path);
    }
});

it('fails a rejected start submission durably and permits a new build', function (): void {
    Bus::shouldReceive('chain')->once()->andThrow(new RuntimeException('broker unavailable'));
    expect(fn () => $this->chain->start($this->host))->toThrow(RuntimeException::class, 'broker unavailable');
    $failed = TaskVmImageBuild::query()->sole();
    expect($failed->state)->toBe(TaskVmImageBuildState::Failed)
        ->and($failed->submission_pending_at)->toBeNull()
        ->and($failed->steps[0]['error'])->toBe('broker unavailable');
    Bus::swap($this->dispatcher);
    Bus::fake();
    expect($this->chain->start($this->host)->state)->toBe(TaskVmImageBuildState::Running);
});

it('fails a rejected resume submission without losing the builder or excluding another resume', function (): void {
    $build = $this->chain->start($this->host);
    $this->incus->fail = 'program';
    expect(fn () => ($this->runBuild)($build))->toThrow(TaskVmException::class);
    $this->incus->fail = null;
    Bus::shouldReceive('chain')->once()->andThrow(new RuntimeException('resume enqueue rejected'));
    expect(fn () => $this->chain->resume($this->host, $build->id))->toThrow(RuntimeException::class, 'resume enqueue rejected');
    expect($build->refresh()->state)->toBe(TaskVmImageBuildState::Failed)
        ->and($build->submission_pending_at)->toBeNull()
        ->and($this->incus->instances)->toHaveKey($build->builder_vm_name);
    Bus::swap($this->dispatcher);
    Bus::fake();
    $this->chain->resume($this->host, $build->id);
    ($this->runBuild)($build->refresh(), true);
    expect($build->state)->toBe(TaskVmImageBuildState::Succeeded);
});

it('recovers a process crash before enqueue on the next start without expiring acknowledged builds', function (): void {
    $pending = TaskVmImageBuild::query()->create(['host_node_id' => $this->host->id, 'submission_pending_at' => now()->toISOString()]);
    $this->chain->recoverSubmissions();
    expect($pending->refresh()->state)->toBe(TaskVmImageBuildState::Running);
    $this->travel(11)->minutes();
    $next = $this->chain->start($this->host);
    expect($pending->refresh()->state)->toBe(TaskVmImageBuildState::Failed)
        ->and($pending->steps[0]['error'])->toContain('not acknowledged')
        ->and($next->submission_pending_at)->toBeNull();
    // If the crashed sender actually queued it, late delivery cannot launch the failed build.
    (new LaunchImageBuilder($pending->id))->handle(app(TaskVmImageBuildSteps::class));
    expect($this->incus->instances)->toBe([]);
    $this->travel(24)->hours();
    $this->chain->recoverSubmissions();
    expect($next->refresh()->state)->toBe(TaskVmImageBuildState::Running);
});

it('recovers a crashed resume submission through the scheduled host sweep', function (): void {
    $build = $this->chain->start($this->host);
    $this->incus->fail = 'program';
    expect(fn () => ($this->runBuild)($build))->toThrow(TaskVmException::class);
    $build->refresh()->forceFill(['state' => TaskVmImageBuildState::Running, 'submission_pending_at' => now()->toISOString()])->save();
    $this->travel(11)->minutes();
    TaskVmSettingsBuilder::bind(['enabled' => false]);
    app()->call([new SweepTaskVmHosts, 'handle']);
    expect($build->refresh()->state)->toBe(TaskVmImageBuildState::Failed)
        ->and($build->submission_pending_at)->toBeNull();
});

it('acknowledges a worker claim even if its submitting process crashed before acknowledgement', function (): void {
    $build = TaskVmImageBuild::query()->create(['host_node_id' => $this->host->id, 'submission_pending_at' => now()->toISOString()]);
    (new LaunchImageBuilder($build->id))->handle(app(TaskVmImageBuildSteps::class));
    $this->travel(11)->minutes();
    $this->chain->recoverSubmissions();
    expect($build->refresh()->state)->toBe(TaskVmImageBuildState::Running)
        ->and($build->submission_pending_at)->toBeNull()
        ->and($this->incus->instances)->toHaveKey($build->builder_vm_name);
});

it('does not fail a worker-owned build when the enqueue reply reports an error', function (): void {
    Bus::shouldReceive('chain')->once()->andReturnUsing(function (): never {
        $build = TaskVmImageBuild::query()->sole();
        (new LaunchImageBuilder($build->id))->handle(app(TaskVmImageBuildSteps::class));
        throw new RuntimeException('enqueue reply lost');
    });
    expect(fn () => $this->chain->start($this->host))->toThrow(RuntimeException::class, 'enqueue reply lost');
    $build = TaskVmImageBuild::query()->sole();
    expect($build->state)->toBe(TaskVmImageBuildState::Running)
        ->and($build->submission_pending_at)->toBeNull();
});

it('protects a published build image during sync prune before smoke launches', function (bool $lostFingerprintWrite): void {
    $build = $this->chain->start($this->host);
    foreach ($this->chain->jobs($build) as $job) {
        if ($job->step() === TaskVmImageBuildStep::Smoke) {
            break;
        }
        for ($poll = 0; $poll < 3; $poll++) {
            $job->handle(app(TaskVmImageBuildSteps::class));
        }
    }
    $build->refresh();
    expect($this->incus->instances)->toBe([])->and($this->incus->images)->toHaveKey(IMAGE_NEW);
    if ($lostFingerprintWrite) {
        $build->forceFill(['image_fingerprint' => null])->save();
    }
    $image = app(IncusTaskVmBaseImage::class);
    $host = $this->settings->host($this->host->id);
    // An existing sync keeps the old base. Its prune must not delete the new, currently unused image.
    $image->prune($host, $this->host, IMAGE_OLD);
    expect($this->incus->images)->toHaveKey(IMAGE_NEW);
    if ($lostFingerprintWrite) {
        $build->forceFill(['image_fingerprint' => IMAGE_NEW])->save();
    }
    $smoke = new SmokeImage($build->id);
    for ($poll = 0; $poll < 3; $poll++) {
        $smoke->handle(app(TaskVmImageBuildSteps::class));
    }
    expect(array_find($build->refresh()->steps, fn (array $entry): bool => $entry['step'] === 'smoke')['seconds'])->not->toBeNull();
    $image->delete($host, $this->host, $build->builder_vm_name.'-smoke');
    $build->forceFill(['state' => TaskVmImageBuildState::Failed])->save();
    $image->prune($host, $this->host, IMAGE_OLD);
    expect($this->incus->images)->toHaveKey(IMAGE_NEW);
    $this->travel(25)->hours();
    $image->prune($host, $this->host, IMAGE_OLD);
    expect($this->incus->images)->not->toHaveKey(IMAGE_NEW);
})->with([false, true]);

it('fences a resume attempted after cleanup claims the row but before it deletes the existing builder', function (): void {
    $build = $this->chain->start($this->host);
    $this->incus->fail = 'program';
    expect(fn () => ($this->runBuild)($build))->toThrow(TaskVmException::class);
    $this->incus->fail = null;
    $overlapped = false;
    $this->incus->afterExecute = function () use ($build, &$overlapped): void {
        $this->incus->afterExecute = null;
        $overlapped = true;
        expect($build->refresh()->cleanup_started_at)->not->toBeNull()
            ->and($this->incus->instances)->toHaveKey($build->builder_vm_name);
        expect(fn () => $this->chain->resume($this->host, $build->id))->toThrow(TaskVmException::class);
    };
    app()->call([new CleanupImageBuild($build->id), 'handle']);
    expect($overlapped)->toBeTrue()->and($build->refresh()->state)->toBe(TaskVmImageBuildState::Failed)
        ->and($this->incus->instances)->toBe([]);
});

it('rejects a stale resume after cleanup wins between the builder existence read and the resume write', function (): void {
    $build = $this->chain->start($this->host);
    $this->incus->fail = 'program';
    expect(fn () => ($this->runBuild)($build))->toThrow(TaskVmException::class);
    $this->incus->fail = null;
    $overlapped = false;
    $this->incus->afterExecute = function () use ($build, &$overlapped): void {
        $this->incus->afterExecute = null;
        $overlapped = true;
        app()->call([new CleanupImageBuild($build->id), 'handle']);
    };
    expect(fn () => $this->chain->resume($this->host, $build->id))->toThrow(TaskVmException::class, 'already claimed');
    expect($overlapped)->toBeTrue()->and($build->refresh()->state)->toBe(TaskVmImageBuildState::Failed)
        ->and($build->cleanup_started_at)->not->toBeNull()->and($this->incus->instances)->toBe([]);
});

it('keeps a submission pending until its caller transaction commits and handles a deferred enqueue error', function (): void {
    Bus::shouldReceive('chain')->once()->andThrow(new RuntimeException('after-commit broker failure'));
    expect(fn () => DB::transaction(function (): void {
        $build = $this->chain->start($this->host);
        expect($build->submission_pending_at)->not->toBeNull();
    }))->toThrow(RuntimeException::class, 'after-commit broker failure');
    $build = TaskVmImageBuild::query()->sole();
    expect($build->state)->toBe(TaskVmImageBuildState::Failed)
        ->and($build->submission_pending_at)->toBeNull()
        ->and($build->steps[0]['error'])->toBe('after-commit broker failure');
});

it('does not acknowledge an incomplete resume through redelivery of an older completed step', function (): void {
    $build = $this->chain->start($this->host);
    $this->incus->fail = 'program';
    expect(fn () => ($this->runBuild)($build))->toThrow(TaskVmException::class);
    $build->refresh()->forceFill(['state' => TaskVmImageBuildState::Running, 'submission_pending_at' => now()->toISOString()])->save();
    (new LaunchImageBuilder($build->id))->handle(app(TaskVmImageBuildSteps::class));
    expect($build->refresh()->submission_pending_at)->not->toBeNull();
    $this->travel(11)->minutes();
    $this->chain->recoverSubmissions();
    expect($build->refresh()->state)->toBe(TaskVmImageBuildState::Failed);
});

it('keeps the running-host domain error when a failed build resumes beside a newer running build', function (): void {
    $failed = $this->chain->start($this->host);
    $this->incus->fail = 'program';
    expect(fn () => ($this->runBuild)($failed))->toThrow(TaskVmException::class);
    $this->incus->fail = null;
    $running = $this->chain->start($this->host);
    expect(fn () => $this->chain->resume($this->host, $failed->id))->toThrow(TaskVmException::class, 'already running');
    expect($failed->refresh()->state)->toBe(TaskVmImageBuildState::Failed)
        ->and($failed->submission_pending_at)->toBeNull()
        ->and($running->refresh()->state)->toBe(TaskVmImageBuildState::Running);
});
