<?php

declare(strict_types=1);

use App\Actions\Fleet\ConvergeFleetRecipeAction;
use App\Domain\Fleet\FleetServingRelease;
use App\Domain\Problems\ProblemCollector;
use App\Domain\Problems\ProblemRecovery;
use App\Domain\Shared\LifecycleStatus;
use App\Domain\TaskVms\TaskVmException;
use App\Domain\TaskVms\TaskVmHost;
use App\Domain\TaskVms\TaskVmImageBuildState;
use App\Domain\TaskVms\TaskVmImageBuildStep;
use App\Domain\TaskVms\TaskVmSettings;
use App\Infrastructure\AgentCommands\AgentCommandRefused;
use App\Infrastructure\AgentCommands\AgentRunnerFailure;
use App\Infrastructure\AgentCommands\GuestAgentCommandRunner;
use App\Infrastructure\MachineSoftware\RecipeLoader;
use App\Infrastructure\Processes\CommandResult;
use App\Infrastructure\Ssh\HostKey;
use App\Infrastructure\Ssh\KnownHostsStore;
use App\Infrastructure\Ssh\SshExecutor;
use App\Infrastructure\Ssh\SshKeyProvider;
use App\Infrastructure\TaskVms\IncusTaskVmBaseImage;
use App\Infrastructure\TaskVms\RecipeImageBuilds;
use App\Infrastructure\TaskVms\RecipeImageBuildSteps;
use App\Infrastructure\TaskVms\RecipeImagePruning;
use App\Infrastructure\TaskVms\TaskVmImageBuildChain;
use App\Jobs\TaskVms\ImageBuild\BuildRecipeImageStep;
use App\Jobs\TaskVms\ImageBuild\PromoteImage;
use App\Jobs\TaskVms\RefreshTaskVmBaseImages;
use App\Models\Node;
use App\Models\ProblemFingerprint;
use App\Models\TaskVmImageBuild;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Sleep;
use Tests\Support\FakeGuestAgentCommandRunner;
use Tests\Support\RecipeImageAnswers;
use Tests\Support\TaskVmSettingsBuilder;

require_once __DIR__.'/../../Support/FakeIncusImageHost.php';

function recipeImageHost(): Node
{
    return Node::query()->create(['name' => 'recipe-beast', 'status' => LifecycleStatus::Active, 'platform' => 'linux', 'architecture' => 'x86_64', 'public_ssh_host' => '192.0.2.7', 'wireguard_ip' => '10.44.0.7', 'user' => 'orbit']);
}

function recipeImageTransport(Node $host): FakeIncusImageHost
{
    TaskVmSettingsBuilder::bind(['hosts' => [TaskVmSettingsBuilder::host($host->id)]]);
    $incus = new FakeIncusImageHost;
    $incus->bindAgentInventory();
    $incus->recipeImages = true;
    $incus->images = [IMAGE_STOCK => [TaskVmHost::SourceImage], IMAGE_OLD => [TaskVmHost::BaseImage]];
    app()->instance(SshExecutor::class, $incus);
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
    $directory = sys_get_temp_dir().'/recipe-image-artifacts';
    @mkdir($directory.'/sandbox-artifacts', 0700, true);
    file_put_contents($directory.'/sandbox-artifacts/pi-test', 'pinned pi bytes');
    config(['orbit.home' => $directory]);

    return $incus;
}

function recipeImageDocument(string $snapshot = '20261014T000000Z'): string
{
    $data = json_decode(file_get_contents(resource_path('machine-software/recipe.json')), true, flags: JSON_THROW_ON_ERROR);
    $data['ubuntu']['snapshot'] = $snapshot;
    $data['tools']['pi']['artifacts']['amd64']['filename'] = 'pi-test';
    $data['tools']['pi']['artifacts']['amd64']['sha256'] = hash('sha256', 'pinned pi bytes');

    return json_encode($data, JSON_THROW_ON_ERROR);
}

function recipeImageCurrent(string $document): void
{
    $directory = sys_get_temp_dir().'/recipe-image-release';
    @mkdir($directory.'/releases/current/apps/gateway/resources/machine-software', 0700, true);
    if (! is_link($directory.'/orbit')) {
        symlink('releases/current', $directory.'/orbit');
    }
    file_put_contents($directory.'/orbit/apps/gateway/resources/machine-software/recipe.json', $document);
    config(['orbit.gateway_checkout' => $directory.'/orbit/apps/gateway']);
}

function runRecipeImageBuild(TaskVmImageBuild $build, bool $promote = true): void
{
    foreach (app(TaskVmImageBuildChain::class)->jobs($build) as $job) {
        if (! $promote && $job instanceof PromoteImage) {
            break;
        }
        $job->handle(app(RecipeImageBuildSteps::class));
        $build->refresh();
    }
}

beforeEach(function (): void {
    Bus::fake();
    Sleep::fake(syncWithCarbon: true);
});

it('configures snapshot apt before installing packages in the Incus recipe chain', function (): void {
    $build = new TaskVmImageBuild(['attempt' => 1]);
    $build->id = 1;

    $jobs = app(TaskVmImageBuildChain::class)->jobs($build);
    $phases = array_map(static fn (BuildRecipeImageStep $job): string => $job->phase,
        array_values(array_filter($jobs, static fn ($job): bool => $job instanceof BuildRecipeImageStep)));

    expect($phases)->toBe(['reset', 'launch', 'boot', 'apt-source', 'packages', 'javascript', 'rust', 'agent', 'pi', 'prepare', 'clean', 'publish', 'smoke', 'topology-seed']);
});

it('keeps aliases for base and every role within the Incus name limit', function (string $prefix): void {
    $identity = 'sha256:0bd780fc6472'.str_repeat('a', 52);
    $aliases = [];
    foreach (['base', 'gateway', 'app-dev', 'app-prod', 'operator'] as $profile) {
        $alias = RecipeImageBuilds::alias($prefix, $identity, $profile, 42);
        expect(strlen($alias))->toBeLessThanOrEqual(64);
        expect($alias)->toEndWith('-recipe-0bd780fc6472-42-'.$profile);
        $aliases[] = $alias;
    }
    expect(array_unique($aliases))->toHaveCount(5);
})->with([TaskVmHost::BaseImage, 'shark-task-base', str_repeat('a', 254)]);

it('identifies publish failures as host commands without requesting a guest log', function (): void {
    $host = recipeImageHost();
    $incus = recipeImageTransport($host);
    recipeImageCurrent(recipeImageDocument());
    $build = app(RecipeImageBuilds::class)->reconcile()[0];
    $incus->fail = 'publish';

    expect(fn () => runRecipeImageBuild($build))->toThrow(TaskVmException::class);

    $failed = array_find($build->refresh()->steps, fn (array $step): bool => $step['program'] === 'base:publish');
    expect($failed['error'])->toContain('`incus publish`', 'Error: publish failed', 'Publishing runs on Incus host [recipe-beast].')
        ->not->toContain('Builder log unavailable', '/var/log/orbit/recipe-images/base-publish.log');
});

