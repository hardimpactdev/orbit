<?php

declare(strict_types=1);

use App\Infrastructure\Processes\CommandResult;
use App\Infrastructure\Ssh\SshExecutor;
use App\Infrastructure\TaskVms\TaskVmRoleImages;
use App\Infrastructure\TaskVms\TaskVmScripts;
use App\Models\Node;
use App\Models\Project;
use App\Models\Task;
use App\Models\TaskVm;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Process\Process;
use Tests\Support\AppDevFakeSshExecutor;
use Tests\Support\TaskVmRecipeImages;
use Tests\Support\TaskVmSettingsBuilder;

it('transfers the claimed recipe from its own host even after a newer recipe becomes desired', function (): void {
    $host = Node::query()->create(['name' => 'beast', 'status' => 'active', 'platform' => 'linux', 'architecture' => 'x86_64', 'wireguard_ip' => '10.44.0.7', 'public_ssh_host' => '192.0.2.7', 'user' => 'orbit']);
    TaskVmSettingsBuilder::bind(['hosts' => [TaskVmSettingsBuilder::host($host->id)]]);
    $build = TaskVmRecipeImages::ready($host->id);
    $project = Project::query()->create(['name' => 'Recipe', 'slug' => 'recipe', 'repository_url' => 'https://github.com/acme/recipe.git', 'default_branch' => 'main']);
    $group = Task::topLevel()->create(['project_id' => $project->id, 'title' => 'Nodes', 'brief' => 'Work', 'status' => 'reserved']);
    Task::query()->create(['parent_id' => $group->id, 'position' => 1, 'title' => 'Prod', 'brief' => 'Prod', 'status' => 'todo', 'topology' => ['app-prod']]);
    $vm = TaskVm::query()->create(['group_id' => $group->id, 'host_node_id' => $host->id, 'provider' => 'incus', 'size' => 'medium', 'name' => 'tvm-1', 'state' => 'provisioning', 'wireguard_ip' => '10.44.0.129', 'recipe_identity' => $build->recipe_identity, 'image_fingerprint' => $build->image_fingerprint]);
    DB::table('task_vm_image_recipe')->where('id', 1)->update(['identity' => 'sha256:'.str_repeat('b', 64)]);
    $manifestJson = json_encode(['recipe_identity' => $build->recipe_identity, 'images' => ['base' => $build->images['base']]], JSON_THROW_ON_ERROR);
    $receipt = 'orbit-role-images-ready:'.$vm->image_fingerprint.':'.hash('sha256', $manifestJson."\n");
    $ssh = new AppDevFakeSshExecutor([new CommandResult(0, $receipt."\n", '', 1, false)]);
    app()->instance(SshExecutor::class, $ssh);

    app(TaskVmRoleImages::class)->prepare($vm);

    expect($ssh->commands)->toHaveCount(1);
    expect($ssh->connections[0]->host)->toBe('10.44.0.7');
    $manifest = json_decode($ssh->commands[0]->arguments[7], true, flags: JSON_THROW_ON_ERROR);
    expect($manifest['recipe_identity'])->toBe($build->recipe_identity);
    expect(array_keys($manifest['images']))->toBe(['base']);
    expect($manifest['images']['base'])->toBe($build->images['base']);
    expect($ssh->commands[0]->input)->toBe(TaskVmScripts::read('role-images-transfer.sh'));
});

it('exports exact host fingerprints and transfers archives before publishing their manifest', function (string $fault): void {
    $work = sys_get_temp_dir().'/orbit-role-transfer-'.bin2hex(random_bytes(6));
    mkdir($work.'/bin', 0700, true);
    file_put_contents($work.'/bin/incus', <<<'SH'
#!/usr/bin/env bash
set -euo pipefail
echo "$*" >> "$WORK/calls"
quiet=false
if [[ $1 == --quiet ]]; then quiet=true; shift; fi
if [[ $3 == file && $4 == push && $quiet == false ]]; then
    if [[ $FAULT == cr-progress ]]; then printf '\rPushing: 100%%\r              \r'; fi
    if [[ $FAULT == normal-progress ]]; then printf 'Pushing: 100%%\n'; fi
fi
if [[ $3 == exec && "$*" != *--disable-stdin* ]]; then cat >/dev/null; fi
if [[ $3 == image && $4 == export ]]; then printf 'image bytes' >"$6.tar"; fi
if [[ $3 == file && $4 == push ]]; then
    if [[ $FAULT == push ]]; then exit 1; fi
    cp "$5" "$WORK/$(basename "$6")"
fi
if [[ $3 == exec && "$*" == *'mv -f'* ]]; then
    mv "$WORK/manifest.json.new" "$WORK/manifest.json"
    if [[ $FAULT == corrupt ]]; then printf 'corrupt' >"$WORK/base.tar"; fi
    if [[ $FAULT == corrupt-manifest ]]; then printf 'corrupt' >"$WORK/manifest.json"; fi
fi
if [[ $3 == exec && "$*" == *sha256sum* ]]; then
    for name in base.tar manifest.json; do
        hash=$(sha256sum "$WORK/$name" | cut -d ' ' -f 1)
        printf '%s  /var/lib/orbit/task-vm-role-images/%s\n' "$hash" "$name"
    done
fi
SH);
    chmod($work.'/bin/incus', 0755);
    $images = [];
    foreach (['base'] as $index => $role) {
        $images[$role] = ['fingerprint' => str_repeat(dechex($index + 1), 64)];
    }
    $manifest = json_encode(['recipe_identity' => 'sha256:'.str_repeat('a', 64), 'images' => $images], JSON_THROW_ON_ERROR);
    $process = new Process(['bash', '-s', '--', 'orbit-tasks', 'tvm-7', $manifest], input: TaskVmScripts::read('role-images-transfer.sh'), env: ['WORK' => $work, 'FAULT' => $fault, 'PATH' => $work.'/bin:'.getenv('PATH')]);
    try {
        $process->run();
        if (in_array($fault, ['push', 'corrupt', 'corrupt-manifest'], true)) {
            expect($process->isSuccessful())->toBeFalse();
            expect($process->getOutput())->not->toContain('orbit-role-images-ready:');

            return;
        }
        expect($process->isSuccessful())->toBeTrue();
        expect(file_get_contents($work.'/base.tar'))->toBe('image bytes');
        expect(file_get_contents($work.'/manifest.json'))->toBe($manifest."\n");
        expect(explode("\n", trim($process->getOutput())))->toContain('orbit-role-images-ready:'.str_repeat('1', 64).':'.hash('sha256', $manifest."\n"));
        $calls = file($work.'/calls', FILE_IGNORE_NEW_LINES);
        expect(array_values(array_filter($calls, static fn (string $call): bool => str_contains($call, 'image export'))))->toHaveCount(1);
        expect(implode("\n", $calls))->toContain('image export '.str_repeat('1', 64))
            ->not->toContain('launch', 'publish', 'image import');
        expect(end($calls))->toBe('--quiet --project orbit-tasks exec tvm-7 --disable-stdin -- sha256sum /var/lib/orbit/task-vm-role-images/base.tar /var/lib/orbit/task-vm-role-images/manifest.json');
    } finally {
        new Process(['rm', '-rf', '--', $work])->run();
    }
})->with(['complete' => '', 'push failure' => 'push', 'corrupt guest archive' => 'corrupt', 'corrupt guest manifest' => 'corrupt-manifest', 'CR progress without newline' => 'cr-progress', 'normal progress' => 'normal-progress']);
