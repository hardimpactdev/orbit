<?php

declare(strict_types=1);

use App\Domain\Shared\LifecycleStatus;
use App\Domain\TaskVms\TaskVmException;
use App\Domain\TaskVms\TaskVmHost;
use App\Domain\TaskVms\TaskVmImageBuildState;
use App\Domain\TaskVms\TaskVmImageBuildStep;
use App\Domain\TaskVms\TaskVmSettings;
use App\Infrastructure\MachineSoftware\RecipeLoader;
use App\Infrastructure\Processes\CommandDeadline;
use App\Infrastructure\Processes\CommandResult;
use App\Infrastructure\Processes\NativeProcessRunner;
use App\Infrastructure\Processes\ProcessInvocation;
use App\Infrastructure\Processes\ProtectedInput;
use App\Infrastructure\Ssh\HostKey;
use App\Infrastructure\Ssh\KnownHostsStore;
use App\Infrastructure\Ssh\SshExecutor;
use App\Infrastructure\Ssh\SshKeyProvider;
use App\Infrastructure\TaskVms\IncusTaskVmBaseImage;
use App\Infrastructure\TaskVms\RecipeImageBuilds;
use App\Infrastructure\TaskVms\RecipeImageBuildSteps;
use App\Infrastructure\TaskVms\RecipeImagePrograms;
use App\Infrastructure\TaskVms\RecipeImagePruning;
use App\Infrastructure\TaskVms\TaskVmGuestShell;
use App\Infrastructure\TaskVms\TaskVmImageBuildChain;
use App\Jobs\TaskVms\ImageBuild\BuildRecipeImageStep;
use App\Jobs\TaskVms\ImageBuild\PromoteImage;
use App\Jobs\TaskVms\RefreshTaskVmBaseImages;
use App\Models\Node;
use App\Models\TaskVmImageBuild;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Sleep;
use Symfony\Component\Process\Process;
use Tests\Support\IncusGuestCall;
use Tests\Support\RecipeImageUnitGuest;
use Tests\Support\RecipeProgramPayload;
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

it('carries the complete base recipe over stdin without exceeding native argument limits', function (): void {
    $node = recipeImageHost();
    $incus = recipeImageTransport($node);
    $host = app(TaskVmSettings::class)->hosts[0];
    $shell = app(IncusTaskVmBaseImage::class)->shell($host, $node, 'recipe-builder');
    app(RecipeImagePrograms::class)->apply($shell, new RecipeLoader()->load(), 'base', 'packages');
    $call = collect($incus->guest)->first(fn ($call): bool => $call->argv[0] === 'install' && is_string($call->input) && str_contains($call->input, 'sys.exit(image_main())'));
    expect(strlen($call->input))->toBeGreaterThan(131072);

    // Execute the real assembled input boundary; print its decoded document instead of running the installer.
    $process = new Process(['python3', '-', 'packages']);
    $process->setInput(str_replace('sys.exit(image_main())', 'print(sys.argv[2])', $call->input));
    $process->mustRun();
    $payload = json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR);
    expect($payload['vp_lock'])->toBe(file_get_contents(resource_path('machine-software/vite-plus-package-lock.json')))
        ->and($payload['php']['input']['mode'])->toBe('apply');
});

it('installs OpenTelemetry only in app-dev image profiles', function (string $profile, bool $enabled): void {
    $node = recipeImageHost();
    $incus = recipeImageTransport($node);
    $host = app(TaskVmSettings::class)->hosts[0];
    $shell = app(IncusTaskVmBaseImage::class)->shell($host, $node, 'recipe-builder');

    app(RecipeImagePrograms::class)->apply($shell, new RecipeLoader()->load(), $profile, 'packages');

    $calls = array_values(array_filter($incus->guest, static fn ($call): bool => $call->argv[0] === 'install' && is_string($call->input) && str_contains($call->input, 'sys.exit(image_main())')));
    expect($calls)->toHaveCount(1);
    $payload = RecipeProgramPayload::read($calls[0]->input);
    expect($payload['php']['input']['opentelemetry'])->toBe($enabled);
    foreach (['8.4', '8.5'] as $version) {
        expect(isset($payload['tools']["php{$version}-opentelemetry"]))->toBe($enabled);
        expect(in_array("php{$version}-opentelemetry", array_column($payload['php']['input']['versions'][$version]['packages'], 'name'), true))->toBe($enabled);
        expect($payload['php']['input']['versions'][$version]['extensions'])->not->toContain('opentelemetry');
    }
})->with(['app-dev' => ['app-dev', true], 'app-prod' => ['app-prod', false], 'base' => ['base', false], 'ci-runner' => ['ci-runner', false]]);

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