/** Each queued result is supplied by the agent; these tests exercise the persisted build state. */
function recipeImageReplies(array $replies): FakeGuestAgentCommandRunner
{
    $agents = app(GuestAgentCommandRunner::class);
    $answer = $agents->answer;
    $agents->answer = static function ($guest, string $command, array $input) use (&$replies, $answer): array {
        if (! in_array($command, ['image.inspect-phase', 'image.start-phase', 'image.restart-phase'], true)) {
            return $answer($guest, $command, $input);
        }
        if ($replies === []) {
            throw new LogicException('Unexpected image phase request: '.$command);
        }
        $reply = array_shift($replies);
        if ($reply instanceof Throwable) {
            throw $reply;
        }

        return $reply;
    };

    return $agents;
}

it('persists the boot fence before starting and reconnects after a lost acknowledgement', function (): void {
    $host = recipeImageHost();
    recipeImageTransport($host);
    recipeImageCurrent(recipeImageDocument());
    $build = app(RecipeImageBuilds::class)->reconcile()[0];
    $agents = recipeImageReplies([
        RecipeImageAnswers::observation('missing'),
        new AgentRunnerFailure('image.start-phase', AgentRunnerFailure::Transport, 'Start acknowledgement lost'),
        RecipeImageAnswers::observation('running'),
        RecipeImageAnswers::observation('complete'),
    ]);

    for ($delivery = 0; $delivery < 3; $delivery++) {
        new BuildRecipeImageStep($build->id, 'base', 'packages')->handle(app(RecipeImageBuildSteps::class));
        $build->refresh();
        expect($build->steps[0]['phase_state']['boot_id'])->toBe(RecipeImageAnswers::Boot);
        expect($build->steps[0]['error'])->toBeNull();
        expect($build->steps[0]['seconds'] !== null)->toBe($delivery === 2);
    }
    expect(array_column($agents->calls, 'command'))->toBe(['image.inspect-phase', 'image.start-phase', 'image.inspect-phase', 'image.inspect-phase']);
    expect($agents->calls[1]['input']['boot_id'])->toBe(RecipeImageAnswers::Boot);
});

it('retries an unobserved start with the persisted boot fence and records a reboot refusal', function (bool $reboot): void {
    $host = recipeImageHost();
    recipeImageTransport($host);
    recipeImageCurrent(recipeImageDocument());
    $build = app(RecipeImageBuilds::class)->reconcile()[0];
    $agents = recipeImageReplies([
        RecipeImageAnswers::observation('missing'),
        new AgentRunnerFailure('image.start-phase', AgentRunnerFailure::Transport, 'Start was not observed'),
        ...($reboot
            ? [new AgentCommandRefused('image.inspect-phase', 'task_vm.image_builder_rebooted', 'The builder rebooted. Retry the image build.')]
            : [RecipeImageAnswers::observation('missing'), RecipeImageAnswers::observation('complete')]),
    ]);
    new BuildRecipeImageStep($build->id, 'base', 'packages')->handle(app(RecipeImageBuildSteps::class));

    $next = fn () => new BuildRecipeImageStep($build->id, 'base', 'packages')->handle(app(RecipeImageBuildSteps::class));
    if ($reboot) {
        expect($next)->toThrow(TaskVmException::class, 'builder rebooted');
        expect($build->refresh()->state)->toBe(TaskVmImageBuildState::Failed);
    } else {
        $next();
        expect($build->refresh()->steps[0]['seconds'])->not->toBeNull();
    }
    expect($agents->calls[2]['input']['boot_id'])->toBe(RecipeImageAnswers::Boot);
    expect(count(array_filter($agents->calls, fn (array $call): bool => $call['command'] === 'image.start-phase')))->toBe($reboot ? 1 : 2);
})->with([false, true]);

it('retries an unreadable observation without another start', function (array|Throwable $reply): void {
    $host = recipeImageHost();
    recipeImageTransport($host);
    recipeImageCurrent(recipeImageDocument());
    $build = app(RecipeImageBuilds::class)->reconcile()[0];
    $agents = recipeImageReplies([
        RecipeImageAnswers::observation('missing'), RecipeImageAnswers::observation('running'),
        $reply, RecipeImageAnswers::observation('complete'),
    ]);
    new BuildRecipeImageStep($build->id, 'base', 'packages')->handle(app(RecipeImageBuildSteps::class));
    new BuildRecipeImageStep($build->id, 'base', 'packages')->handle(app(RecipeImageBuildSteps::class));
    expect($build->refresh()->steps[0]['error'])->toBeNull();
    expect($build->steps[0]['seconds'])->toBeNull();

    new BuildRecipeImageStep($build->id, 'base', 'packages')->handle(app(RecipeImageBuildSteps::class));

    expect($build->refresh()->steps[0]['seconds'])->not->toBeNull();
    expect(array_column($agents->calls, 'command'))->toBe(['image.inspect-phase', 'image.start-phase', 'image.inspect-phase', 'image.inspect-phase']);
})->with([
    'empty observation' => [[]],
    'incomplete observation' => [['state' => 'running']],
    'transport loss' => [new AgentRunnerFailure('image.inspect-phase', AgentRunnerFailure::Transport, 'Guest unavailable')],
    'truncated response' => [new AgentRunnerFailure('image.inspect-phase', AgentRunnerFailure::MalformedResult, 'Truncated result')],
]);

it('ends unknown observations at the persisted bound and redacts the recorded diagnostic', function (): void {
    $host = recipeImageHost();
    recipeImageTransport($host);
    recipeImageCurrent(recipeImageDocument());
    $build = app(RecipeImageBuilds::class)->reconcile()[0];
    recipeImageReplies([
        RecipeImageAnswers::observation('missing'), RecipeImageAnswers::observation('running'),
        new AgentRunnerFailure('image.inspect-phase', AgentRunnerFailure::Transport, 'API_TOKEN=do-not-store-this'),
    ]);
    new BuildRecipeImageStep($build->id, 'base', 'packages')->handle(app(RecipeImageBuildSteps::class));
    $steps = $build->refresh()->steps;
    $steps[0]['phase_state']['observed_at'] = microtime(true) - 1501;
    $build->update(['steps' => $steps]);

    expect(fn () => new BuildRecipeImageStep($build->id, 'base', 'packages')->handle(app(RecipeImageBuildSteps::class)))
        ->toThrow(TaskVmException::class, 'The guest phase outcome is unknown');

    expect($build->refresh()->state)->toBe(TaskVmImageBuildState::Failed);
    expect($build->steps[0]['error'])->toContain('[REDACTED]')->not->toContain('do-not-store-this');
    $logs = array_filter(app(GuestAgentCommandRunner::class)->calls, fn (array $call): bool => $call['command'] === 'image.recipe-log');
    expect($logs)->not->toBeEmpty();
    foreach ($logs as $log) {
        expect($log['input']['text'])->not->toContain('do-not-store-this');
    }
});

