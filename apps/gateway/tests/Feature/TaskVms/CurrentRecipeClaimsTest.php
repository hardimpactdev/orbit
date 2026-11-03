<?php

declare(strict_types=1);

use App\Actions\TaskVms\AllocateTaskVmAction;
use App\Domain\Tasks\TaskCapacityException;
use App\Domain\TaskVms\TaskVmState;
use App\Domain\TaskVms\TaskVmUpCloud;
use App\Infrastructure\MachineSoftware\RecipeLoader;
use App\Infrastructure\TaskVms\RecipeImageBuilds;
use App\Models\Node;
use App\Models\Project;
use App\Models\Task;
use App\Models\TaskVm;
use App\Models\TaskVmImageBuild;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Queue;
use Tests\Support\TaskVmRecipeImages;
use Tests\Support\TaskVmSettingsBuilder;

function currentRecipeClaimHost(string $name = 'beast'): Node
{
    $host = Node::query()->create(['name' => $name, 'status' => 'active', 'platform' => 'linux', 'architecture' => 'x86_64', 'wireguard_ip' => $name === 'beast' ? '10.44.0.7' : '10.44.0.11', 'public_ssh_host' => '192.0.2.7', 'user' => 'orbit']);
    TaskVmSettingsBuilder::bind(['hosts' => [TaskVmSettingsBuilder::host($host->id)]]);

    return $host;
}

function currentRecipeClaimGroup(): Task
{
    $project = Project::query()->create(['name' => 'Recipe', 'slug' => 'recipe', 'repository_url' => 'https://github.com/acme/recipe.git', 'default_branch' => 'main']);

    return Task::topLevel()->create(['project_id' => $project->id, 'title' => 'Recipe claim', 'brief' => 'Build', 'status' => 'reserved']);
}

it('waits for building, failed, missing, or unpromoted current recipe images despite an older build promoted over a day ago', function (?string $state): void {
    Queue::fake();
    $host = currentRecipeClaimHost();
    $group = currentRecipeClaimGroup();
    TaskVmSettingsBuilder::bind(['hosts' => [TaskVmSettingsBuilder::host($host->id)],
        'upcloud' => new TaskVmUpCloud(true, [$group->project_id], '/test/token', 1, 'nl-ams1', 'STARTER-2xCPU-4GB', 30, '01000000-0000-4000-8000-000030260200')]);
    $build = TaskVmRecipeImages::ready($host->id);
    $build->update(['recipe_identity' => 'sha256:'.str_repeat('b', 64), 'promoted_at' => now()->subDay()->subMinute()]);
    if ($state !== null) {
        $current = TaskVmRecipeImages::ready($host->id);
        $current->update(['state' => $state === 'unpromoted' ? 'succeeded' : $state, 'promoted_at' => null]);
    }
    $identity = app(RecipeLoader::class)->load()->identity;

    expect(fn () => app(AllocateTaskVmAction::class)->capture($group))->toThrow(TaskCapacityException::class,
        'Task VM: waiting for recipe '.substr($identity, 7, 12).' images on beast.');
    expect(TaskVm::query()->count())->toBe(0);
    Queue::assertNothingPushed();
})->with(['running', 'failed', null, 'unpromoted']);

it('records a current recipe and base fingerprint and keeps its VM across a deployed recipe change', function (TaskVmState $state): void {
    Queue::fake();
    $host = currentRecipeClaimHost();
    $group = currentRecipeClaimGroup();
    $build = TaskVmRecipeImages::ready($host->id);

    $vm = app(AllocateTaskVmAction::class)->capture($group);
    expect($vm->recipe_identity)->toBe($build->recipe_identity)
        ->and($vm->image_fingerprint)->toBe(str_repeat('a', 64))
        ->and($vm->image_build_id)->toBe($build->id)
        ->and($vm->image_fallback)->toBeFalse();
    $vm->update(['state' => $state]);
    $directory = sys_get_temp_dir().'/orbit-claim-recipe-'.bin2hex(random_bytes(6));
    $release = $directory.'/releases/current/apps/gateway';
    mkdir($release.'/resources/machine-software', 0700, true);
    symlink('releases/current', $directory.'/orbit');
    $document = json_decode($build->recipe_json, true, flags: JSON_THROW_ON_ERROR);
    $document['ubuntu']['snapshot'] = new DateTimeImmutable($document['ubuntu']['snapshot'])->modify('+1 day')->format('Ymd\THis\Z');
    file_put_contents($release.'/resources/machine-software/recipe.json', json_encode($document, JSON_THROW_ON_ERROR));
    config(['orbit.gateway_checkout' => $directory.'/orbit/apps/gateway']);
    try {
        expect(app(RecipeImageBuilds::class)->currentRecipe()->identity)->not->toBe($build->recipe_identity);
        expect(app(AllocateTaskVmAction::class)->capture($group)->id)->toBe($vm->id);
        expect($vm->refresh()->recipe_identity)->toBe($build->recipe_identity);
        expect($vm->state)->toBe($state);
    } finally {
        File::deleteDirectory($directory);
    }
})->with([TaskVmState::Ready, TaskVmState::Parked]);

