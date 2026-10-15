<?php

declare(strict_types=1);

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
    $vm = TaskVm::query()->create(['group_id' => $group->id, 'host_node_id' => $host->id, 'provider' => 'incus', 'size' => 'medium', 'name' => 'tvm-1', 'state' => 'provisioning', 'wireguard_ip' => '10.44.0.129', 'recipe_identity' => $build->recipe_identity]);
    DB::table('task_vm_image_recipe')->where('id', 1)->update(['identity' => 'sha256:'.str_repeat('b', 64)]);
    $ssh = new AppDevFakeSshExecutor;
    app()->instance(SshExecutor::class, $ssh);

    app(TaskVmRoleImages::class)->prepare($vm);

    expect($ssh->commands)->toHaveCount(1);
    expect($ssh->connections[0]->host)->toBe('10.44.0.7');
    $manifest = json_decode($ssh->commands[0]->arguments[7], true, flags: JSON_THROW_ON_ERROR);
    expect($manifest['recipe_identity'])->toBe($build->recipe_identity);
    expect(array_keys($manifest['images']))->toBe(['gateway', 'app-dev', 'app-prod', 'operator']);
    expect($manifest['images']['app-prod'])->toBe($build->images['app-prod']);
    expect($ssh->commands[0]->input)->toBe(TaskVmScripts::read('role-images-transfer.sh'));
});

it('exports exact host fingerprints and transfers archives before publishing their manifest', function (): void {
    $work = sys_get_temp_dir().'/orbit-role-transfer-'.bin2hex(random_bytes(6));
    mkdir($work.'/bin', 0700, true);
    file_put_contents($work.'/bin/incus', <<<'SH'
#!/usr/bin/env bash
set -euo pipefail
echo "$*" >> "$WORK/calls"
if [[ $3 == image && $4 == export ]]; then printf 'image bytes' >"$6.tar"; fi
SH);
    chmod($work.'/bin/incus', 0755);
    $images = [];
    foreach (['gateway', 'app-dev', 'app-prod', 'operator'] as $index => $role) {
        $images[$role] = ['fingerprint' => str_repeat(dechex($index + 1), 64)];
    }
    $manifest = json_encode(['recipe_identity' => 'sha256:'.str_repeat('a', 64), 'images' => $images], JSON_THROW_ON_ERROR);
    $process = new Process(['bash', TaskVmScripts::path('role-images-transfer.sh'), 'orbit-tasks', 'tvm-7', $manifest], env: ['WORK' => $work, 'PATH' => $work.'/bin:'.getenv('PATH')]);
    try {
        $process->mustRun();
        $calls = file($work.'/calls', FILE_IGNORE_NEW_LINES);
        expect(array_values(array_filter($calls, static fn (string $call): bool => str_contains($call, 'image export'))))->toHaveCount(4);
        expect(implode("\n", $calls))->toContain('image export '.str_repeat('1', 64))
            ->not->toContain('launch', 'publish', 'image import');
        expect(end($calls))->toBe('--project orbit-tasks exec tvm-7 -- mv -f /var/lib/orbit/task-vm-role-images/manifest.json.new /var/lib/orbit/task-vm-role-images/manifest.json');
    } finally {
        new Process(['rm', '-rf', '--', $work])->run();
    }
});