it('resumes a failed build by reusing success or explicitly restarting the failed invocation', function (bool $succeeded): void {
    $host = recipeImageHost();
    $incus = recipeImageTransport($host);
    recipeImageCurrent(recipeImageDocument());
    $build = app(RecipeImageBuilds::class)->reconcile()[0];
    $incus->instances[$build->builder_vm_name] = IMAGE_STOCK;
    $result = RecipeImageAnswers::observation($succeeded ? 'complete' : 'failed', reason: 'failed with exit 100', exit: 100);
    $agents = recipeImageReplies([RecipeImageAnswers::observation('missing'), $result, $result,
        ...($succeeded ? [] : [RecipeImageAnswers::observation('complete', str_repeat('2', 32))])]);
    $run = fn () => new BuildRecipeImageStep($build->id, 'base', 'packages')->handle(app(RecipeImageBuildSteps::class));
    if ($succeeded) {
        $run();
        $steps = $build->refresh()->steps;
        $steps[0]['seconds'] = null;
        $steps[0]['error'] = 'Observation failed after guest success.';
        $build->update(['state' => TaskVmImageBuildState::Failed, 'steps' => $steps]);
    } else {
        expect($run)->toThrow(TaskVmException::class);
    }

    app(TaskVmImageBuildChain::class)->resume($host, $build->id);
    $job = new BuildRecipeImageStep($build->id, 'base', 'packages');
    $job->buildAttempt = 2;
    $job->handle(app(RecipeImageBuildSteps::class));

    expect($build->refresh()->steps[0]['seconds'])->not->toBeNull();
    expect($build->steps[0]['phase_state']['attempt'])->toBe(2);
    expect(array_column($agents->calls, 'command'))->toBe(['image.inspect-phase', 'image.start-phase', ...($succeeded ? [] : ['image.recipe-log']), 'image.inspect-phase', ...($succeeded ? [] : ['image.restart-phase'])]);
    if (! $succeeded) {
        expect(array_last($agents->calls)['input']['invocation_id'])->toBe(str_repeat('1', 32));
    }
})->with([true, false]);

it('adopts a running phase on resume and records its later failure without a restart', function (): void {
    $host = recipeImageHost();
    $incus = recipeImageTransport($host);
    recipeImageCurrent(recipeImageDocument());
    $build = app(RecipeImageBuilds::class)->reconcile()[0];
    $incus->instances[$build->builder_vm_name] = IMAGE_STOCK;
    $agents = recipeImageReplies([
        RecipeImageAnswers::observation('missing'), RecipeImageAnswers::observation('running'),
        RecipeImageAnswers::observation('running'), RecipeImageAnswers::observation('failed', reason: 'killed by signal 9'),
    ]);
    new BuildRecipeImageStep($build->id, 'base', 'packages')->handle(app(RecipeImageBuildSteps::class));
    $build->update(['state' => TaskVmImageBuildState::Failed]);
    app(TaskVmImageBuildChain::class)->resume($host, $build->id);
    $job = new BuildRecipeImageStep($build->id, 'base', 'packages');
    $job->buildAttempt = 2;
    $job->handle(app(RecipeImageBuildSteps::class));

    expect(fn () => $job->handle(app(RecipeImageBuildSteps::class)))->toThrow(TaskVmException::class, 'signal 9');

    expect($build->refresh()->state)->toBe(TaskVmImageBuildState::Failed);
    expect($build->steps[0]['phase_state']['attempt'])->toBe(2);
    expect(array_column($agents->calls, 'command'))->not->toContain('image.restart-phase');
});

it('retains explicit restart intent across a lost acknowledgement', function (bool $unsent, bool $succeeded): void {
    $host = recipeImageHost();
    $incus = recipeImageTransport($host);
    recipeImageCurrent(recipeImageDocument());
    $build = app(RecipeImageBuilds::class)->reconcile()[0];
    $incus->instances[$build->builder_vm_name] = IMAGE_STOCK;
    $failed = RecipeImageAnswers::observation('failed', reason: 'failed with exit 100', exit: 100);
    $next = RecipeImageAnswers::observation($succeeded ? 'complete' : 'failed', str_repeat('2', 32), 'failed with exit 100', 100);
    $agents = recipeImageReplies([RecipeImageAnswers::observation('missing'), $failed, $failed,
        new AgentRunnerFailure('image.restart-phase', AgentRunnerFailure::Transport, 'Lost restart reply'),
        ...($unsent ? [$failed, $next] : [$next])]);
    expect(fn () => new BuildRecipeImageStep($build->id, 'base', 'packages')->handle(app(RecipeImageBuildSteps::class)))->toThrow(TaskVmException::class);
    app(TaskVmImageBuildChain::class)->resume($host, $build->id);
    $job = new BuildRecipeImageStep($build->id, 'base', 'packages');
    $job->buildAttempt = 2;
    $job->handle(app(RecipeImageBuildSteps::class));
    expect($build->refresh()->steps[0]['phase_state']['restart_from'])->toBe(str_repeat('1', 32));

    $job = new BuildRecipeImageStep($build->id, 'base', 'packages');
    $job->buildAttempt = 2;
    if ($succeeded) {
        $job->handle(app(RecipeImageBuildSteps::class));
    } else {
        expect(fn () => $job->handle(app(RecipeImageBuildSteps::class)))->toThrow(TaskVmException::class, 'exit 100');
    }

    expect($build->refresh()->steps[0]['phase_state'])->not->toHaveKey('restart_from');
    expect($build->steps[0]['phase_state']['attempt'])->toBe(2);
    $restarts = array_values(array_filter($agents->calls, fn (array $call): bool => $call['command'] === 'image.restart-phase'));
    expect($restarts)->toHaveCount($unsent ? 2 : 1);
    foreach ($restarts as $restart) {
        expect($restart['input']['invocation_id'])->toBe(str_repeat('1', 32));
    }
})->with([false, true])->with([false, true]);

it('records native phase failures without inventing an exit code', function (string $reason, string $phase): void {
    $host = recipeImageHost();
    recipeImageTransport($host);
    recipeImageCurrent(recipeImageDocument());
    $build = app(RecipeImageBuilds::class)->reconcile()[0];
    recipeImageReplies([RecipeImageAnswers::observation('missing'),
        RecipeImageAnswers::observation('failed', reason: $reason, log: 'retained native diagnostic')]);

    try {
        new BuildRecipeImageStep($build->id, 'base', $phase)->handle(app(RecipeImageBuildSteps::class));
        test()->fail('The native phase failure must fail the build.');
    } catch (TaskVmException $error) {
        expect($error->getMessage())->toContain($reason, 'retained native diagnostic')->not->toContain('exit 9', 'exit 255');
        expect($error->imageFailure['exit_code'])->toBeNull();
    }

    expect($build->refresh()->state)->toBe(TaskVmImageBuildState::Failed);
    expect($build->steps[0]['error'])->toContain($reason);
})->with([
    ['killed by signal 9', 'packages'],
    ['timed out after 1440 seconds', 'packages'],
    ['artifact publication failed: No space left on device', 'pi'],
]);

