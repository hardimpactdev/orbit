<?php

declare(strict_types=1);

use App\Domain\Nodes\NodeProvisioningLock;
use App\Domain\Nodes\NodeProvisioningLockException;
use App\Domain\Nodes\RoleName;
use App\Models\Node;
use App\Models\Operation;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Queue;
use Tests\Support\SwampNodeFixture;

uses(LazilyRefreshDatabase::class);

beforeEach(function (): void {
    Queue::fake();
    Http::preventStrayRequests();
    Process::preventStrayProcesses();
    $make = static fn (string $name, string $ip): Node => Node::withoutEvents(static fn (): Node => Node::query()->create([
        'name' => $name, 'status' => 'active', 'platform' => 'linux', 'wireguard_ip' => $ip,
        'public_ssh_host' => '192.0.2.99', 'public_ssh_port' => 2222, 'user' => 'orbit',
    ]));
    $this->gateway = $make('gateway', '10.44.0.1');
    $this->gateway->roles()->create(['role' => RoleName::Gateway, 'status' => 'active']);
    $this->node = $make('editable', '10.44.0.2');
    $this->withServerVariables(['REMOTE_ADDR' => $this->gateway->wireguard_ip]);
    $this->url = '/api/v1/nodes/'.$this->node->id;
});

it('commits record edits through Gateway authority without a direct target grant or execution', function (): void {
    $this->patchJson($this->url, ['name' => 'renamed', 'platform' => 'macos', 'apps' => null,
        'task_vms' => ['cidr' => '10.251.75.0/24', 'task_places' => 2, 'ci_places' => 3]])
        ->assertOk()->assertJsonPath('data.name', 'renamed')->assertJsonPath('data.platform', 'macos')
        ->assertJsonPath('data.settings.task_vms.task_places', 2)->assertJsonPath('data.settings.task_vms.ci_places', 3);
    expect($this->node->refresh()->public_ssh_host)->toBe('192.0.2.99')
        ->and($this->node->public_ssh_port)->toBe(2222)->and($this->node->user)->toBe('orbit')
        ->and(Operation::query()->count())->toBe(0);
    Http::assertNothingSent();
    Process::assertNothingRan();
    Queue::assertNothingPushed();
});

it('skips identical record and settings patches without a record write or Operation', function (array $patch): void {
    $this->node->update(['settings' => ['apps' => ['path' => '/srv/apps']]]);
    $before = $this->node->refresh()->getAttributes();
    $this->travel(1)->minutes();
    $this->patchJson($this->url, $patch)->assertOk()->assertJsonPath('data.skipped', 'node.unchanged');
    expect($this->node->refresh()->getAttributes())->toBe($before)
        ->and(Operation::query()->count())->toBe(0);
    Http::assertNothingSent();
    Process::assertNothingRan();
    Queue::assertNothingPushed();
})->with([
    'name' => [['name' => 'editable']],
    'platform' => [['platform' => 'linux']],
    'folder' => [['apps' => ['path' => '/srv/apps']]],
    'absent host' => [['task_vms' => null]],
]);

it('refuses a mixed invalid patch without saving its valid name', function (array $patch, string $code): void {
    $before = $this->node->refresh()->getAttributes();
    $this->patchJson($this->url, ['name' => 'must-not-save', ...$patch])
        ->assertUnprocessable()->assertJsonPath('error.code', $code);
    expect($this->node->refresh()->getAttributes())->toBe($before)
        ->and(Operation::query()->count())->toBe(0);
    Http::assertNothingSent();
    Process::assertNothingRan();
})->with([
    'relative folder' => [['apps' => ['path' => 'relative/apps']], 'node.settings_path_invalid'],
    'protected folder' => [['apps' => ['path' => '/etc/apps']], 'node.settings_path_protected'],
    'unknown platform' => [['platform' => 'unknown'], 'validation.failed'],
    'bad places' => [['task_vms' => ['cidr' => '10.251.76.0/24', 'task_places' => -1, 'ci_places' => 1]], 'node.settings_invalid'],
]);