it('uses another current host while one host builds', function (): void {
    Queue::fake();
    $building = currentRecipeClaimHost();
    $ready = currentRecipeClaimHost('shark');
    TaskVmSettingsBuilder::bind(['hosts' => [TaskVmSettingsBuilder::host($building->id), TaskVmSettingsBuilder::host($ready->id, ['cidr' => '10.251.78.0/24'])]]);
    TaskVmRecipeImages::ready($ready->id);

    expect(app(AllocateTaskVmAction::class)->capture(currentRecipeClaimGroup())->host_node_id)->toBe($ready->id);
});

it('keeps the promoted image until a cold candidate succeeds and promotes', function (string $state, bool $promoted, bool $replacement): void {
    Queue::fake();
    $host = currentRecipeClaimHost();
    $build = TaskVmRecipeImages::ready($host->id);
    $candidate = $build->replicate();
    $images = $build->images;
    $images['base']['fingerprint'] = str_repeat('b', 64);
    $candidate->fill(['recipe_generation' => $build->recipe_generation, 'state' => $state, 'promoted_at' => $promoted ? now() : null,
        'image_fingerprint' => str_repeat('b', 64), 'images' => $images]);
    $candidate->save();

    $vm = app(AllocateTaskVmAction::class)->capture(currentRecipeClaimGroup());

    expect($vm->image_fingerprint)->toBe(str_repeat($replacement ? 'b' : 'a', 64));
})->with([
    'running' => ['running', false, false],
    'failed after promotion' => ['failed', true, false],
    'unpromoted' => ['succeeded', false, false],
    'promoted' => ['succeeded', true, true],
]);

/** A promoted build of an older recipe on the host, as a build of the current recipe runs or fails. */
function currentRecipeClaimPreviousImage(Node $host, DateTimeInterface $promotedAt): TaskVmImageBuild
{
    $build = TaskVmRecipeImages::ready($host->id, str_repeat('c', 64));
    $build->update(['recipe_identity' => 'sha256:'.str_repeat('b', 64), 'promoted_at' => $promotedAt]);

    return $build;
}

it('starts from the host last promoted image under a day old while the current recipe builds or failed', function (?string $state): void {
    Queue::fake();
    $host = currentRecipeClaimHost();
    $previous = currentRecipeClaimPreviousImage($host, now()->subHours(23));
    if ($state !== null) {
        TaskVmRecipeImages::ready($host->id)->update(['state' => $state, 'promoted_at' => null]);
    }

    $vm = app(AllocateTaskVmAction::class)->capture(currentRecipeClaimGroup());

    expect($vm->host_node_id)->toBe($host->id)
        ->and($vm->image_fingerprint)->toBe(str_repeat('c', 64))
        ->and($vm->recipe_identity)->toBe('sha256:'.str_repeat('b', 64))
        ->and($vm->recipe_identity)->not->toBe(app(RecipeLoader::class)->load()->identity)
        ->and($vm->image_build_id)->toBe($previous->id)
        ->and($vm->image_fallback)->toBeTrue()
        ->and($vm->fresh()->image_fallback)->toBeTrue();
})->with(['running', 'failed', null]);

it('takes the newest promoted image as the fallback', function (): void {
    Queue::fake();
    $host = currentRecipeClaimHost();
    currentRecipeClaimPreviousImage($host, now()->subHours(3));
    $newest = TaskVmRecipeImages::ready($host->id, str_repeat('d', 64));
    $newest->update(['recipe_identity' => 'sha256:'.str_repeat('e', 64), 'promoted_at' => now()->subHour()]);

    $vm = app(AllocateTaskVmAction::class)->capture(currentRecipeClaimGroup());

    expect($vm->image_build_id)->toBe($newest->id)->and($vm->image_fingerprint)->toBe(str_repeat('d', 64));
});

it('keeps a group that declared the current recipe waiting despite a fresh fallback image', function (): void {
    Queue::fake();
    $host = currentRecipeClaimHost();
    currentRecipeClaimPreviousImage($host, now()->subHour());
    $group = currentRecipeClaimGroup();
    $group->update(['current_recipe' => true]);
    $identity = app(RecipeLoader::class)->load()->identity;

    expect(fn () => app(AllocateTaskVmAction::class)->capture($group))->toThrow(TaskCapacityException::class,
        'Task VM: waiting for recipe '.substr($identity, 7, 12).' images on beast.');
    expect(TaskVm::query()->count())->toBe(0);
});

it('prefers the current recipe image over a newer promoted image of another recipe', function (): void {
    Queue::fake();
    $host = currentRecipeClaimHost();
    currentRecipeClaimPreviousImage($host, now()->subHour());
    $current = TaskVmRecipeImages::ready($host->id);
    $current->update(['promoted_at' => now()->subHours(5)]);

    $vm = app(AllocateTaskVmAction::class)->capture(currentRecipeClaimGroup());

    expect($vm->image_build_id)->toBe($current->id)->and($vm->image_fingerprint)->toBe(str_repeat('a', 64))
        ->and($vm->recipe_identity)->toBe($current->recipe_identity)->and($vm->image_fallback)->toBeFalse();
});
