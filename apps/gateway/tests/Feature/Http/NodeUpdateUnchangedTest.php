<?php

declare(strict_types=1);

use App\Domain\Nodes\RoleName;
use App\Models\Node;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Testing\TestResponse;

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