it('reconnects to the same phase after losing its start acknowledgement without running the installer twice', function (): void {
    $host = recipeImageHost();
    $incus = recipeImageTransport($host);
    recipeImageCurrent(recipeImageDocument());
    $build = app(RecipeImageBuilds::class)->reconcile()[0];
    $guest = new RecipeImageUnitGuest(static fn (): CommandResult => new CommandResult(0, '{"ok":true}', '', 1, false));
    $guest->loseStartReply = true;
    $guest->running = true;
    $incus->unitGuests[$build->builder_vm_name] = $guest;
    $job = new BuildRecipeImageStep($build->id, 'base', 'packages');

    $job->handle(app(RecipeImageBuildSteps::class));
    expect($build->refresh()->state)->toBe(TaskVmImageBuildState::Running)
        ->and($build->steps[0]['seconds'])->toBeNull()
        ->and($build->steps[0]['error'])->toBeNull();
    $job->handle(app(RecipeImageBuildSteps::class));
    expect($build->refresh()->steps[0]['seconds'])->toBeNull();
    $guest->running = false;
    // A fresh delivery carries no in-memory program state from the original worker.
    new BuildRecipeImageStep($build->id, 'base', 'packages')->handle(app(RecipeImageBuildSteps::class));
    expect($build->refresh()->steps[0]['seconds'])->not->toBeNull()
        ->and($guest->starts)->toBe(1);
    $start = collect($incus->guest)->first(static fn ($call): bool => $call->argv[0] === 'systemd-run');
    expect($start->argv)->toContain('--property=RuntimeMaxSec='.RecipeImagePrograms::PhaseSeconds,
        '--property=ExitType=cgroup', '--property=KillMode=control-group', '--property=UMask=0022');
    expect(implode(' ', $start->argv))->not->toContain('--wait', '--pipe');
});

it('starts a missing unit again only on the recorded boot', function (bool $reboot): void {
    $host = recipeImageHost();
    $incus = recipeImageTransport($host);
    recipeImageCurrent(recipeImageDocument());
    $build = app(RecipeImageBuilds::class)->reconcile()[0];
    $guest = new RecipeImageUnitGuest(static fn (): CommandResult => new CommandResult(0, '', '', 1, false));
    $guest->loseUnsentStart = true;
    $incus->unitGuests[$build->builder_vm_name] = $guest;
    new BuildRecipeImageStep($build->id, 'base', 'packages')->handle(app(RecipeImageBuildSteps::class));
    expect($build->refresh()->steps[0]['phase_state']['boot_id'])->toBe($guest->bootId);
    expect($guest->starts)->toBe(0);

    if ($reboot) {
        $guest->bootId = '22222222-2222-4222-8222-222222222222';
        expect(fn () => new BuildRecipeImageStep($build->id, 'base', 'packages')->handle(app(RecipeImageBuildSteps::class)))
            ->toThrow(TaskVmException::class, 'builder rebooted during phase [base:packages]');
        expect($guest->starts)->toBe(0);
    } else {
        new BuildRecipeImageStep($build->id, 'base', 'packages')->handle(app(RecipeImageBuildSteps::class));
        expect($build->refresh()->steps[0]['seconds'])->not->toBeNull();
        expect($guest->starts)->toBe(1);
    }
})->with([false, true]);

it('never restarts an installer lost during a builder reboot', function (): void {
    $host = recipeImageHost();
    $incus = recipeImageTransport($host);
    recipeImageCurrent(recipeImageDocument());
    $build = app(RecipeImageBuilds::class)->reconcile()[0];
    $guest = new RecipeImageUnitGuest(static fn (): CommandResult => new CommandResult(0, '', '', 1, false));
    $guest->running = true;
    $incus->unitGuests[$build->builder_vm_name] = $guest;
    new BuildRecipeImageStep($build->id, 'base', 'packages')->handle(app(RecipeImageBuildSteps::class));
    $guest->bootId = '22222222-2222-4222-8222-222222222222';
    $guest->units = [];

    expect(fn () => new BuildRecipeImageStep($build->id, 'base', 'packages')->handle(app(RecipeImageBuildSteps::class)))
        ->toThrow(TaskVmException::class, 'builder rebooted during phase [base:packages]');
    expect($guest->starts)->toBe(1);
});