it('requires Linux for folder work before saving a mixed rename', function (): void {
    $this->node->update(['platform' => 'ipados']);
    $this->patchJson($this->url, ['name' => 'must-not-save', 'apps' => ['path' => '/srv/apps']])
        ->assertUnprocessable()->assertJsonPath('error.code', 'node.settings_platform_unsupported');
    expect($this->node->refresh()->name)->toBe('editable')->and(Operation::query()->count())->toBe(0);
    Process::assertNothingRan();
});

it('refuses a managed platform edit for either a pin or any role', function (bool $pin): void {
    if ($pin) {
        Node::withoutEvents(fn () => $this->node->update(['ssh_host_fingerprint' => 'SHA256:pinned']));
    } else {
        $this->node->roles()->create(['role' => RoleName::AppDev, 'status' => 'failed']);
    }
    $this->patchJson($this->url, ['name' => 'must-not-save', 'platform' => 'macos'])
        ->assertConflict()->assertJsonPath('error.code', 'node.platform_managed');
    expect($this->node->refresh()->name)->toBe('editable')->and(Operation::query()->count())->toBe(0);
})->with([true, false]);

it('requires Gateway authority or a direct target grant', function (): void {
    $this->withServerVariables(['REMOTE_ADDR' => $this->node->wireguard_ip]);
    $this->patchJson('/api/v1/nodes/'.$this->gateway->id, ['name' => 'must-not-save'])
        ->assertForbidden()->assertJsonPath('error.code', 'node_access.required');
    $this->node->accessibleNodes()->attach($this->gateway);
    $this->patchJson('/api/v1/nodes/'.$this->gateway->id, ['name' => 'renamed-gateway'])->assertOk();
    expect($this->gateway->refresh()->name)->toBe('renamed-gateway')->and(Operation::query()->count())->toBe(0);
    Process::assertNothingRan();
});

it('holds the existing current-name and requested-name lifecycle locks', function (string $busy): void {
    $lock = $this->mock(NodeProvisioningLock::class);
    if ($busy === 'renamed') {
        $lock->shouldReceive('run')->once()->with('editable', Mockery::type(Closure::class))
            ->andReturnUsing(fn (string $name, Closure $callback): mixed => $callback());
    }
    $lock->shouldReceive('run')->once()->with($busy, Mockery::type(Closure::class))
        ->andThrow(new NodeProvisioningLockException($busy));
    $this->patchJson($this->url, ['name' => 'renamed'])->assertConflict()->assertJsonPath('error.code', 'node.provisioning_busy');
    expect($this->node->refresh()->name)->toBe('editable')->and(Operation::query()->count())->toBe(0);
})->with(['editable', 'renamed']);

it('prepares the default folder only when unsetting with active app-dev', function (bool $active): void {
    SwampNodeFixture::ensure();
    Node::withoutEvents(fn () => $this->node->update([
        'settings' => ['apps' => ['path' => '/srv/old-apps']],
        'ssh_host_key_type' => 'ssh-ed25519', 'ssh_host_key' => 'public-fixture', 'ssh_host_fingerprint' => 'SHA256:fixture',
    ]));
    $this->node->roles()->create(['role' => RoleName::AppDev, 'status' => $active ? 'active' : 'failed']);
    DB::beginTransaction();
    $response = $this->patchJson($this->url, ['name' => 'renamed', 'apps' => null]);
    if ($active) {
        $response->assertAccepted();
        $operation = Operation::query()->where('kind', 'node.update')->sole();
        expect($operation->input['root'])->toBe('/home/orbit/apps')
            ->and($operation->input['settings'])->toBeNull()
            ->and($this->node->refresh()->settings)->toBe(['apps' => ['path' => '/srv/old-apps']]);
    } else {
        $response->assertOk();
        expect($this->node->refresh()->settings)->toBeNull()->and(Operation::query()->count())->toBe(0);
    }
    expect($this->node->refresh()->name)->toBe('renamed');
    Process::assertNothingRan();
    DB::rollBack();
})->with([true, false]);
