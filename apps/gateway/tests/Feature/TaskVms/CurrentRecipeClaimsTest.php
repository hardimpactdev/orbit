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

it('waits for building, failed, missing, or unpromoted current recipe images despite an older succeeded build', function (?string $state): void {
    Queue::fake();
    $host = currentRecipeClaimHost();
    $group = currentRecipeClaimGroup();
    TaskVmSettingsBuilder::bind(['hosts' => [TaskVmSettingsBuilder::host($host->id)],
        'upcloud' => new TaskVmUpCloud(true, [$group->project_id], '/test/token', 1, 'nl-ams1', 'STARTER-2xCPU-4GB', 30, '01000000-0000-4000-8000-000030260200')]);
    $build = TaskVmRecipeImages::ready($host->id);
    $build->update(['recipe_identity' => 'sha256:'.str_repeat('b', 64)]);
    if ($state !== null) {
        $current = TaskVmRecipeImages::ready($host->id);
        $current->update(['state' => $state === 'unpromoted' ? 'succeeded' : $state, 'promoted_at' => null]);
    }
    $identity = app(RecipeLoader::class)->load()->identity;

    expect(fn () => app(AllocateTaskVmAction::class)->execute($group))->toThrow(TaskCapacityException::class,
        'Task VM: waiting for recipe '.substr($identity, 7, 12).' images on beast.');
    expect(TaskVm::query()->count())->toBe(0);
    Queue::assertNothingPushed();
})->with(['running', 'failed', null, 'unpromoted']);

it('records a current recipe and base fingerprint and keeps its VM across a deployed recipe change', function (TaskVmState $state): void {
    Queue::fake();
    $host = currentRecipeClaimHost();
    $group = currentRecipeClaimGroup();
    $build = TaskVmRecipeImages::ready($host->id);

    $vm = app(AllocateTaskVmAction::class)->execute($group);
    expect($vm->recipe_identity)->toBe($build->recipe_identity)
        ->and($vm->image_fingerprint)->toBe(str_repeat('a', 64));
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
        expect(app(AllocateTaskVmAction::class)->execute($group)->id)->toBe($vm->id);
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

    expect(app(AllocateTaskVmAction::class)->execute(currentRecipeClaimGroup())->host_node_id)->toBe($ready->id);
});
