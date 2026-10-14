<?php

declare(strict_types=1);

use App\Domain\Shared\LifecycleStatus;
use App\Domain\TaskVms\TaskVmException;
use App\Domain\TaskVms\TaskVmHost;
use App\Domain\TaskVms\TaskVmImageBuildState;
use App\Domain\TaskVms\TaskVmImageBuildStep;
use App\Infrastructure\Ssh\HostKey;
use App\Infrastructure\Ssh\KnownHostsStore;
use App\Infrastructure\Ssh\SshExecutor;
use App\Infrastructure\Ssh\SshKeyProvider;
use App\Infrastructure\TaskVms\IncusTaskVmBaseImage;
use App\Infrastructure\TaskVms\TaskVmImageBuildChain;
use App\Infrastructure\TaskVms\TaskVmImageBuildSteps;
use App\Jobs\TaskVms\ImageBuild\AwaitImageBuilderBoot;
use App\Jobs\TaskVms\ImageBuild\CleanupImageBuild;
use App\Jobs\TaskVms\ImageBuild\LaunchImageBuilder;
use App\Jobs\TaskVms\ImageBuild\SmokeImage;
use App\Jobs\TaskVms\RefreshTaskVmBaseImages;
use App\Jobs\TaskVms\RefreshTaskVmWorkspaceTemplates;
use App\Jobs\TaskVms\SweepTaskVmHosts;
use App\Models\Node;
use App\Models\TaskVmImageBuild;
use Illuminate\Contracts\Queue\Job;
use Illuminate\Queue\WorkerOptions;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
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
    $upgrade = collect($this->incus->guest)->first(fn ($call): bool => str_contains(implode(' ', $call->argv), 'apt-get -y full-upgrade'));
    expect(implode(' ', $upgrade->argv))->toContain('openssh-server')->toContain('libnss3')->toContain('bind9-dnsutils');
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

it('records a failed step and its catch dispatches one cleanup that keeps the alias', function (): void {
    $build = $this->chain->start($this->host);
    $this->incus->fail = 'program';
    try {
        ($this->runBuild)($build);
    } catch (TaskVmException $error) {
        $first = Bus::dispatched(LaunchImageBuilder::class)->sole();
        $first->invokeChainCatchCallbacks($error);
    }
    Bus::assertDispatchedTimes(CleanupImageBuild::class, 1);
    $build->refresh();
    expect($build->state)->toBe(TaskVmImageBuildState::Failed)->and($build->current_program)->toBe('bootstrap')->and($build->steps[3]['error'])->toContain('failed');
    app()->call([new CleanupImageBuild($build->id), 'handle']);
    expect($this->incus->instances)->toBe([])->and($this->incus->images[IMAGE_OLD])->toContain(TaskVmHost::BaseImage);
    expect(fn () => $this->chain->resume($this->host, $build->id))->toThrow(TaskVmException::class, 'builder still exists');
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
    expect(property_exists($poll, 'tries'))->toBeFalse()->and($poll->retryUntil()->getTimestamp())->toBe(now()->addSeconds(600)->getTimestamp());
    $this->travel(601)->seconds();
    expect(fn () => $poll->handle(app(TaskVmImageBuildSteps::class)))->toThrow(TaskVmException::class, '600 seconds');
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

it('fails a boot error immediately through the worker and runs exactly one queued cleanup', function (): void {
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
    expect($this->incus->instances)->toBe([])
        ->and(DB::table('jobs')->count())->toBe(0)
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
