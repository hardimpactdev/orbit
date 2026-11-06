<?php

declare(strict_types=1);

use App\Actions\TaskVms\StartTaskVmImageOperationAction;
use App\Domain\Nodes\RoleName;
use App\Domain\Shared\ResourceOperationException;
use App\Domain\TaskVms\TaskVmError;
use App\Domain\TaskVms\TaskVmHost;
use App\Domain\TaskVms\TaskVmImageBuildState;
use App\Domain\TaskVms\TaskVmImageProjection;
use App\Domain\TaskVms\TaskVmSettings;
use App\Infrastructure\MachineSoftware\RecipeLoader;
use App\Infrastructure\TaskVms\RecipeImageBuilds;
use App\Models\Cluster;
use App\Models\Node;
use App\Models\Operation;
use App\Models\TaskVmImageBuild;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\SwampNodeFixture;

uses(LazilyRefreshDatabase::class);

beforeEach(fn () => SwampNodeFixture::ensure());

const ImageFingerprint = 'a1b2c3d4e5f60718293a4b5c6d7e8f90a1b2c3d4e5f60718293a4b5c6d7e8f90';

/** A configured Incus host, the Gateway Node that holds the Pi server, and a build row for the current recipe. */
function imageWorld(): TaskVmImageBuild
{
    $node = static fn (string $name, string $address): Node => Node::withoutEvents(fn (): Node => Node::query()->create([
        'name' => $name, 'status' => 'active', 'platform' => 'linux', 'architecture' => 'x86_64', 'public_ssh_host' => $address, 'public_ssh_port' => 22,
        'wireguard_ip' => $address, 'user' => 'nckrtl', 'ssh_host_fingerprint' => 'SHA256:fixture', 'ssh_host_key_type' => 'ssh-ed25519', 'ssh_host_key' => 'fixture-public-key',
    ]));
    $host = $node('shark', '10.44.0.11');
    $node('gateway', '10.44.0.2')->roles()->create(['role' => RoleName::Gateway, 'status' => 'active']);
    $cluster = Cluster::query()->create(['name' => 'dev', 'tld' => 'dev']);
    app()->instance(TaskVmSettings::class, new TaskVmSettings(true, $cluster->id, '10.44.0.128/25',
        [new TaskVmHost($host->id, 'orbit-tasks', 'orbittask0', '10.251.78.0/24', 12, 4, '8GiB', '20GiB')],
        'http://10.44.0.3:8317', '/pi', str_repeat('a', 64), ['gpt-6']));
    $key = base64_encode(pack('N', 11).'ssh-ed25519'.pack('N', 32).str_repeat('k', 32));
    config(['swamp.ssh_public_key' => 'ssh-ed25519 '.$key.' fixture']);
    $recipe = app(RecipeImageBuilds::class)->currentRecipe();
    DB::table('task_vm_image_recipe')->where('id', 1)->update(['identity' => $recipe->identity, 'recipe_json' => $recipe->json, 'generation' => 1]);

    return TaskVmImageBuild::query()->create(['host_node_id' => $host->id, 'recipe_identity' => $recipe->identity, 'state' => TaskVmImageBuildState::Running,
        'builder_vm_name' => 'tvm-image-1', 'recipe_json' => $recipe->json, 'recipe_generation' => 1]);
}

function imageOperation(TaskVmImageBuild $build): Operation
{
    try {
        app(StartTaskVmImageOperationAction::class)->execute($build);
    } catch (Throwable $error) {
        // The run start after the commit needs a Swamp server the suite has not; the Operation is recorded before it.
        if (! str_contains($error::class, 'Swamp') && ! $error instanceof ResourceOperationException) {
            throw $error;
        }
    }

    $operation = Operation::query()->where('kind', 'task_vm.image_build')->latest('id')->firstOrFail();
    $operation->forceFill(['status' => 'running', 'attempts' => 1, 'state' => ['swamp_run_id' => 'fixture-image']])->save();

    return $operation->refresh();
}

/** @return array<string,mixed> */
function imageRun(string $status, array $steps, ?string $failedStep = null): array
{
    $run = ['run_id' => 'fixture-image', 'status' => $status, 'started_at' => now()->toIso8601String(), 'finished_at' => now()->toIso8601String(),
        'result' => ['steps' => $steps, 'summary' => ['failed_steps' => $failedStep === null ? [] : [$failedStep]]]];

    return $failedStep === null ? $run : [...$run, 'failure' => ['failed_step' => $failedStep]];
}