describe('recipe image triggers', function (): void {
    it('starts a deployed recipe immediately and reuses unchanged running and succeeded builds', function (): void {
        $host = recipeImageHost();
        recipeImageTransport($host);
        recipeImageCurrent(recipeImageDocument());
        app()->call([new RefreshTaskVmBaseImages, 'handle']);
        $build = TaskVmImageBuild::query()->sole();
        expect($build->recipe_identity)->toBe(new RecipeLoader()->decode(recipeImageDocument())->identity);
        expect($build->recipe_json)->toBe(recipeImageDocument());
        app()->call([new RefreshTaskVmBaseImages, 'handle']);
        expect(TaskVmImageBuild::query()->count())->toBe(1);
        runRecipeImageBuild($build);
        app()->call([new RefreshTaskVmBaseImages, 'handle']);
        expect(TaskVmImageBuild::query()->count())->toBe(1);
        Bus::assertDispatchedTimes(BuildRecipeImageStep::class, 1);
    });

    it('builds and smoke-tests only one base on each host while preserving guest DNS', function (): void {
        $first = recipeImageHost();
        $incus = recipeImageTransport($first);
        $second = Node::query()->create(['name' => 'recipe-shark', 'status' => LifecycleStatus::Active, 'platform' => 'linux', 'architecture' => 'x86_64', 'public_ssh_host' => '192.0.2.8', 'wireguard_ip' => '10.44.0.8', 'user' => 'orbit']);
        TaskVmSettingsBuilder::bind(['hosts' => [TaskVmSettingsBuilder::host($first->id), TaskVmSettingsBuilder::host($second->id, ['imageAlias' => 'shark-task-base'])]]);
        recipeImageCurrent(recipeImageDocument());
        $builds = app(RecipeImageBuilds::class)->reconcile();
        expect($builds)->toHaveCount(2);
        foreach ($builds as $build) {
            runRecipeImageBuild($build);
            expect(array_keys($build->images))->toBe(['base']);
            expect(app(TaskVmImageBuildChain::class)->jobs($build))->toHaveCount(16);
            expect($build->state)->toBe(TaskVmImageBuildState::Succeeded);
            foreach ($build->images as $profile => $image) {
                expect($incus->imageProperties[$image['fingerprint']])->toBe(['orbit.recipe' => $build->recipe_identity, 'orbit.profile' => $profile]);
                expect($image)->toMatchArray(['project' => 'orbit-tasks', 'type' => 'virtual-machine']);
                expect(strlen($image['alias']))->toBeLessThanOrEqual(64);
            }
        }
        foreach ($incus->guest as $call) {
            expect(json_encode([$call->argv, $call->input], JSON_THROW_ON_ERROR))->not->toContain('resolved.conf', 'resolvectl');
        }
        $launches = array_values(array_filter($incus->commands, static fn ($command): bool => ($command->arguments[5] ?? '') === 'launch'));
        expect($launches)->toHaveCount(4);
        $base = $builds[0]->images['base']['fingerprint'];
        expect(array_filter($launches, static fn ($command): bool => $command->arguments[count($command->arguments) - 2] === $base))->toHaveCount(1);
        Bus::assertDispatchedTimes(BuildRecipeImageStep::class, 2);
    });

    it('keeps previous images on a base smoke failure and resumes from the failed phase', function (): void {
        $host = recipeImageHost();
        $incus = recipeImageTransport($host);
        recipeImageCurrent(recipeImageDocument());
        $build = app(RecipeImageBuilds::class)->reconcile()[0];
        $incus->fail = 'recipe base:verify';
        expect(fn () => runRecipeImageBuild($build))->toThrow(TaskVmException::class);
        expect($build->refresh()->state)->toBe(TaskVmImageBuildState::Failed);
        expect($incus->images[IMAGE_OLD])->toContain(TaskVmHost::BaseImage);
        expect($build->promoted_at)->toBeNull();
        $incus->fail = null;
        $chain = app(TaskVmImageBuildChain::class);
        $chain->resume($host, $build->id);
        $remaining = false;
        foreach ($chain->jobs($build->refresh()) as $job) {
            $remaining = $remaining || $job->program() === 'base:smoke';
            if ($remaining) {
                $job->handle(app(RecipeImageBuildSteps::class));
            }
        }
        expect($build->refresh()->state)->toBe(TaskVmImageBuildState::Succeeded);
    });

    it('fences an older build before promotion while the newer recipe waits for its host', function (): void {
        $host = recipeImageHost();
        $incus = recipeImageTransport($host);
        recipeImageCurrent(recipeImageDocument());
        $old = app(RecipeImageBuilds::class)->reconcile()[0];
        runRecipeImageBuild($old, promote: false);
        recipeImageCurrent(recipeImageDocument('20261015T000000Z'));
        expect(app(RecipeImageBuilds::class)->reconcile())->toBe([]);
        expect(fn () => new PromoteImage($old->id)->handle(app(RecipeImageBuildSteps::class)))
            ->toThrow(TaskVmException::class, 'newer recipe');
        expect($incus->images[IMAGE_OLD])->toContain(TaskVmHost::BaseImage);
        $new = app(RecipeImageBuilds::class)->reconcile()[0];
        runRecipeImageBuild($new);
        expect($new->refresh()->recipe_generation)->toBe(2);
        expect($incus->images[$new->image_fingerprint])->toContain(TaskVmHost::BaseImage);
        expect(fn () => app(TaskVmImageBuildChain::class)->resume($host, $old->id))->toThrow(TaskVmException::class, 'newer recipe');
    });

    it('keeps the stored recipe during a release and prints image locations from the manual commands', function (): void {
        $host = recipeImageHost();
        recipeImageTransport($host);
        recipeImageCurrent(recipeImageDocument());
        $this->artisan('task-vms:build-recipe')->expectsOutputToContain('recipe-beast')->assertSuccessful();
        $build = TaskVmImageBuild::query()->sole();
        $identity = $build->recipe_identity;
        recipeImageCurrent(recipeImageDocument('20261015T000000Z'));
        runRecipeImageBuild($build, promote: false);
        expect($build->recipe_identity)->toBe($identity);
        $this->artisan('task-vms:image-builds')->expectsOutputToContain($identity)
            ->expectsOutputToContain($build->images['base']['alias'])->expectsOutputToContain('base:smoke')->assertSuccessful();
        expect(DB::table('task_vm_image_recipe')->value('generation'))->toBe(1);
    });
});

it('retries a discarded failure without replacing already published recipe images', function (): void {
    $host = recipeImageHost();
    $incus = recipeImageTransport($host);
    recipeImageCurrent(recipeImageDocument());
    $build = app(RecipeImageBuilds::class)->reconcile()[0];
    $incus->fail = 'recipe base:verify';
    expect(fn () => runRecipeImageBuild($build))->toThrow(TaskVmException::class);
    $base = $build->refresh()->image_fingerprint;
    app(IncusTaskVmBaseImage::class)->sweep(app(TaskVmSettings::class)->host($host->id), $host, discardFailed: true);
    $incus->fail = null;
    $this->artisan('task-vms:build-recipe', ['--retry' => true])->assertSuccessful();
    runRecipeImageBuild($build->refresh());
    expect($build->state)->toBe(TaskVmImageBuildState::Succeeded);
    expect($build->image_fingerprint)->toBe($base);
    expect($build->attempt)->toBe(2);
});

it('reactivates verified images when the recipe is explicitly reverted without rebuilding them', function (): void {
    $host = recipeImageHost();
    $incus = recipeImageTransport($host);
    recipeImageCurrent(recipeImageDocument());
    $first = app(RecipeImageBuilds::class)->reconcile()[0];
    runRecipeImageBuild($first);
    recipeImageCurrent(recipeImageDocument('20261015T000000Z'));
    runRecipeImageBuild(app(RecipeImageBuilds::class)->reconcile()[0]);
    recipeImageCurrent(recipeImageDocument());
    $reactivated = app(RecipeImageBuilds::class)->reconcile()[0];
    $jobs = app(TaskVmImageBuildChain::class)->jobs($reactivated);
    foreach (array_slice($jobs, -2) as $job) {
        $job->handle(app(RecipeImageBuildSteps::class));
    }
    expect($reactivated->refresh()->state)->toBe(TaskVmImageBuildState::Succeeded);
    expect($incus->images[$first->image_fingerprint])->toContain(TaskVmHost::BaseImage);
    expect(TaskVmImageBuild::query()->count())->toBe(2);
});

