<?php

declare(strict_types=1);

use App\Domain\Nodes\NodeSettingsOperationCompletion;
use App\Domain\Nodes\RoleName;
use App\Domain\Nodes\Storage\NodeSettingsParser;
use App\Domain\Operations\OperationOutcome;
use App\Domain\Shared\LifecycleStatus;
use App\Domain\Shared\ResourceOperationException;
use App\Http\Middleware\RequireActiveWireGuardPeer;
use App\Http\Middleware\RequireNodeAccess;
use App\Models\Node;
use App\Models\Operation;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Testing\TestResponse;
use Tests\Support\SwampNodeFixture;

uses(LazilyRefreshDatabase::class);

beforeEach(function (): void {
    Queue::fake();
    $this->withoutMiddleware([RequireActiveWireGuardPeer::class, RequireNodeAccess::class]);
});

/** A Node with the given roles, each in its status, and `$settings` stored. */
function ciPlacesNode(array $roles = [RoleName::CiRunner->value => LifecycleStatus::Active], ?array $settings = null): Node
{
    $node = Node::withoutEvents(fn (): Node => Node::query()->create([
        'name' => 'ci-node', 'status' => 'active', 'platform' => 'linux', 'architecture' => 'x86_64',
        'wireguard_ip' => '10.44.0.20', 'public_ssh_host' => '10.44.0.20', 'user' => 'orbit', 'settings' => $settings,
        'ssh_host_fingerprint' => 'SHA256:fixture', 'ssh_host_key_type' => 'ssh-ed25519', 'ssh_host_key' => 'fixture-public-key',
    ]));
    foreach ($roles as $role => $status) {
        $node->roles()->create(['role' => $role, 'status' => $status]);
    }

    return $node;
}

/** @param array<string, mixed> $body */
function ciPlacesPatch(Node $node, array $body): TestResponse
{
    return test()->patchJson("/api/v1/nodes/{$node->id}", $body);
}

it('records the Instance host opt-in of an app-dev Node as a setting, with no run, and clears it', function (): void {
    $node = ciPlacesNode([RoleName::AppDev->value => LifecycleStatus::Active], ['t3' => ['connection_id' => 'fixture']]);

    ciPlacesPatch($node, ['instance_host' => true])->assertOk()->assertJsonPath('data.settings.instance_host', true);
    expect($node->refresh()->settings)->toBe(['t3' => ['connection_id' => 'fixture'], 'instance_host' => true]);
    ciPlacesPatch($node, ['instance_host' => true])->assertOk()->assertJsonPath('meta.skipped', 'node.unchanged');
    ciPlacesPatch($node, ['instance_host' => 'yes'])->assertStatus(422);
    ciPlacesPatch($node, ['instance_host' => false])->assertOk();

    expect($node->refresh()->settings)->toBe(['t3' => ['connection_id' => 'fixture']]);
    $this->assertDatabaseCount('operations', 0);
});