it('carries the host, the builder names and the pins of the recipe, and no agent', function (): void {
    $build = imageWorld();

    $input = imageOperation($build)->input;

    expect($input)->toMatchArray(['build_id' => $build->id, 'attempt' => 1, 'builder' => 'tvm-image-1', 'smoke' => 'tvm-image-1-smoke', 'project' => 'orbit-tasks',
        'network' => 'orbittask0', 'source' => 'ubuntu-26.04-vm', 'base_alias' => 'orbit-task-base', 'previous_alias' => 'orbit-task-base-previous', 'profile' => 'base', 'image' => true]);
    expect($input['candidate_alias'])->toEndWith('-base-attempt-1');
    expect($input['jump']['target'])->toMatchArray(['host' => '10.44.0.11', 'user' => 'nckrtl', 'port' => 22]);
    expect($input['ssh']['target'])->toBe(['host' => '', 'user' => 'orbit', 'port' => 22, 'host_keys' => []]);
    expect($input['recipe'])->toHaveKeys(['ubuntu', 'tools', 'base', 'common', 'php']);
    expect($input['recipe']['tools']['pi']['artifacts']['amd64']['sha256'])->toBe($input['pi']['sha256']);
    expect(hash('sha256', $input['vp_lock']))->toBe($input['recipe']['tools']['vp']['lock_sha256']);
    $launch = json_decode($input['launch_config'], true, flags: JSON_THROW_ON_ERROR);
    expect($launch['devices']['cloud-init'])->toBe(['type' => 'disk', 'source' => 'cloud-init:config']);
    expect($launch['config']['cloud-init.user-data'])->toStartWith("#cloud-config\n")->toContain('"name": "orbit"')->toContain('ssh-ed25519 ');
    expect($launch['config']['cloud-init.user-data'])->toContain('"packages": [')->toContain('"openssh-server"');
    foreach (['agent_sha256', 'agent_url', 'certificate', 'prune', 'gateway_public_key', 'ssh_execution'] as $retired) {
        expect($input)->not->toHaveKey($retired);
    }
})->group('task-vm-image-build');

it('keeps the Orbit agent out of the recipe identity', function (): void {
    $loader = app(RecipeLoader::class);
    $document = json_decode(app(RecipeImageBuilds::class)->currentRecipe()->json, true, flags: JSON_THROW_ON_ERROR);
    $identity = static fn (mixed $agent): string => $loader->decode(json_encode([...$document, 'agent' => $agent], JSON_THROW_ON_ERROR))->identity;

    expect($identity(['linux-x86_64' => str_repeat('a', 64)]))->toBe($identity(['linux-x86_64' => str_repeat('b', 64)]))->toBe($identity(null));
})->group('task-vm-image-build');

it('records a succeeded build only with its fingerprint and a promoted image', function (): void {
    $build = imageWorld();
    $operation = imageOperation($build);
    $steps = ['Publish the candidate' => ['status' => 'succeeded', 'stdout' => 'Instance published with fingerprint: '.ImageFingerprint], 'Promote the image' => ['status' => 'failed']];

    $refusal = null;
    try {
        app(TaskVmImageProjection::class)->apply($operation, imageRun('succeeded', $steps));
    } catch (ResourceOperationException $error) {
        $refusal = $error->errorCode;
    }
    expect($refusal)->toBe('operation.result_invalid');

    $steps['Promote the image'] = ['status' => 'succeeded'];
    $done = app(TaskVmImageProjection::class)->apply($operation->refresh(), imageRun('succeeded', $steps));

    expect($done->status->value)->toBe('succeeded');
    expect($done->result)->toMatchArray(['ready' => true, 'fingerprint' => ImageFingerprint]);
    $build->refresh();
    expect($build->image_fingerprint)->toBe(ImageFingerprint);
    expect($build->promoted_at)->not->toBeNull();
    expect($build->state)->toBe(TaskVmImageBuildState::Succeeded);
    expect(array_column($build->steps, 'step'))->toBe(['publish', 'promote']);
})->group('task-vm-image-build');

it('records a candidate without a promotion when the seed check skipped the promote', function (): void {
    $build = imageWorld();
    $operation = imageOperation($build);
    $steps = ['Publish the candidate' => ['status' => 'succeeded', 'stdout' => 'Instance published with fingerprint: '.ImageFingerprint],
        'Check the seed' => ['status' => 'succeeded'], 'Promote the image' => ['status' => 'skipped']];

    $done = app(TaskVmImageProjection::class)->apply($operation, imageRun('succeeded', $steps));

    expect($done->status->value)->toBe('succeeded');
    expect($done->result)->toMatchArray(['ready' => false, 'promoted' => false, 'fingerprint' => ImageFingerprint]);
    $build->refresh();
    expect($build->image_fingerprint)->toBe(ImageFingerprint);
    expect($build->promoted_at)->toBeNull();
    expect($build->state)->toBe(TaskVmImageBuildState::Succeeded);
})->group('task-vm-image-build');

it('refuses a succeeded run that neither promoted nor kept its candidate', function (): void {
    $build = imageWorld();
    $operation = imageOperation($build);
    $steps = ['Publish the candidate' => ['status' => 'succeeded', 'stdout' => 'Instance published with fingerprint: '.ImageFingerprint], 'Promote the image' => ['status' => 'skipped']];

    $refusal = null;
    try {
        app(TaskVmImageProjection::class)->apply($operation, imageRun('succeeded', $steps));
    } catch (ResourceOperationException $error) {
        $refusal = $error->errorCode;
    }

    expect($refusal)->toBe('operation.result_invalid');
})->group('task-vm-image-build');

it('names the code of the step that failed', function (): void {
    $build = imageWorld();
    $operation = imageOperation($build);

    $failed = app(TaskVmImageProjection::class)->apply($operation, imageRun('failed', ['Wait for the builder-1' => ['status' => 'failed']], 'Wait for the builder-1'));

    expect($failed->status->value)->toBe('failed');
    expect($failed->error_code)->toBe(TaskVmError::BootstrapTimeout->value);
})->group('task-vm-image-build');