it('lets the first recipe worker acknowledge a submission before the dispatcher reply arrives', function (): void {
    $host = recipeImageHost();
    recipeImageTransport($host);
    recipeImageCurrent(recipeImageDocument());
    $build = app(RecipeImageBuilds::class)->reconcile()[0];
    $build->update(['submission_pending_at' => now()->toISOString()]);
    $job = app(TaskVmImageBuildChain::class)->jobs($build)[0];
    $job->handle(app(RecipeImageBuildSteps::class));
    expect($build->refresh()->current_program)->toBe('base:reset');
    expect($build->submission_pending_at)->toBeNull();
});

it('automatically retries a missed queue submission without retrying a failed image phase', function (): void {
    $host = recipeImageHost();
    recipeImageTransport($host);
    recipeImageCurrent(recipeImageDocument());
    $build = app(RecipeImageBuilds::class)->reconcile()[0];
    $build->update(['submission_pending_at' => now()->subMinutes(11)->toISOString()]);
    app(TaskVmImageBuildChain::class)->recoverSubmissions();
    expect($build->refresh()->state)->toBe(TaskVmImageBuildState::Failed);
    app(RecipeImageBuilds::class)->reconcile();
    expect($build->refresh()->state)->toBe(TaskVmImageBuildState::Running);
    expect($build->attempt)->toBe(2);
});

it('records a bounded redacted output tail and a real builder log for failed recipe phases', function (string $phase): void {
    $host = recipeImageHost();
    $incus = recipeImageTransport($host);
    recipeImageCurrent(recipeImageDocument());
    $build = app(RecipeImageBuilds::class)->reconcile()[0];
    $incus->fail = 'recipe base:'.$phase;
    $incus->recipeFailure = new CommandResult(1, "stdout diagnostic\n".implode("\n", array_map(fn (int $n): string => "line {$n}", range(1, 60))),
        "API_TOKEN=do-not-store-this\nAuthorization: Bearer do-not-store-that\napt dependency conflict", 1, false);

    expect(fn () => runRecipeImageBuild($build))->toThrow(TaskVmException::class);

    $logPhase = $phase === 'verify' ? 'smoke' : $phase;
    $failed = array_find($build->refresh()->steps, fn (array $step): bool => $step['program'] === 'base:'.$logPhase);
    expect($failed['error'])->toContain('apt dependency conflict', 'line 60', '[REDACTED]',
        '/var/log/orbit/recipe-images/base-'.$logPhase.'.log', $build->builder_vm_name)
        ->not->toContain('stdout diagnostic', 'line 1'."\n", 'do-not-store-this', 'do-not-store-that');
    expect(mb_strlen($failed['error']))->toBeLessThanOrEqual(4000);
    expect(substr_count($failed['error'], 'apt dependency conflict'))->toBe(1);
    $logs = array_filter(app(GuestAgentCommandRunner::class)->calls, static fn (array $call): bool => $call['command'] === 'image.recipe-log' && str_contains($call['input']['text'], 'Gateway observation:'));
    expect($logs)->not->toBeEmpty();
    foreach ($logs as $log) {
        expect($log['input']['text'])->not->toContain('do-not-store-this', 'do-not-store-that', 'apt dependency conflict');
    }
})->with(['packages', 'javascript', 'rust', 'agent']);

it('records a native verification refusal on the smoke step and preserves the current image', function (): void {
    $host = recipeImageHost();
    $incus = recipeImageTransport($host);
    recipeImageCurrent(recipeImageDocument());
    $build = app(RecipeImageBuilds::class)->reconcile()[0];
    $incus->fail = 'recipe base:verify';
    $incus->recipeFailure = new CommandResult(1, '', 'PHP module mismatch API_TOKEN=private-token', 1, false);

    expect(fn () => runRecipeImageBuild($build))->toThrow(TaskVmException::class, 'PHP module mismatch');

    $failed = array_find($build->refresh()->steps, fn (array $step): bool => $step['program'] === 'base:smoke');
    expect($failed['error'])->toContain('PHP module mismatch', '[REDACTED]', $build->builder_vm_name.'-smoke')
        ->not->toContain('private-token');
    expect($build->state)->toBe(TaskVmImageBuildState::Failed);
    expect($build->promoted_at)->toBeNull();
    expect($incus->images[IMAGE_OLD])->toContain(TaskVmHost::BaseImage);
});

/** Simulate the four role images published before the base-only chain. */
function recipeImageLegacyRoles(TaskVmImageBuild $build, FakeIncusImageHost $incus): void
{
    $images = $build->images;
    foreach (['gateway', 'app-dev', 'app-prod', 'operator'] as $profile) {
        $alias = RecipeImageBuilds::alias(TaskVmHost::BaseImage, $build->recipe_identity, $profile, $build->id);
        $fingerprint = hash('sha256', $alias);
        $incus->images[$fingerprint] = [$alias];
        $images[$profile] = ['alias' => $alias, 'fingerprint' => $fingerprint, 'project' => 'orbit-tasks', 'type' => 'virtual-machine'];
    }
    $build->update(['images' => $images]);
}

it('builds the topology seed in the smoke VM, then deletes the VM and gives the seed its final name', function (): void {
    $host = recipeImageHost();
    $incus = recipeImageTransport($host);
    recipeImageCurrent(recipeImageDocument());
    $build = app(RecipeImageBuilds::class)->reconcile()[0];

    runRecipeImageBuild($build);

    $fingerprint = $build->images['base']['fingerprint'];
    $seed = 'orbit-topology-seed-'.substr($fingerprint, 0, 12);
    expect($incus->seeds)->toBe([['project' => 'orbit-tasks', 'pool' => 'orbit-tasks', 'vm' => 'tvm-image-1-smoke',
        'size' => '45GiB', 'fingerprint' => $fingerprint]])
        ->and($incus->volumes)->toBe([$seed])
        ->and($incus->instances)->not->toHaveKey('tvm-image-1-smoke');
    $order = $incus->events;
    $built = array_search('incus.build-topology-seed', $order, true);
    $deleted = min(array_filter(array_keys($order, 'delete --force -- tvm-image-1-smoke', true), static fn (int $index): bool => $index > $built));
    $renamed = array_find_key($incus->events, static fn (string $line): bool => $line === 'incus.volume.finish_seed');
    expect($built)->toBeLessThan($deleted)->and($deleted)->toBeLessThan($renamed);
    expect(array_find($build->steps, static fn (array $step): bool => $step['program'] === 'base:topology-seed')['error'])->toBeNull()
        ->and($build->promoted_at)->not->toBeNull();
});