/** The UpCloud waiting path uses the same guest protocol without an Incus queue step. */
function recipePhaseShell(RecipeImageUnitGuest $guest): TaskVmGuestShell
{
    return new class($guest) implements TaskVmGuestShell
    {
        public function __construct(private RecipeImageUnitGuest $guest) {}

        public function asUser(array $command, string|ProtectedInput|null $input = null, float $timeout = 900.0, ?string $cwd = null): CommandResult
        {
            throw new LogicException('Installer phases run as root.');
        }

        public function asRoot(array $command, string|ProtectedInput|null $input = null, float $timeout = 900.0): CommandResult
        {
            return $this->guest->run($command, $input) ?? new CommandResult(0, $command[0] === 'dpkg' ? 'amd64' : '', '', 1, false);
        }
    };
}

it('uses the same boot fence while UpCloud waits for its phase', function (bool $reboot): void {
    $guest = new RecipeImageUnitGuest(static fn (): CommandResult => new CommandResult(0, '', '', 1, false));
    $guest->loseUnsentStart = ! $reboot;
    $guest->running = $reboot;
    Sleep::whenFakingSleep(static function () use ($guest, $reboot): void {
        if ($reboot) {
            $guest->bootId = '22222222-2222-4222-8222-222222222222';
            $guest->units = [];
            $guest->running = false;
        }
    });
    $run = fn () => app(RecipeImagePrograms::class)->apply(recipePhaseShell($guest), new RecipeLoader()->decode(recipeImageDocument()), 'base', 'packages');

    if ($reboot) {
        expect($run)->toThrow(TaskVmException::class, 'builder rebooted during phase [base:packages]');
    } else {
        $run();
    }
    expect($guest->starts)->toBe(1);
})->with([false, true]);

it('retries an unreadable phase status without restarting its installer', function (CommandResult $reply): void {
    $host = recipeImageHost();
    $incus = recipeImageTransport($host);
    recipeImageCurrent(recipeImageDocument());
    $build = app(RecipeImageBuilds::class)->reconcile()[0];
    $guest = new RecipeImageUnitGuest(static fn (): CommandResult => new CommandResult(0, '', '', 1, false));
    $guest->running = true;
    $incus->unitGuests[$build->builder_vm_name] = $guest;
    $job = new BuildRecipeImageStep($build->id, 'base', 'packages');
    $job->handle(app(RecipeImageBuildSteps::class));
    $guest->statusReplies = [$reply];

    $job->handle(app(RecipeImageBuildSteps::class));
    expect($build->refresh()->state)->toBe(TaskVmImageBuildState::Running);
    expect($build->steps[0]['error'])->toBeNull();
    $guest->running = false;
    new BuildRecipeImageStep($build->id, 'base', 'packages')->handle(app(RecipeImageBuildSteps::class));
    expect($guest->starts)->toBe(1);
    expect($build->refresh()->steps[0]['seconds'])->not->toBeNull();
})->with([
    'empty success' => new CommandResult(0, '', '', 1, false),
    'partial success' => new CommandResult(0, "LoadState=loaded\n", '', 1, false),
    'incus exec error' => new CommandResult(1, '', 'Error: guest agent is unavailable', 1, false),
    'truncated reply' => new CommandResult(0, 'LoadState=loaded', '', 1, true),
]);

it('ends unknown status retries at the shared bound and redacts the guest-log observation', function (): void {
    $host = recipeImageHost();
    $incus = recipeImageTransport($host);
    recipeImageCurrent(recipeImageDocument());
    $build = app(RecipeImageBuilds::class)->reconcile()[0];
    $guest = new RecipeImageUnitGuest(static fn (): CommandResult => new CommandResult(0, '', '', 1, false));
    $guest->running = true;
    $incus->unitGuests[$build->builder_vm_name] = $guest;
    new BuildRecipeImageStep($build->id, 'base', 'packages')->handle(app(RecipeImageBuildSteps::class));
    $steps = $build->refresh()->steps;
    $steps[0]['phase_state']['observed_at'] = microtime(true) - 1501;
    $build->steps = $steps;
    $build->save();
    $guest->statusReplies = [new CommandResult(1, '', 'API_TOKEN=do-not-store-this', 1, false)];

    expect(fn () => new BuildRecipeImageStep($build->id, 'base', 'packages')->handle(app(RecipeImageBuildSteps::class)))
        ->toThrow(TaskVmException::class, 'The guest phase outcome is unknown');
    $logs = array_filter($incus->commands, static fn ($command): bool => str_contains((string) $command->input, 'Gateway observation:'));
    expect($logs)->not->toBeEmpty();
    foreach ($logs as $log) {
        expect($log->input)->toContain('[REDACTED]')->not->toContain('do-not-store-this');
    }
});

