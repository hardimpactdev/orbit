<?php

declare(strict_types=1);

use App\Domain\Nodes\RoleName;
use App\Models\Node;
use App\Models\Operation;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Testing\TestResponse;

use function Pest\Laravel\getJson;
use function Pest\Laravel\travel;
use function Pest\Laravel\withServerVariables;

uses(LazilyRefreshDatabase::class);

const UnchangedHost = ['cidr' => '10.252.10.0/24', 'task_places' => 2, 'ci_places' => 2, 'containers' => ['cidr' => '10.252.11.0/24', 'task_places' => 1, 'ci_places' => 1]];

function unchangedNode(): Node
{
    $node = Node::withoutEvents(fn (): Node => Node::query()->create([
        'name' => 'enrolled', 'status' => 'active', 'platform' => 'linux',
        'wireguard_ip' => '10.44.0.10', 'public_ssh_host' => '10.44.0.10', 'user' => 'orbit',
    ]));
    $node->roles()->create(['role' => RoleName::Gateway, 'status' => 'active']);

    return $node;
}

/** @param array<string, mixed> $body */
function unchangedPatch(Node $node, array $body): TestResponse
{
    return test()->withServerVariables(['REMOTE_ADDR' => $node->wireguard_ip])->patchJson("/api/v1/nodes/{$node->id}", $body);
}

it('reports node.unchanged and writes nothing when a record-only request repeats the stored values', function (array $body): void {
    $node = unchangedNode();
    unchangedPatch($node, $body)->assertOk()->assertJsonMissingPath('meta.skipped');
    $stored = $node->refresh()->only(['name', 'platform', 'settings', 'updated_at']);

    $this->travel(5)->minutes();
    unchangedPatch($node, $body)->assertOk()->assertJsonPath('meta.skipped', 'node.unchanged')->assertJsonPath('data.id', $node->id);

    expect($node->refresh()->only(['name', 'platform', 'settings', 'updated_at']))->toEqual($stored);
})->with([
    'task VM host settings with containers' => [['task_vms' => UnchangedHost]],
    'the same name' => [['name' => 'beast']],
    'the same name and host settings' => [['name' => 'beast', 'task_vms' => UnchangedHost]],
]);

it('reports no skip when any value of the request changes', function (array $second): void {
    $node = unchangedNode();
    unchangedPatch($node, ['name' => 'beast', 'task_vms' => UnchangedHost])->assertOk();

    unchangedPatch($node, $second)->assertOk()->assertJsonMissingPath('meta.skipped');
})->with([
    'another name' => [['name' => 'beast-2', 'task_vms' => UnchangedHost]],
    'other container places' => [['task_vms' => [...UnchangedHost, 'containers' => [...UnchangedHost['containers'], 'task_places' => 2]]]],
    'other VM places' => [['task_vms' => [...UnchangedHost, 'ci_places' => 3]]],
]);

it('stores and clears a reboot hold through node update and shows it in every Node read', function (): void {
    $node = unchangedNode();
    $this->withServerVariables(['REMOTE_ADDR' => $node->wireguard_ip]);
    $this->getJson("/api/v1/nodes/{$node->id}")->assertOk()->assertJsonPath('data.reboot_hold', false);
    foreach ([true, false] as $hold) {
        unchangedPatch($node, ['reboot_hold' => $hold])->assertOk()->assertJsonPath('data.reboot_hold', $hold);
        expect($node->fresh()->reboot_hold)->toBe($hold);
        $this->getJson("/api/v1/nodes/{$node->id}")->assertOk()->assertJsonPath('data.reboot_hold', $hold);
        $this->getJson('/api/v1/nodes')->assertOk()->assertJsonPath('data.0.reboot_hold', $hold);
        $stored = $node->refresh()->updated_at;
        $this->travel(5)->minutes();
        unchangedPatch($node, ['reboot_hold' => $hold])->assertOk()->assertJsonPath('meta.skipped', 'node.unchanged');
        expect($node->refresh()->updated_at)->toEqual($stored);
    }
    expect(Operation::query()->count())->toBe(0);
});

it('rejects a reboot hold that is not a JSON boolean without changing the Node', function (mixed $hold): void {
    $node = unchangedNode();
    unchangedPatch($node, ['name' => 'changed', 'reboot_hold' => $hold])->assertUnprocessable();
    expect($node->refresh()->name)->toBe('enrolled')->and($node->reboot_hold)->toBeFalse();
})->with(['string' => ['true'], 'number' => [1], 'null' => [null], 'object' => [(object) []]]);

it('stores and clears an image refresh hold through node update and shows it in every Node read', function (): void {
    $node = unchangedNode();
    unchangedPatch($node, ['task_vms' => UnchangedHost])->assertOk();
    $settings = $node->refresh()->settings;
    withServerVariables(['REMOTE_ADDR' => $node->wireguard_ip]);
    getJson("/api/v1/nodes/{$node->id}")->assertOk()->assertJsonPath('data.image_refresh_hold', false);
    foreach ([true, false] as $hold) {
        unchangedPatch($node, ['image_refresh_hold' => $hold])->assertOk()->assertJsonPath('data.image_refresh_hold', $hold);
        expect($node->refresh()->image_refresh_hold)->toBe($hold)
            ->and($node->settings)->toBe($settings);
        getJson("/api/v1/nodes/{$node->id}")->assertOk()->assertJsonPath('data.image_refresh_hold', $hold);
        getJson('/api/v1/nodes')->assertOk()->assertJsonPath('data.0.image_refresh_hold', $hold);
        $stored = $node->refresh()->updated_at;
        travel(5)->minutes();
        unchangedPatch($node, ['image_refresh_hold' => $hold])->assertOk()->assertJsonPath('meta.skipped', 'node.unchanged');
        expect($node->refresh()->updated_at)->toEqual($stored);
    }
    expect(Operation::query()->count())->toBe(0);
});

it('rejects an image refresh hold that is not a JSON boolean without changing the Node', function (mixed $hold): void {
    $node = unchangedNode();
    unchangedPatch($node, ['name' => 'changed', 'image_refresh_hold' => $hold])->assertUnprocessable();
    expect($node->refresh()->name)->toBe('enrolled')->and($node->image_refresh_hold)->toBeFalse();
})->with(['string' => ['true'], 'number' => [1], 'null' => [null], 'object' => [(object) []]]);
