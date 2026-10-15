<?php

declare(strict_types=1);

use App\Domain\Shared\LifecycleStatus;
use App\Domain\TaskVms\TaskVmException;
use App\Domain\TaskVms\TaskVmHost;
use App\Domain\TaskVms\TaskVmImageBuildState;
use App\Infrastructure\MachineSoftware\RecipeLoader;
use App\Infrastructure\Ssh\HostKey;
use App\Infrastructure\Ssh\KnownHostsStore;
use App\Infrastructure\Ssh\SshExecutor;
use App\Infrastructure\Ssh\SshKeyProvider;
use App\Infrastructure\TaskVms\RecipeImageBuilds;
use App\Infrastructure\TaskVms\TaskVmImageBuildChain;
use App\Infrastructure\TaskVms\TaskVmImageBuildSteps;
use App\Jobs\TaskVms\ImageBuild\BuildRecipeImageStep;
use App\Jobs\TaskVms\ImageBuild\CleanupImageBuild;
use App\Jobs\TaskVms\ImageBuild\PromoteImage;
use App\Jobs\TaskVms\RefreshTaskVmBaseImages;
use App\Models\Node;
use App\Models\TaskVmImageBuild;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Sleep;
use Symfony\Component\Process\Process;
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
        $job->handle(app(TaskVmImageBuildSteps::class));
        $build->refresh();
    }
}

beforeEach(function (): void {
    Bus::fake();
    Sleep::fake(syncWithCarbon: true);
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

    it('builds all four role images on each configured host without syncing images', function (): void {
        $first = recipeImageHost();
        $incus = recipeImageTransport($first);
        $second = Node::query()->create(['name' => 'recipe-shark', 'status' => LifecycleStatus::Active, 'platform' => 'linux', 'architecture' => 'x86_64', 'public_ssh_host' => '192.0.2.8', 'wireguard_ip' => '10.44.0.8', 'user' => 'orbit']);
        TaskVmSettingsBuilder::bind(['hosts' => [TaskVmSettingsBuilder::host($first->id), TaskVmSettingsBuilder::host($second->id, ['imageAlias' => 'shark-task-base'])]]);
        recipeImageCurrent(recipeImageDocument());
        $builds = app(RecipeImageBuilds::class)->reconcile();
        expect($builds)->toHaveCount(2);
        foreach ($builds as $build) {
            runRecipeImageBuild($build);
            expect(array_keys($build->images))->toBe(['base', 'gateway', 'app-dev', 'app-prod', 'operator']);
            expect($build->state)->toBe(TaskVmImageBuildState::Succeeded);
            foreach ($build->images as $profile => $image) {
                expect($incus->imageProperties[$image['fingerprint']])->toBe(['orbit.recipe' => $build->recipe_identity, 'orbit.profile' => $profile]);
                expect($image)->toMatchArray(['project' => 'orbit-tasks', 'type' => 'virtual-machine']);
            }
        }
        $launches = array_values(array_filter($incus->commands, static fn ($command): bool => ($command->arguments[5] ?? '') === 'launch'));
        expect($launches)->toHaveCount(20);
        $base = $builds[0]->images['base']['fingerprint'];
        expect(array_filter($launches, static fn ($command): bool => $command->arguments[count($command->arguments) - 2] === $base))->toHaveCount(5);
        Bus::assertDispatchedTimes(BuildRecipeImageStep::class, 2);
    });

    it('keeps previous images on a role failure and resumes from the failed phase', function (): void {
        $host = recipeImageHost();
        $incus = recipeImageTransport($host);
        recipeImageCurrent(recipeImageDocument());
        $build = app(RecipeImageBuilds::class)->reconcile()[0];
        $incus->fail = 'recipe app-prod:packages';
        expect(fn () => runRecipeImageBuild($build))->toThrow(TaskVmException::class);
        expect($build->refresh()->state)->toBe(TaskVmImageBuildState::Failed);
        expect($incus->images[IMAGE_OLD])->toContain(TaskVmHost::BaseImage);
        expect($build->promoted_at)->toBeNull();
        $incus->fail = null;
        $chain = app(TaskVmImageBuildChain::class);
        $chain->resume($host, $build->id);
        $remaining = false;
        foreach ($chain->jobs($build->refresh()) as $job) {
            $remaining = $remaining || $job->program() === 'app-prod:packages';
            if ($remaining) {
                $job->handle(app(TaskVmImageBuildSteps::class));
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
        expect(fn () => new PromoteImage($old->id)->handle(app(TaskVmImageBuildSteps::class)))
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
            ->expectsOutputToContain($build->images['operator']['alias'])->assertSuccessful();
        expect(DB::table('task_vm_image_recipe')->value('generation'))->toBe(1);
    });
});

it('runs image installer regressions without host changes or downloads', function (): void {
    $process = new Process(['python3', base_path('tests/Fixtures/machine-software/build_image_test.py'),
        resource_path('machine-software/converge-node.py'), resource_path('machine-software/build-image.py')]);
    $process->run();
    expect($process->getExitCode())->toBe(0, $process->getErrorOutput());
});

it('retries a discarded failure without replacing already published recipe images', function (): void {
    $host = recipeImageHost();
    $incus = recipeImageTransport($host);
    recipeImageCurrent(recipeImageDocument());
    $build = app(RecipeImageBuilds::class)->reconcile()[0];
    $incus->fail = 'recipe app-prod:packages';
    expect(fn () => runRecipeImageBuild($build))->toThrow(TaskVmException::class);
    $base = $build->refresh()->image_fingerprint;
    app()->call([new CleanupImageBuild($build->id), 'handle']);
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
    $promote = $jobs[array_key_last($jobs)];
    $promote->handle(app(TaskVmImageBuildSteps::class));
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
    $job->handle(app(TaskVmImageBuildSteps::class));
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