it('reuses a succeeded unit and restarts a failed unit only on explicit resume', function (bool $succeeded): void {
    $host = recipeImageHost();
    $incus = recipeImageTransport($host);
    recipeImageCurrent(recipeImageDocument());
    $build = app(RecipeImageBuilds::class)->reconcile()[0];
    $guest = new RecipeImageUnitGuest(static fn (): CommandResult => new CommandResult($succeeded ? 0 : 100, '', 'installer diagnostic', 1, false));
    $incus->unitGuests[$build->builder_vm_name] = $guest;
    $incus->instances[$build->builder_vm_name] = IMAGE_STOCK;
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
    $unit = array_key_first($guest->units);
    app(TaskVmImageBuildChain::class)->resume($host, $build->id);
    $job = new BuildRecipeImageStep($build->id, 'base', 'packages');
    $job->buildAttempt = 2;
    if ($succeeded) {
        $job->handle(app(RecipeImageBuildSteps::class));
        expect($guest->starts)->toBe(1);
    } else {
        expect(fn () => $job->handle(app(RecipeImageBuildSteps::class)))->toThrow(TaskVmException::class);
        expect($guest->starts)->toBe(2);
    }
    expect(array_keys($guest->units))->toBe([$unit]);
    expect($build->refresh()->steps[0]['phase_state']['boot_id'])->toBe($guest->bootId);
})->with([true, false]);

it('adopts a running unit on resume and reports its later failure without restarting', function (): void {
    $host = recipeImageHost();
    $incus = recipeImageTransport($host);
    recipeImageCurrent(recipeImageDocument());
    $build = app(RecipeImageBuilds::class)->reconcile()[0];
    $guest = new RecipeImageUnitGuest(static fn (): CommandResult => new CommandResult(0, '', '', 1, false));
    $guest->running = true;
    $incus->unitGuests[$build->builder_vm_name] = $guest;
    $incus->instances[$build->builder_vm_name] = IMAGE_STOCK;
    new BuildRecipeImageStep($build->id, 'base', 'packages')->handle(app(RecipeImageBuildSteps::class));
    $build->update(['state' => TaskVmImageBuildState::Failed]);
    app(TaskVmImageBuildChain::class)->resume($host, $build->id);
    $job = new BuildRecipeImageStep($build->id, 'base', 'packages');
    $job->buildAttempt = 2;
    $job->handle(app(RecipeImageBuildSteps::class));
    expect($build->refresh()->steps[0]['phase_state']['attempt'])->toBe(2);

    $guest->running = false;
    $guest->signaled = true;
    foreach ($guest->units as &$unit) {
        $unit['exit'] = 9;
    }
    unset($unit);
    expect(fn () => $job->handle(app(RecipeImageBuildSteps::class)))->toThrow(TaskVmException::class, 'signal 9');
    expect($build->refresh()->state)->toBe(TaskVmImageBuildState::Failed);
    expect($guest->starts)->toBe(1);
});

