<?php

declare(strict_types=1);

use App\Domain\TaskVms\TaskVmHost;
use App\Domain\TaskVms\TaskVmImageBuildState;
use App\Domain\TaskVms\TaskVmImageInputs;
use App\Domain\TaskVms\TaskVmImageRefreshTrigger;
use App\Domain\TaskVms\TaskVmSettings;
use App\Models\Cluster;
use App\Models\Node;
use App\Models\TaskVmImageBuild;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Process;

uses(LazilyRefreshDatabase::class);

/** A release folder with the files an image carries, and one that it does not. */
function triggerRelease(string $path, string $playbook = 'a', string $code = 'a', string $lock = 'a'): void
{
    foreach (['swamp/ansible/tasks', 'apps/gateway/resources/machine-software', 'apps/gateway/app'] as $folder) {
        is_dir($path.'/'.$folder) || mkdir($path.'/'.$folder, 0700, true);
    }
    file_put_contents($path.'/swamp/ansible/tasks/task-vm-apt.yml', $playbook);
    file_put_contents($path.'/apps/gateway/resources/machine-software/recipe.json', '{}');
    file_put_contents($path.'/apps/gateway/app/Code.php', $code);
    file_put_contents($path.'/composer.lock', $lock);
}

beforeEach(function (): void {
    $this->base = sys_get_temp_dir().'/orbit-refresh-trigger-'.bin2hex(random_bytes(4));
    foreach (['aaaaaaaaaaaa', 'bbbbbbbbbbbb'] as $id) {
        mkdir($this->base.'/releases/'.$id.'/apps/gateway', 0700, true);
    }
    symlink('releases/bbbbbbbbbbbb', $this->base.'/orbit');
    config(['orbit.gateway_checkout' => $this->base.'/orbit/apps/gateway']);
});

afterEach(function (): void {
    Process::run(['rm', '-rf', $this->base]);
});

it('digests only what the image carries: playbooks, pins and lock files', function (): void {
    triggerRelease($this->base.'/releases/aaaaaaaaaaaa');
    triggerRelease($this->base.'/releases/bbbbbbbbbbbb', code: 'changed code');
    $inputs = app(TaskVmImageInputs::class);

    expect($inputs->changed($this->base.'/releases/aaaaaaaaaaaa', $this->base.'/releases/bbbbbbbbbbbb'))->toBeFalse();

    triggerRelease($this->base.'/releases/bbbbbbbbbbbb', playbook: 'b');
    expect($inputs->changed($this->base.'/releases/aaaaaaaaaaaa', $this->base.'/releases/bbbbbbbbbbbb'))->toBeTrue();

    triggerRelease($this->base.'/releases/bbbbbbbbbbbb', lock: 'b');
    expect($inputs->changed($this->base.'/releases/aaaaaaaaaaaa', $this->base.'/releases/bbbbbbbbbbbb'))->toBeTrue();
    expect($inputs->changed($this->base.'/releases/gone', $this->base.'/releases/bbbbbbbbbbbb'))->toBeTrue();
});

it('asks every Linux task VM host and skips one that refuses', function (): void {
    triggerRelease($this->base.'/releases/aaaaaaaaaaaa');
    triggerRelease($this->base.'/releases/bbbbbbbbbbbb', playbook: 'b');
    $cluster = Cluster::query()->create(['name' => 'dev', 'tld' => 'dev']);
    $node = static fn (string $name, string $platform, string $ip): Node => Node::withoutEvents(fn (): Node => Node::query()->create([
        'name' => $name, 'status' => 'active', 'platform' => $platform, 'architecture' => 'x86_64', 'public_ssh_host' => $ip, 'wireguard_ip' => $ip, 'user' => 'orbit',
        'ssh_host_fingerprint' => 'SHA256:fixture', 'ssh_host_key_type' => 'ssh-ed25519', 'ssh_host_key' => 'fixture-public-key']));
    $busy = $node('shark', 'linux', '10.44.0.11');
    $mac = $node('mac', 'macos', '10.44.0.12');
    app()->instance(TaskVmSettings::class, new TaskVmSettings(true, $cluster->id, '10.44.0.128/25',
        [new TaskVmHost($busy->id, 'orbit-tasks', 'orbittask0', '10.251.77.0/24', 5, 4, '8GiB', '40GiB'), new TaskVmHost($mac->id, 'orbit-tasks', 'orbittask0', '10.251.78.0/24', 5, 4, '8GiB', '40GiB')],
        'http://10.44.0.3:8317', '/pi', str_repeat('a', 64), ['gpt-6']));
    TaskVmImageBuild::query()->create(['host_node_id' => $busy->id, 'recipe_identity' => 'recipe-2', 'state' => TaskVmImageBuildState::Running]);

    // The Linux host refuses while an image build runs; the macOS Node is no task VM host for this run.
    expect(app(TaskVmImageRefreshTrigger::class)->admitAll('operator'))->toBe(['shark' => 'task_vm.image_build_running']);
});