it('fails the phase with the agent diagnostic and no guest log, and builds the seed again in a fresh smoke VM on resume', function (bool $smokeGone): void {
    $host = recipeImageHost();
    $incus = recipeImageTransport($host);
    recipeImageCurrent(recipeImageDocument());
    $build = app(RecipeImageBuilds::class)->reconcile()[0];
    // The agent created the `-new` volume and changed the smoke VM before it failed.
    $incus->fail = 'topology seed';
    expect(fn () => runRecipeImageBuild($build))->toThrow(TaskVmException::class, 'topology seed');
    $failed = array_find($build->refresh()->steps, static fn (array $step): bool => $step['program'] === 'base:topology-seed');
    expect($failed['error'])->toContain('Building the topology seed in [tvm-image-1-smoke] failed: Error: topology seed failed', 'Error: topology seed failed')
        ->not->toContain('/var/log/orbit/recipe-images/base-topology-seed.log')
        ->and($build->promoted_at)->toBeNull()
        ->and($incus->instances)->toHaveKey('tvm-image-1-smoke');
    expect(array_filter($incus->guest, static fn ($call): bool => str_contains(implode(' ', $call->argv), 'topology-seed')))->toBe([]);

    $incus->fail = null;
    $incus->afterExecute = null;
    if ($smokeGone) {
        unset($incus->instances['tvm-image-1-smoke']);
    }
    $chain = app(TaskVmImageBuildChain::class);
    $chain->resume($host, $build->id);
    $remaining = false;
    foreach ($chain->jobs($build->refresh()) as $job) {
        $remaining = $remaining || $job->program() === 'base:topology-seed';
        if ($remaining) {
            $job->handle(app(RecipeImageBuildSteps::class));
        }
    }
    $seed = 'orbit-topology-seed-'.substr((string) $build->refresh()->image_fingerprint, 0, 12);
    expect($build->state)->toBe(TaskVmImageBuildState::Succeeded)
        ->and($incus->volumes)->toBe([$seed])
        ->and($incus->instances)->not->toHaveKey('tvm-image-1-smoke')
        ->and(array_filter($incus->commands, static fn ($command): bool => ($command->arguments[5] ?? '') === 'launch' && array_last($command->arguments) === 'tvm-image-1-smoke'))->toHaveCount(2);

    $seeds = count($incus->seeds);
    app(RecipeImageBuildSteps::class)->run($build, TaskVmImageBuildStep::Recipe, 'base:topology-seed');
    expect($incus->seeds)->toHaveCount($seeds);
})->with(['smoke VM kept' => [false], 'smoke VM gone' => [true]]);

it('refuses to promote a base without its topology seed', function (): void {
    $host = recipeImageHost();
    recipeImageTransport($host);
    recipeImageCurrent(recipeImageDocument());
    $build = app(RecipeImageBuilds::class)->reconcile()[0];
    runRecipeImageBuild($build, promote: false);
    $build->forceFill(['steps' => array_values(array_filter($build->steps, static fn (array $step): bool => $step['program'] !== 'base:topology-seed'))])->save();

    expect(fn () => app(RecipeImageBuildSteps::class)->promote($build))->toThrow(TaskVmException::class, 'have its topology seed before promotion');
});

it('prunes every profile of older identities while keeping the previous recipe and images in use', function (): void {
    $host = recipeImageHost();
    $incus = recipeImageTransport($host);
    recipeImageCurrent(recipeImageDocument());
    $old = app(RecipeImageBuilds::class)->reconcile()[0];
    runRecipeImageBuild($old);
    recipeImageLegacyRoles($old, $incus);
    $used = $old->images['app-dev']['fingerprint'];
    $shared = $old->images['operator']['fingerprint'];
    $incus->instances['tvm-running-task'] = $used;
    $incus->images[$shared][] = 'operator-kept';
    recipeImageCurrent(recipeImageDocument('20261015T000000Z'));
    $previous = app(RecipeImageBuilds::class)->reconcile()[0];
    runRecipeImageBuild($previous);
    recipeImageLegacyRoles($previous, $incus);
    recipeImageCurrent(recipeImageDocument('20261016T000000Z'));
    $current = app(RecipeImageBuilds::class)->reconcile()[0];

    runRecipeImageBuild($current);

    expect($current->state)->toBe(TaskVmImageBuildState::Succeeded);
    foreach ([$current, $previous] as $kept) {
        expect($incus->images)->toHaveKey($kept->images['base']['fingerprint']);
    }
    foreach (['gateway', 'app-dev', 'app-prod', 'operator'] as $profile) {
        expect($incus->images)->not->toHaveKey($previous->images[$profile]['fingerprint']);
    }
    expect($incus->images[$used])->toBe([$old->images['app-dev']['alias']]);
    expect($incus->images[$shared])->toBe(['operator-kept']);
    expect($incus->images)->toHaveKeys([$used, $shared, IMAGE_STOCK]);
    foreach (['base', 'gateway', 'app-prod'] as $profile) {
        expect($incus->images)->not->toHaveKey($old->images[$profile]['fingerprint']);
    }
    // A base goes with its topology seed; a kept base keeps its own.
    $seed = static fn (TaskVmImageBuild $build): string => 'orbit-topology-seed-'.substr((string) $build->images['base']['fingerprint'], 0, 12);
    expect($incus->volumes)->toEqualCanonicalizing([$seed($previous), $seed($current)]);
    // A seed that a failed build left under construction goes with its base too.
    $incus->volumes[] = $seed($previous).'-new';
    recipeImageCurrent(recipeImageDocument('20261017T000000Z'));
    $next = app(RecipeImageBuilds::class)->reconcile()[0];
    runRecipeImageBuild($next);
    expect($incus->volumes)->toEqualCanonicalizing([$seed($current), $seed($next)]);
    unset($incus->instances['tvm-running-task']);
    app(RecipeImagePruning::class)->prune($current);
    expect($incus->images)->not->toHaveKey($used);
});

it('resumes a failed final prune without rebuilding or moving the base alias again', function (): void {
    $host = recipeImageHost();
    $incus = recipeImageTransport($host);
    foreach (['20261014T000000Z', '20261015T000000Z'] as $snapshot) {
        recipeImageCurrent(recipeImageDocument($snapshot));
        runRecipeImageBuild(app(RecipeImageBuilds::class)->reconcile()[0]);
    }
    recipeImageCurrent(recipeImageDocument('20261016T000000Z'));
    $current = app(RecipeImageBuilds::class)->reconcile()[0];
    $incus->fail = 'image delete';

    expect(fn () => runRecipeImageBuild($current))->toThrow(TaskVmException::class);
    expect($current->refresh()->current_step)->toBe(TaskVmImageBuildStep::Prune)
        ->and($current->state)->toBe(TaskVmImageBuildState::Failed)
        ->and($current->promoted_at)->not->toBeNull();
    $incus->fail = null;
    $incus->commands = [];
    app(TaskVmImageBuildChain::class)->resume($host, $current->id);
    runRecipeImageBuild($current->refresh());

    expect($current->state)->toBe(TaskVmImageBuildState::Succeeded);
    foreach ($incus->commands as $command) {
        expect(array_slice($command->arguments, 5, 2))->not->toBe(['image', 'alias']);
        expect($command->arguments)->not->toContain('publish', 'launch', 'PATCH');
    }
});