it('observes explicit restart identity after a lost acknowledgement', function (bool $unsent, bool $succeeded): void {
    $host = recipeImageHost();
    $incus = recipeImageTransport($host);
    recipeImageCurrent(recipeImageDocument());
    $build = app(RecipeImageBuilds::class)->reconcile()[0];
    $exit = 100;
    $guest = new RecipeImageUnitGuest(static function () use (&$exit): CommandResult {
        return new CommandResult($exit, '', 'installer diagnostic', 1, false);
    });
    $incus->unitGuests[$build->builder_vm_name] = $guest;
    $incus->instances[$build->builder_vm_name] = IMAGE_STOCK;
    expect(fn () => new BuildRecipeImageStep($build->id, 'base', 'packages')->handle(app(RecipeImageBuildSteps::class)))
        ->toThrow(TaskVmException::class);
    app(TaskVmImageBuildChain::class)->resume($host, $build->id);
    $exit = $succeeded ? 0 : 100;
    $guest->loseUnsentRestart = $unsent;
    $guest->loseRestartReply = ! $unsent;
    $job = new BuildRecipeImageStep($build->id, 'base', 'packages');
    $job->buildAttempt = 2;
    $job->handle(app(RecipeImageBuildSteps::class));
    expect($build->refresh()->state)->toBe(TaskVmImageBuildState::Running);

    $next = new BuildRecipeImageStep($build->id, 'base', 'packages');
    $next->buildAttempt = 2;
    if ($succeeded) {
        $next->handle(app(RecipeImageBuildSteps::class));
    } else {
        expect(fn () => $next->handle(app(RecipeImageBuildSteps::class)))->toThrow(TaskVmException::class, 'exit 100');
    }
    expect($guest->starts)->toBe(2);
    expect($build->refresh()->steps[0]['phase_state']['attempt'])->toBe(2);
})->with([false, true])->with([false, true]);

it('keeps an actual installer traceback once when the unit routes its stderr', function (): void {
    recipeImageTransport(recipeImageHost());
    $directory = sys_get_temp_dir().'/recipe-traceback-'.bin2hex(random_bytes(8));
    mkdir($directory, 0700);
    $guest = null;
    $guest = new RecipeImageUnitGuest(static function (string $profile, string $phase, string $source) use ($directory, &$guest): CommandResult {
        $source = str_replace("Path('/var/log/orbit/recipe-images')", 'Path('.json_encode($directory, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES).')', $source);
        $source = str_replace("if __name__ == '__main__':", "def platform_check(architecture):\n    pass\ndef image_packages(payload):\n    raise RuntimeError('installer regression')\n\nif __name__ == '__main__':", $source);
        $result = new NativeProcessRunner()->run(new ProcessInvocation(['python3', '-', $phase], timeout: 30, input: $source));
        if (! is_file($directory.'/'.$profile.'-'.$phase.'.log')) {
            throw new RuntimeException($result->stderr);
        }
        $guest->files['/var/log/orbit/recipe-images/'.$profile.'-'.$phase.'.log'] = file_get_contents($directory.'/'.$profile.'-'.$phase.'.log');

        return $result;
    });
    $state = [];
    $failure = null;
    try {
        app(RecipeImagePrograms::class)->poll(recipePhaseShell($guest), new RecipeLoader()->decode(recipeImageDocument()), 'base', 'packages', 'traceback', $state);
    } catch (TaskVmException $error) {
        $failure = $error;
    } finally {
        foreach (glob($directory.'/*') as $file) {
            unlink($file);
        }
        rmdir($directory);
    }

    expect($failure)->toBeInstanceOf(TaskVmException::class);
    expect(substr_count($failure->getMessage(), 'Traceback (most recent call last):'))->toBe(1);
    expect(substr_count($guest->files['/var/log/orbit/recipe-images/base-packages.log'], 'Traceback (most recent call last):'))->toBe(1);
    expect($guest->journal)->toBe([]);
});

it('keeps stderr-only Pi installer failures in the phase log and build error', function (): void {
    $host = recipeImageHost();
    $incus = recipeImageTransport($host);
    recipeImageCurrent(recipeImageDocument());
    $build = app(RecipeImageBuilds::class)->reconcile()[0];
    $incus->fail = 'recipe base:pi';
    $incus->recipeFailure = new CommandResult(1, '', 'mv: No space left on device', 1, false);

    expect(fn () => new BuildRecipeImageStep($build->id, 'base', 'pi')->handle(app(RecipeImageBuildSteps::class)))
        ->toThrow(TaskVmException::class, 'mv: No space left on device');
    expect($incus->unitGuests[$build->builder_vm_name]->files['/var/log/orbit/recipe-images/base-pi.log'])
        ->toContain('mv: No space left on device');
});

