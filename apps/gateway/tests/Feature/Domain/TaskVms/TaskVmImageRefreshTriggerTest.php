<?php

declare(strict_types=1);

use App\Domain\TaskVms\TaskVmHost;
use App\Domain\TaskVms\TaskVmImageRefreshTrigger;
use App\Domain\TaskVms\TaskVmSettings;
use App\Infrastructure\TaskVms\RecipeImageBuilds;
use App\Jobs\TaskVms\RefreshTaskVmImages;
use App\Models\Cluster;
use App\Models\Node;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Process;

uses(LazilyRefreshDatabase::class);

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

it('asks every Linux task VM host for a release commit and skips one that refuses', function (): void {
    $cluster = Cluster::query()->create(['name' => 'dev', 'tld' => 'dev']);
    $node = static fn (string $name, string $platform, string $ip): Node => Node::withoutEvents(fn (): Node => Node::query()->create([
        'name' => $name, 'status' => 'active', 'platform' => $platform, 'architecture' => 'x86_64', 'public_ssh_host' => $ip, 'wireguard_ip' => $ip, 'user' => 'orbit',
        'ssh_host_fingerprint' => 'SHA256:fixture', 'ssh_host_key_type' => 'ssh-ed25519', 'ssh_host_key' => 'fixture-public-key']));
    $bare = $node('shark', 'linux', '10.44.0.11');
    $mac = $node('mac', 'macos', '10.44.0.12');
    app()->instance(TaskVmSettings::class, new TaskVmSettings(true, $cluster->id, '10.44.0.128/25',
        [new TaskVmHost($bare->id, 'orbit-tasks', 'orbittask0', '10.251.77.0/24', 5, 4, '8GiB', '40GiB'), new TaskVmHost($mac->id, 'orbit-tasks', 'orbittask0', '10.251.78.0/24', 5, 4, '8GiB', '40GiB')],
        '/pi', str_repeat('a', 64), ['gpt-6']));

    // The Linux host refuses, as this test has no proof Swamp Club key; the macOS Node is no task VM host for this run.
    expect(app(TaskVmImageRefreshTrigger::class)->admitAll('release', str_repeat('b', 40)))->toBe(['shark' => 'task_vm.image_refresh_unavailable']);
});

it('schedules no recipe reconcile: only the refresh starts work on the image of a task VM host', function (): void {
    Artisan::call('schedule:list');
    $scheduled = Artisan::output();

    expect($scheduled)->toContain(RefreshTaskVmImages::class)
        ->and($scheduled)->not->toContain('RefreshTaskVmBaseImages')
        ->and($scheduled)->not->toContain('RecipeImage')
        ->and(class_exists('App\Jobs\TaskVms\RefreshTaskVmBaseImages'))->toBeFalse()
        ->and(method_exists(RecipeImageBuilds::class, 'reconcile'))->toBeFalse();
});

it('keys the queued job by its host and commit, so a repeat is one job', function (): void {
    expect((new RefreshTaskVmImages)->uniqueId())->toBe('daily:')
        ->and((new RefreshTaskVmImages(7, 'bbbbbbbbbbbb'))->uniqueId())->toBe('host-7:bbbbbbbbbbbb');
});
