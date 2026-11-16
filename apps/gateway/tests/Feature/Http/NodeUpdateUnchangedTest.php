<?php

declare(strict_types=1);

use App\Domain\Nodes\RoleName;
use App\Models\Node;
use App\Models\Operation;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Testing\TestResponse;

uses(LazilyRefreshDatabase::class);

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
    'the same name' => [['name' => 'beast']],
    'the same name and reboot hold' => [['name' => 'beast', 'reboot_hold' => true]],
]);

it('reports no skip when any value of the request changes', function (array $second): void {
    $node = unchangedNode();
    unchangedPatch($node, ['name' => 'beast', 'reboot_hold' => true])->assertOk();

    unchangedPatch($node, $second)->assertOk()->assertJsonMissingPath('meta.skipped');
})->with([
    'another name' => [['name' => 'beast-2', 'reboot_hold' => true]],
    'another reboot hold' => [['name' => 'beast', 'reboot_hold' => false]],
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

it('refuses the removed task_vms and image_refresh_hold members as it refuses any unknown member, and keeps the stored value', function (string $member, mixed $value): void {
    $node = unchangedNode();
    $stored = ['task_vms' => ['cidr' => '10.252.10.0/24', 'task_places' => 2, 'ci_places' => 2]];
    Node::withoutEvents(fn (): bool => $node->update(['settings' => $stored]));
    $unknown = unchangedPatch($node, ['name' => 'changed', 'colour' => $value])->assertUnprocessable();

    $removed = unchangedPatch($node, ['name' => 'changed', $member => $value])->assertUnprocessable();

    expect($removed->json('error.code'))->toBe($unknown->json('error.code'))
        ->and(str_replace($member, 'colour', (string) $removed->json('error.message')))->toBe($unknown->json('error.message'))
        ->and($node->refresh()->name)->toBe('enrolled')
        ->and($node->settings)->toBe($stored)
        ->and(Operation::query()->count())->toBe(0);
    // A later update leaves the stored value, which no code reads, as it is.
    unchangedPatch($node, ['name' => 'changed'])->assertOk()->assertJsonMissingPath('data.settings.task_vms')->assertJsonMissingPath('data.image_refresh_hold');
    expect($node->refresh()->settings)->toBe($stored);
})->with([
    'task_vms' => ['task_vms', ['cidr' => '10.252.10.0/24', 'task_places' => 2, 'ci_places' => 2]],
    'image_refresh_hold' => ['image_refresh_hold', true],
]);