it('reports a killed guest process as a signal without an exit code', function (): void {
    $host = recipeImageHost();
    $incus = recipeImageTransport($host);
    recipeImageCurrent(recipeImageDocument());
    $build = app(RecipeImageBuilds::class)->reconcile()[0];
    $guest = new RecipeImageUnitGuest(static fn (): CommandResult => new CommandResult(9, '', 'signal diagnostic', 1, false));
    $guest->signaled = true;
    $incus->unitGuests[$build->builder_vm_name] = $guest;
    $failure = null;

    try {
        new BuildRecipeImageStep($build->id, 'base', 'packages')->handle(app(RecipeImageBuildSteps::class));
    } catch (TaskVmException $error) {
        $failure = $error;
    }
    expect($failure)->toBeInstanceOf(TaskVmException::class);
    expect($failure->getMessage())->toContain('signal 9')->not->toContain('exit 9');
    expect($failure->imageFailure['exit_code'])->toBeNull();
});

it('uses disk and a transfer budget for the protected Pi artifact', function (): void {
    $host = recipeImageHost();
    $incus = recipeImageTransport($host);
    recipeImageCurrent(recipeImageDocument());
    $build = app(RecipeImageBuilds::class)->reconcile()[0];

    new BuildRecipeImageStep($build->id, 'base', 'pi')->handle(app(RecipeImageBuildSteps::class));
    $transfer = collect($incus->commands)->first(fn ($command): bool => $command->protectedInput instanceof ProtectedInput);
    expect(implode(' ', $transfer->arguments))->toContain('HOME=/var/tmp/', 'sha256sum --check --status', 'trap ');
    $index = array_search($transfer, $incus->commands, true);
    expect($incus->connections[$index]->commandTimeout)->toBe(900.0);
    $start = collect($incus->guest)->first(fn ($call): bool => $call->argv[0] === 'systemd-run');
    expect(implode(' ', $start->argv))->toContain('--property=StandardInput=file:/var/tmp/');
});

it('bounds both staging transfers inside one delivery budget covered by the job', function (): void {
    $host = recipeImageHost();
    $incus = recipeImageTransport($host);
    recipeImageCurrent(recipeImageDocument());
    $build = app(RecipeImageBuilds::class)->reconcile()[0];
    $elapsed = 0.0;
    $clock = static function () use (&$elapsed): float {
        return $elapsed;
    };
    app()->instance(RecipeImagePrograms::class, new RecipeImagePrograms(new CommandDeadline($clock)));
    $transfers = [];
    $incus->afterExecute = static function ($command) use ($incus, &$elapsed, &$transfers): void {
        $call = IncusGuestCall::decode($command);
        $connection = $incus->connections[array_key_last($incus->connections)];
        if ($command->protectedInput !== null || ($call->argv[0] === 'install' && ($call->argv[3] ?? null) === '/dev/stdin')) {
            $transfers[] = $connection->commandTimeout;
            $elapsed += $connection->commandTimeout;
        } else {
            $elapsed += 1;
        }
    };
    $job = new BuildRecipeImageStep($build->id, 'base', 'pi');

    $job->handle(app(RecipeImageBuildSteps::class));

    expect($build->refresh()->state)->toBe(TaskVmImageBuildState::Running);
    expect($incus->unitGuests[$build->builder_vm_name]->starts)->toBe(0);
    expect($transfers)->toHaveCount(2);
    expect($transfers[0])->toBe((float) RecipeImagePrograms::TransferSeconds);
    expect($transfers[1])->toBeLessThan(RecipeImagePrograms::TransferSeconds);
    expect($elapsed)->toBe((float) RecipeImagePrograms::PhaseSeconds);
    expect($job->timeout)->toBeGreaterThan($elapsed);
});