it('keeps old build rows readable but refuses to resume the removed build path', function (): void {
    $host = recipeImageHost();
    recipeImageTransport($host);
    $legacy = TaskVmImageBuild::query()->create(['host_node_id' => $host->id, 'state' => 'failed',
        'current_step' => 'upgrade-packages', 'source_alias' => TaskVmHost::SourceImage, 'from_stock' => true]);

    $this->artisan('task-vms:image-builds')->expectsOutputToContain(TaskVmHost::SourceImage)->assertSuccessful();
    expect(fn () => app(TaskVmImageBuildChain::class)->resume($host, $legacy->id))->toThrow(TaskVmException::class, 'Legacy image builds cannot resume');
});

it('rebuilds a reverted identity after its images were pruned', function (): void {
    $host = recipeImageHost();
    $incus = recipeImageTransport($host);
    $first = null;
    foreach (['20261014T000000Z', '20261015T000000Z', '20261016T000000Z'] as $snapshot) {
        recipeImageCurrent(recipeImageDocument($snapshot));
        $build = app(RecipeImageBuilds::class)->reconcile()[0];
        $first ??= $build;
        runRecipeImageBuild($build);
    }
    expect($incus->images)->not->toHaveKey($first->image_fingerprint);
    recipeImageCurrent(recipeImageDocument());

    $reverted = app(RecipeImageBuilds::class)->reconcile()[0];
    expect($reverted->images)->toBe([])->and($reverted->current_step)->toBeNull();
    runRecipeImageBuild($reverted);

    expect($reverted->id)->toBe($first->id)->and($reverted->state)->toBe(TaskVmImageBuildState::Succeeded);
    expect($incus->images[$reverted->image_fingerprint])->toContain(TaskVmHost::BaseImage);
});

it('prunes superseded failed recipes but keeps images without host-local ownership records', function (): void {
    $host = recipeImageHost();
    $incus = recipeImageTransport($host);
    recipeImageCurrent(recipeImageDocument());
    $old = app(RecipeImageBuilds::class)->reconcile()[0];
    runRecipeImageBuild($old);
    $old->update(['state' => TaskVmImageBuildState::Failed]);
    $foreign = Node::query()->create(['name' => 'recipe-shark', 'status' => 'active', 'public_ssh_host' => '192.0.2.8']);
    $identity = 'sha256:'.str_repeat('f', 64);
    TaskVmImageBuild::query()->create(['host_node_id' => $foreign->id, 'recipe_identity' => $identity, 'state' => 'succeeded']);
    $incus->images[IMAGE_USED] = [RecipeImageBuilds::alias(TaskVmHost::BaseImage, $identity, 'base', 999)];
    foreach (['20261015T000000Z', '20261016T000000Z'] as $snapshot) {
        recipeImageCurrent(recipeImageDocument($snapshot));
        $current = app(RecipeImageBuilds::class)->reconcile($host->id)[0];
        runRecipeImageBuild($current);
    }

    expect($incus->images)->not->toHaveKey($old->image_fingerprint)->toHaveKey(IMAGE_USED);
});

it('retires current recipe role aliases without deleting its base or another running build', function (): void {
    $host = recipeImageHost();
    $incus = recipeImageTransport($host);
    recipeImageCurrent(recipeImageDocument());
    $current = app(RecipeImageBuilds::class)->reconcile()[0];
    runRecipeImageBuild($current);
    recipeImageLegacyRoles($current, $incus);
    $roles = $current->images;
    $sharedAlias = $roles['gateway']['alias'];
    unset($incus->images[$roles['gateway']['fingerprint']]);
    $incus->images[$current->image_fingerprint][] = $sharedAlias;
    $roles['gateway']['fingerprint'] = $current->image_fingerprint;
    $current->update(['images' => $roles]);
    $running = TaskVmImageBuild::query()->create(['host_node_id' => $host->id,
        'recipe_identity' => 'sha256:'.str_repeat('e', 64), 'state' => 'running']);
    recipeImageLegacyRoles($running, $incus);

    app(RecipeImagePruning::class)->prune($current);

    expect($incus->images[$current->image_fingerprint])->toBe([$roles['base']['alias'], TaskVmHost::BaseImage]);
    foreach (['app-dev', 'app-prod', 'operator'] as $profile) {
        expect($incus->images)->not->toHaveKey($roles[$profile]['fingerprint']);
    }
    foreach ($running->images as $image) {
        expect($incus->images[$image['fingerprint']])->toBe([$image['alias']]);
    }
    $running->delete();
    recipeImageCurrent(recipeImageDocument('20261015T000000Z'));
    runRecipeImageBuild(app(RecipeImageBuilds::class)->reconcile()[0]);
    recipeImageCurrent(recipeImageDocument());
    $reactivated = app(RecipeImageBuilds::class)->reconcile()[0];
    expect($reactivated->current_step)->toBe(TaskVmImageBuildStep::Promote)
        ->and($reactivated->image_fingerprint)->toBe($current->image_fingerprint);
});

it('cold rebuilds the same recipe from stock and swaps its image only after the candidate passes', function (): void {
    $host = recipeImageHost();
    $incus = recipeImageTransport($host);
    recipeImageCurrent(recipeImageDocument());
    $builds = app(RecipeImageBuilds::class);
    $old = $builds->reconcile()[0];
    runRecipeImageBuild($old);
    $previous = $old->images['base'];

    $this->artisan('task-vms:build-recipe', ['--rebuild' => true])->assertSuccessful();
    $candidate = TaskVmImageBuild::query()->latest('id')->firstOrFail();
    expect($candidate->id)->not->toBe($old->id)
        ->and($candidate->recipe_identity)->toBe($old->recipe_identity)
        ->and($candidate->recipe_generation)->toBe($old->recipe_generation)
        ->and($candidate->from_stock)->toBeTrue()
        ->and($candidate->images)->toBe([])
        ->and($candidate->steps)->toBe([])
        ->and($incus->images[$previous['fingerprint']])->toContain(TaskVmHost::BaseImage);
    expect($builds->reconcile()[0]->id)->toBe($candidate->id);
    $incus->fail = 'recipe base:verify';
    expect(fn () => runRecipeImageBuild($candidate))->toThrow(TaskVmException::class);
    expect($candidate->refresh()->state)->toBe(TaskVmImageBuildState::Failed)
        ->and($candidate->promoted_at)->toBeNull()
        ->and($old->refresh()->state)->toBe(TaskVmImageBuildState::Succeeded)
        ->and($incus->images[$previous['fingerprint']])->toContain(TaskVmHost::BaseImage)
        ->and($candidate->images['base']['alias'])->not->toBe($previous['alias']);
    $incus->fail = null;
    $replacement = $builds->reconcile(rebuild: true)[0];
    runRecipeImageBuild($replacement);
    expect($replacement->refresh()->state)->toBe(TaskVmImageBuildState::Succeeded)
        ->and($replacement->source_fingerprint)->toBe(IMAGE_STOCK)
        ->and($incus->images[$replacement->image_fingerprint])->toContain(TaskVmHost::BaseImage)
        ->and($incus->images[$previous['fingerprint']])->toContain($previous['alias']);
    expect($incus->images[$previous['fingerprint']])->not->toContain(TaskVmHost::BaseImage);
});