describe('a Node with an active ci-runner role', function (): void {
    it('stores, shows and patches its CI places as a record', function (): void {
        $node = ciPlacesNode();
        test()->getJson("/api/v1/nodes/{$node->id}")->assertOk()->assertJsonMissingPath('data.settings.ci');

        ciPlacesPatch($node, ['ci' => ['places' => 4]])->assertOk()->assertJsonPath('data.settings.ci', ['places' => 4, 'proof_only' => false]);
        ciPlacesPatch($node, ['ci' => ['proof_only' => true]])->assertOk()->assertJsonPath('data.settings.ci', ['places' => 4, 'proof_only' => true]);
        ciPlacesPatch($node, ['ci' => ['places' => 2]])->assertOk()->assertJsonPath('data.settings.ci', ['places' => 2, 'proof_only' => true]);

        test()->getJson("/api/v1/nodes/{$node->id}")->assertOk()->assertJsonPath('data.settings.ci', ['places' => 2, 'proof_only' => true]);
        expect($node->refresh()->settings)->toBe(['ci' => ['places' => 2, 'proof_only' => true]]);
        $this->assertDatabaseCount('operations', 0);
    });

    it('reports node.unchanged when the CI places repeat the stored value', function (): void {
        $node = ciPlacesNode(settings: ['ci' => ['places' => 3, 'proof_only' => false]]);

        ciPlacesPatch($node, ['ci' => ['places' => 3]])->assertOk()->assertJsonPath('meta.skipped', 'node.unchanged');
        ciPlacesPatch($node, ['ci' => ['places' => 4]])->assertOk()->assertJsonMissingPath('meta.skipped');
    });

    it('keeps the other settings when its CI places change, and they keep its CI places', function (): void {
        $node = ciPlacesNode(settings: ['ci' => ['places' => 3, 'proof_only' => false], 't3' => ['connection_id' => 'fixture']]);

        ciPlacesPatch($node, ['nested_fleet' => true])->assertOk()->assertJsonPath('data.settings.ci.places', 3);
        ciPlacesPatch($node, ['ci' => ['places' => 1]])->assertOk();

        expect($node->refresh()->settings)->toBe(['t3' => ['connection_id' => 'fixture'], 'nested_fleet' => true, 'ci' => ['places' => 1, 'proof_only' => false]]);
    });

    it('refuses a first CI setting that names no places', function (): void {
        $node = ciPlacesNode();

        ciPlacesPatch($node, ['ci' => ['proof_only' => true]])->assertStatus(422)->assertJsonPath('error.code', 'node.settings_invalid');
        expect($node->refresh()->settings)->toBeNull();
    });

    it('refuses an invalid ci value and changes nothing', function (mixed $ci): void {
        $node = ciPlacesNode(settings: ['ci' => ['places' => 3, 'proof_only' => false]]);

        ciPlacesPatch($node, ['name' => 'renamed', 'ci' => $ci])->assertStatus(422)->assertJsonPath('error.code', 'node.settings_invalid');
        expect($node->refresh()->settings)->toBe(['ci' => ['places' => 3, 'proof_only' => false]])->and($node->name)->toBe('ci-node');
    })->with([
        'no member' => [(object) []],
        'more than 64 places' => [['places' => 65]],
        'negative places' => [['places' => -1]],
        'places as a string' => [['places' => '4']],
        'null places' => [['places' => null]],
        'proof_only as a string' => [['proof_only' => 'true']],
        'an unknown member' => [['places' => 2, 'runners' => 2]],
        'a list' => [[2]],
        'a number' => [2],
    ]);

    it('saves the CI places at once while an apps folder run starts, and the run keeps them', function (): void {
        SwampNodeFixture::ensure();
        $node = ciPlacesNode([RoleName::CiRunner->value => LifecycleStatus::Active, RoleName::AppDev->value => LifecycleStatus::Active],
            ['apps' => ['path' => '/home/orbit/apps']]);

        ciPlacesPatch($node, ['apps' => ['path' => '/home/orbit/other-apps'], 'ci' => ['places' => 2]])->assertAccepted();
        $operation = Operation::query()->sole();
        expect($node->refresh()->settings)->toBe(['apps' => ['path' => '/home/orbit/apps'], 'ci' => ['places' => 2, 'proof_only' => false]]);

        ciPlacesPatch($node, ['ci' => ['places' => 5]])->assertOk();
        app(NodeSettingsOperationCompletion::class)->complete($operation, OperationOutcome::succeeded());

        expect($node->refresh()->settings)->toBe(['apps' => ['path' => '/home/orbit/other-apps'], 'ci' => ['places' => 5, 'proof_only' => false]]);
    });
});

describe('a Node without an active ci-runner role', function (): void {
    it('refuses CI places with node.settings_ci_runner_required and changes nothing', function (array $roles, array $ci): void {
        $node = ciPlacesNode($roles);

        ciPlacesPatch($node, ['name' => 'renamed', 'ci' => $ci])->assertStatus(422)->assertJsonPath('error.code', 'node.settings_ci_runner_required');
        expect($node->refresh()->settings)->toBeNull()->and($node->name)->toBe('ci-node');
    })->with([
        'no role' => [[]],
        'another role' => [[RoleName::AppDev->value => LifecycleStatus::Active]],
        'a ci-runner role still provisioning' => [[RoleName::CiRunner->value => LifecycleStatus::Provisioning]],
        'a failed ci-runner role' => [[RoleName::CiRunner->value => LifecycleStatus::Failed]],
    ])->with([
        'places' => [['places' => 2]],
        'proof_only' => [['proof_only' => true]],
    ]);

    it('clears stored CI places, and a clear with nothing stored writes nothing', function (): void {
        $node = ciPlacesNode([], ['ci' => ['places' => 2, 'proof_only' => true], 'apps' => ['path' => '/srv/apps']]);

        ciPlacesPatch($node, ['ci' => null])->assertOk()->assertJsonMissingPath('data.settings.ci')->assertJsonMissingPath('meta.skipped');
        expect($node->refresh()->settings)->toBe(['apps' => ['path' => '/srv/apps']]);

        ciPlacesPatch($node, ['ci' => null])->assertOk()->assertJsonPath('meta.skipped', 'node.unchanged');
    });
});

it('takes no CI places at node:add', function (): void {
    expect(fn () => new NodeSettingsParser()->parseComplete((object) ['ci' => (object) ['places' => 2]]))
        ->toThrow(fn (ResourceOperationException $exception) => expect($exception->errorCode)->toBe('node.settings_invalid'));
});