it('publishes only complete uploads and removes partial disk staging files', function (bool $truncated): void {
    recipeImageTransport(recipeImageHost());
    $directory = sys_get_temp_dir().'/image-upload-'.bin2hex(random_bytes(8));
    mkdir($directory, 0700);
    $guest = new RecipeImageUnitGuest(static fn (): CommandResult => new CommandResult(0, '', '', 1, false));
    $shell = new class($guest, $directory, $truncated) implements TaskVmGuestShell
    {
        public function __construct(private RecipeImageUnitGuest $guest, private string $directory, private bool $truncated) {}

        public function asUser(array $command, string|ProtectedInput|null $input = null, float $timeout = 900.0, ?string $cwd = null): CommandResult
        {
            throw new LogicException('Installer phases run as root.');
        }

        public function asRoot(array $command, string|ProtectedInput|null $input = null, float $timeout = 900.0): CommandResult
        {
            if ($command[0] === 'env' && str_starts_with($command[1], 'HOME=/var/tmp/orbit-recipe-image-')) {
                $contents = $input instanceof ProtectedInput ? stream_get_contents($input->stream()) : $input;
                $local = $command;
                $local[1] = 'HOME='.$this->directory;
                $result = new NativeProcessRunner()->run(new ProcessInvocation($local, timeout: $timeout,
                    input: $this->truncated ? substr($contents, 0, -1) : $contents));
                if (! $result->succeeded()) {
                    return $result;
                }
            }

            return $this->guest->run($command, $input) ?? new CommandResult(0, $command[0] === 'dpkg' ? 'amd64' : '', '', 1, false);
        }
    };
    $state = [];
    try {
        $run = fn () => app(RecipeImagePrograms::class)->poll($shell, new RecipeLoader()->decode(recipeImageDocument()), 'base', 'pi', 'transfer', $state);
        if ($truncated) {
            expect($run)->toThrow(TaskVmException::class, 'Image control command [env] failed');
            expect(glob($directory.'/.local/bin/*'))->toBe([]);
            expect(glob($directory.'/.local/bin/.pi-server.*'))->toBe([]);
            expect($guest->starts)->toBe(0);
        } else {
            expect($run())->toBeTrue();
            expect(glob($directory.'/.local/bin/.pi-server.*'))->toBe([]);
            expect($guest->starts)->toBe(1);
            $files = glob($directory.'/.local/bin/pi-server');
            expect($files)->toHaveCount(1);
            expect(file_get_contents($files[0]))->toBe('pinned pi bytes');
            expect(fileperms($directory) & 0777)->toBe(0700);
        }
    } finally {
        foreach ([...glob($directory.'/.local/bin/*'), ...glob($directory.'/.local/bin/.pi-server.*')] as $file) {
            unlink($file);
        }
        rmdir($directory.'/.local/bin');
        rmdir($directory.'/.local');
        rmdir($directory);
    }
})->with([false, true]);

it('reports the guest service timeout without inventing a package exit code', function (): void {
    $host = recipeImageHost();
    $incus = recipeImageTransport($host);
    recipeImageCurrent(recipeImageDocument());
    $build = app(RecipeImageBuilds::class)->reconcile()[0];
    $guest = new RecipeImageUnitGuest(static fn (): CommandResult => new CommandResult(0, '', '', 1, false));
    $guest->timedOut = true;
    $incus->unitGuests[$build->builder_vm_name] = $guest;

    expect(fn () => new BuildRecipeImageStep($build->id, 'base', 'packages')->handle(app(RecipeImageBuildSteps::class)))
        ->toThrow(TaskVmException::class, 'timed out after '.RecipeImagePrograms::PhaseSeconds.' seconds');
    expect($build->refresh()->steps[0]['error'])->not->toContain('exit 255', 'Exit code [1]');
    $state = $build->steps[0]['phase_state'];
    $failure = null;
    try {
        app(RecipeImagePrograms::class)->poll(recipePhaseShell($guest), new RecipeLoader()->decode(recipeImageDocument()), 'base', 'packages', (string) $build->id, $state);
    } catch (TaskVmException $error) {
        $failure = $error;
    }
    expect($failure)->toBeInstanceOf(TaskVmException::class);
    expect($failure->imageFailure['exit_code'])->toBeNull();
});

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