it('refuses cold rebuilds atomically while a recipe or configured host is building', function (): void {
    $host = recipeImageHost();
    recipeImageTransport($host);
    recipeImageCurrent(recipeImageDocument());
    $build = app(RecipeImageBuilds::class)->reconcile()[0];
    $generation = DB::table('task_vm_image_recipe')->value('generation');

    $this->artisan('task-vms:build-recipe', ['--rebuild' => true])->expectsOutputToContain('running')->assertFailed();
    $this->artisan('task-vms:build-recipe', ['--rebuild' => true, '--retry' => true])->expectsOutputToContain('cannot be combined')->assertFailed();
    expect(DB::table('task_vm_image_recipe')->value('generation'))->toBe($generation);
    $this->assertDatabaseCount('task_vm_image_builds', 1);
    expect($build->refresh()->state)->toBe(TaskVmImageBuildState::Running);
});

it('collects an owned cold candidate after publish succeeded but its reply was lost', function (): void {
    $host = recipeImageHost();
    $incus = recipeImageTransport($host);
    recipeImageCurrent(recipeImageDocument());
    $builds = app(RecipeImageBuilds::class);
    $old = $builds->reconcile()[0];
    runRecipeImageBuild($old);
    $interrupted = $builds->reconcile(rebuild: true)[0];
    $incus->afterImage = static function (array $operation): void {
        if ($operation['kind'] === 'publish') {
            throw new RuntimeException('The publish reply was lost.');
        }
    };

    expect(fn () => runRecipeImageBuild($interrupted))->toThrow(TaskVmException::class);
    $alias = RecipeImageBuilds::alias(TaskVmHost::BaseImage, $old->recipe_identity, 'base', $interrupted->id);
    $orphan = hash('sha256', $alias);
    expect($interrupted->refresh()->images)->toBe([])
        ->and($incus->images)->toHaveKey($orphan);
    $incus->afterImage = null;
    $replacement = $builds->reconcile(rebuild: true)[0];
    runRecipeImageBuild($replacement);

    expect($incus->images)->not->toHaveKey($orphan);
    expect($incus->images)->toHaveKey($old->image_fingerprint)->toHaveKey($replacement->refresh()->image_fingerprint);
});

it('keeps the last admissible image when intervening cold rebuilds fail after promotion', function (): void {
    $host = recipeImageHost();
    $incus = recipeImageTransport($host);
    recipeImageCurrent(recipeImageDocument());
    $builds = app(RecipeImageBuilds::class);
    $old = $builds->reconcile()[0];
    runRecipeImageBuild($old);
    $middle = $builds->reconcile(rebuild: true)[0];
    $incus->afterImage = static function (array $operation) use ($incus): void {
        if ($operation['kind'] === 'promote') {
            $incus->fail = 'image delete';
        }
    };
    expect(fn () => runRecipeImageBuild($middle))->toThrow(TaskVmException::class);
    expect($middle->refresh()->promoted_at)->not->toBeNull();
    $incus->afterImage = null;
    $incus->fail = null;
    $candidate = $builds->reconcile(rebuild: true)[0];
    runRecipeImageBuild($candidate);

    expect($incus->images)->toHaveKey($old->image_fingerprint)
        ->and($old->refresh()->state)->toBe(TaskVmImageBuildState::Succeeded);
    expect($incus->images)->not->toHaveKey($middle->image_fingerprint);
});

it('keeps a halted Node rollout and its incident through cold rebuild and promotion', function (): void {
    $host = recipeImageHost();
    recipeImageTransport($host);
    recipeImageCurrent(recipeImageDocument());
    app()->instance(FleetServingRelease::class, new FleetServingRelease(config()->string('orbit.gateway_checkout')));
    $builds = app(RecipeImageBuilds::class);
    $old = $builds->reconcile()[0];
    runRecipeImageBuild($old);
    $halt = ['generation' => $old->recipe_generation, 'status' => 'halted', 'node_id' => $host->id,
        'message' => 'Node failed. No automatic retry.', 'nodes' => []];
    $old->forceFill(['fleet_convergence' => $halt])->save();
    app(ProblemCollector::class)->recordRelease('rollout_halted|recipe-fleet:'.$old->recipe_identity.'|'.$old->recipe_generation.':'.$old->id.':1|convergence_halted', [
        'summary' => $halt['message'], 'release_kind' => 'rollout_halted', 'incident_resource' => 'recipe-fleet:'.$old->recipe_identity,
        'incident_attempt' => $old->recipe_generation.':'.$old->id.':1', 'incident_fault' => 'convergence_halted',
    ]);
    $incident = ProblemFingerprint::query()->sole();
    $desired = DB::table('task_vm_image_recipe')->where('id', 1)->first();

    $candidate = $builds->reconcile(rebuild: true)[0];
    runRecipeImageBuild($candidate);
    app(ConvergeFleetRecipeAction::class)->execute();

    expect($candidate->refresh()->state)->toBe(TaskVmImageBuildState::Succeeded)
        ->and($candidate->promoted_at)->not->toBeNull()
        ->and($candidate->fleet_convergence)->toBeNull()
        ->and($old->refresh()->fleet_convergence)->toBe($halt)
        ->and(DB::table('task_vm_image_recipe')->where('id', 1)->value('generation'))->toBe($desired->generation)
        ->and(app(ProblemRecovery::class)->reason($incident->refresh()))->toBeNull()
        ->and($incident->evidence)->not->toHaveKey('resolved_at');
});

it('reactivates a retained successful recipe after a failed cold rebuild and a recipe revert', function (): void {
    $host = recipeImageHost();
    $incus = recipeImageTransport($host);
    $firstRecipe = recipeImageDocument();
    recipeImageCurrent($firstRecipe);
    $builds = app(RecipeImageBuilds::class);
    $old = $builds->reconcile()[0];
    runRecipeImageBuild($old);
    $failed = $builds->reconcile(rebuild: true)[0];
    $incus->fail = 'recipe base:verify';
    expect(fn () => runRecipeImageBuild($failed))->toThrow(TaskVmException::class);
    $incus->fail = null;
    recipeImageCurrent(recipeImageDocument('20261016T000000Z'));
    runRecipeImageBuild($builds->reconcile()[0]);
    recipeImageCurrent($firstRecipe);

    $reverted = $builds->reconcile()[0];
    expect($reverted->id)->toBe($old->id)
        ->and($reverted->current_step)->toBe(TaskVmImageBuildStep::Promote);
    runRecipeImageBuild($reverted);
    expect($reverted->refresh()->state)->toBe(TaskVmImageBuildState::Succeeded)
        ->and($incus->images[$old->image_fingerprint])->toContain(TaskVmHost::BaseImage)
        ->and($failed->refresh()->state)->toBe(TaskVmImageBuildState::Failed);
});

it('refuses a cold rebuild instead of changing the desired Node recipe', function (): void {
    $host = recipeImageHost();
    recipeImageTransport($host);
    recipeImageCurrent(recipeImageDocument());
    $desired = DB::table('task_vm_image_recipe')->where('id', 1)->first();

    $this->artisan('task-vms:build-recipe', ['--rebuild' => true])->expectsOutputToContain('current desired recipe')->assertFailed();

    expect(DB::table('task_vm_image_recipe')->where('id', 1)->first())->toEqual($desired);
    $this->assertDatabaseCount('task_vm_image_builds', 0);
});