it('runs image installer regressions without host changes or downloads', function (): void {
    $process = new Process(['python3', base_path('tests/Fixtures/machine-software/build_image_test.py'),
        resource_path('remote/lib/recipe.py'), resource_path('machine-software/build-image.py')]);
    $process->run();
    expect($process->getExitCode())->toBe(0, $process->getErrorOutput());
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
    $logs = array_filter($incus->commands, static fn ($command): bool => str_contains((string) $command->input, 'Gateway observation:'));
    expect($logs)->not->toBeEmpty();
    foreach ($logs as $log) {
        expect($log->input)->not->toContain('do-not-store-this', 'do-not-store-that', 'apt dependency conflict');
    }
})->with(['packages', 'javascript', 'rust', 'agent', 'verify']);

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
    expect($incus->seeds)->toBe([['project' => 'orbit-tasks', 'pool' => 'orbit-tasks', 'vm' => 'tvm-image-1-smoke', 'device' => 'topology-seed',
        'volume' => $seed.'-new', 'size' => '45GiB', 'fingerprint' => $fingerprint, 'git_read' => []]])
        ->and($incus->volumes)->toBe([$seed])
        ->and($incus->instances)->not->toHaveKey('tvm-image-1-smoke');
    $program = array_values(array_filter($incus->commands, static fn ($command): bool => in_array('task-vms.build-topology-seed', $command->arguments, true)));
    expect(array_slice($program[0]->arguments, 0, 6))->toBe(['sudo', '-n', '-u', 'root', '-H', 'python3']);
    $order = array_map(static fn ($command): string => implode(' ', array_slice($command->arguments, 5, 4)), $incus->commands);
    $built = array_search('python3 -I -c '.$program[0]->arguments[8], $order, true);
    $deleted = min(array_filter(array_keys($order, 'delete --force -- tvm-image-1-smoke', true), static fn (int $index): bool => $index > $built));
    $renamed = array_find_key($order, static fn (string $line): bool => str_starts_with($line, 'storage volume rename'));
    expect($built)->toBeLessThan($deleted)->and($deleted)->toBeLessThan($renamed);
    expect(array_find($build->steps, static fn (array $step): bool => $step['program'] === 'base:topology-seed')['error'])->toBeNull()
        ->and($build->promoted_at)->not->toBeNull();
});

it('fails the phase with the program log and no guest log, and builds the seed again in a fresh smoke VM on resume', function (bool $smokeGone): void {
    $host = recipeImageHost();
    $incus = recipeImageTransport($host);
    recipeImageCurrent(recipeImageDocument());
    $build = app(RecipeImageBuilds::class)->reconcile()[0];
    // The program created the `-new` volume and changed the smoke VM before it failed.
    $incus->fail = 'topology seed';
    $incus->afterExecute = function ($command) use ($incus): void {
        if (in_array('task-vms.build-topology-seed', $command->arguments, true) && $incus->fail !== null) {
            $incus->volumes[] = 'orbit-topology-seed-'.substr((string) json_decode(explode("\0", (string) $command->input, 2)[1], true)['fingerprint'], 0, 12).'-new';
        }
    };
    expect(fn () => runRecipeImageBuild($build))->toThrow(TaskVmException::class, 'topology seed');
    $failed = array_find($build->refresh()->steps, static fn (array $step): bool => $step['program'] === 'base:topology-seed');
    expect($failed['error'])->toContain('Building the topology seed in [tvm-image-1-smoke] failed: no_result.', 'Error: topology seed failed')
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
        ->and(array_filter($incus->commands, static fn ($command): bool => ($command->arguments[5] ?? '') === 'launch' && $command->arguments[array_key_last($command->arguments)] === 'tvm-image-1-smoke'))->toHaveCount(2);

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
        ->and($candidate->recipe_generation)->toBe($old->recipe_generation + 1)
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
    $incus->afterExecute = static function ($command): void {
        if (($command->arguments[5] ?? null) === 'publish') {
            throw new RuntimeException('The publish reply was lost.');
        }
    };

    expect(fn () => runRecipeImageBuild($interrupted))->toThrow(TaskVmException::class);
    $alias = RecipeImageBuilds::alias(TaskVmHost::BaseImage, $old->recipe_identity, 'base', $interrupted->id);
    $orphan = hash('sha256', $alias);
    expect($interrupted->refresh()->images)->toBe([])
        ->and($incus->images)->toHaveKey($orphan);
    $incus->afterExecute = null;
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
    $incus->afterExecute = static function ($command) use ($incus): void {
        if (($command->arguments[3] ?? null) === 'query') {
            $incus->fail = 'image list';
        }
    };
    expect(fn () => runRecipeImageBuild($middle))->toThrow(TaskVmException::class);
    expect($middle->refresh()->promoted_at)->not->toBeNull();
    $incus->afterExecute = null;
    $incus->fail = null;
    $candidate = $builds->reconcile(rebuild: true)[0];
    runRecipeImageBuild($candidate);

    expect($incus->images)->toHaveKey($old->image_fingerprint)
        ->and($old->refresh()->state)->toBe(TaskVmImageBuildState::Succeeded);
    expect($incus->images)->not->toHaveKey($middle->image_fingerprint);
});
