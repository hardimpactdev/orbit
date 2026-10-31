<?php

declare(strict_types=1);

use App\Domain\Operations\OperationStatus;
use App\Models\Activity;
use App\Models\InstanceDeployment;
use App\Models\Node;
use App\Models\Operation;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Tests\Support\Orb220DeploymentApiFixture;

beforeEach(function (): void {
    enable_operation_queue();
    $this->fixture = Orb220DeploymentApiFixture::create();
    $this->deployUrl = "/api/v1/instances/{$this->fixture->instance->id}/deploy";
    $this->asCaller = fn () => $this->withServerVariables(['REMOTE_ADDR' => $this->fixture->caller->wireguard_ip]);
});

function start_deploy(object $test): TestResponse
{
    return ($test->asCaller)()->call('POST', $test->deployUrl, server: [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_ACCEPT' => 'application/json',
    ], content: '{}');
}

describe('starting a deploy', function (): void {
    it('answers 202 with a held Swamp intent and no Gateway execution job', function (): void {
        $response = start_deploy($this);

        $response->assertAccepted()
            ->assertJsonPath('data.kind', 'instance.deploy')
            ->assertJsonPath('data.target_type', 'instance')
            ->assertJsonPath('data.target_id', $this->fixture->instance->id)
            ->assertJsonPath('data.status', 'queued')
            ->assertJsonPath('data.sequence', 1)
            ->assertJsonPath('data.poll_after_seconds', 2)
            ->assertJsonPath('data.triggered_by', $this->fixture->caller->name)
            ->assertJsonMissingPath('data.steps');

        $operation = Operation::query()->sole();

        expect($operation->lock_key)->toBe("instance:{$this->fixture->instance->id}")
            ->and($operation->node_id)->toBe($this->fixture->owner->id)
            ->and(DB::table('jobs')->count())->toBe(0)
            ->and(Activity::query()->sole()->status)->toBe('succeeded')
            ->and(InstanceDeployment::query()->count())->toBe(0)
            ->and($this->fixture->deployment->invocations)->toBe(0);
    });

    it('refuses a second deploy while the first one holds the Instance', function (): void {
        $first = start_deploy($this)->json('data.id');

        start_deploy($this)
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'operation.target_busy')
            ->assertJsonPath('error.details.operation_id', $first);

        expect(Operation::query()->count())->toBe(1);
    });

    it('admits Swamp intent without the Gateway execution queue', function (): void {
        config(['orbit.gateway_queue.connection' => '']);

        start_deploy($this)->assertAccepted();

        expect(Operation::query()->count())->toBe(1)->and(DB::table('jobs')->count())->toBe(0);
    });

    it('refuses an Instance that cannot be deployed before it records anything', function (): void {
        $this->fixture->instance->update(['deployment_branch' => 'bad..branch']);

        start_deploy($this)->assertStatus(409)->assertJsonPath('error.code', 'deployment_config.unavailable');

        expect(Operation::query()->count())->toBe(0);
    });

});

describe('reading operations', function (): void {
    it('lists operations newest first with filters and without steps', function (): void {
        $first = start_deploy($this)->json('data.id');
        Operation::query()->findOrFail($first)->update(['status' => OperationStatus::Succeeded, 'lock_key' => null, 'finished_at' => now()]);
        $second = start_deploy($this)->json('data.id');

        ($this->asCaller)()->getJson('/api/v1/operations')->assertOk()
            ->assertJsonPath('data.0.id', $second)
            ->assertJsonPath('data.1.id', $first)
            ->assertJsonMissingPath('data.0.steps');
        ($this->asCaller)()->getJson('/api/v1/operations?status=queued')->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $second);
        ($this->asCaller)()->getJson("/api/v1/operations?target_type=instance&target_id={$this->fixture->instance->id}&kind=instance.deploy")
            ->assertOk()->assertJsonCount(2, 'data');
        ($this->asCaller)()->getJson('/api/v1/operations?status=sleeping')->assertStatus(422);
    });

    it('lists nothing for a well-formed kind or target type that no operation has', function (string $query): void {
        start_deploy($this);

        ($this->asCaller)()->getJson("/api/v1/operations?{$query}")->assertOk()->assertJsonPath('data', []);
    })->with([
        'kind' => 'kind=instance.restart',
        'target type' => 'target_type=route',
    ]);

    it('refuses a kind or target type in another form', function (string $query, string $field, string $message): void {
        ($this->asCaller)()->getJson("/api/v1/operations?{$query}")
            ->assertUnprocessable()
            ->assertJsonPath('error.code', 'validation.failed')
            ->assertJsonPath("error.details.{$field}.0", $message);
    })->with([
        'kind without a verb' => ['kind=instance', 'kind', 'The kind field format is invalid.'],
        'kind in upper case' => ['kind=Instance.Deploy', 'kind', 'The kind field format is invalid.'],
        'kind with three parts' => ['kind=instance.deploy.now', 'kind', 'The kind field format is invalid.'],
        'kind longer than its column' => ['kind=instance.'.str_repeat('a', 56), 'kind', 'The kind field must not be greater than 64 characters.'],
        'target type with a verb' => ['target_type=instance.deploy', 'target_type', 'The target type field format is invalid.'],
        'target type in upper case' => ['target_type=Instance', 'target_type', 'The target type field format is invalid.'],
        'target type longer than its column' => ['target_type='.str_repeat('a', 33), 'target_type', 'The target type field must not be greater than 32 characters.'],
    ]);

    it('needs access to the target Node, and lists only operations the caller can access', function (): void {
        $id = start_deploy($this)->json('data.id');
        $stranger = Node::query()->create([
            'name' => 'stranger',
            'status' => 'active',
            'platform' => 'linux',
            'public_ssh_host' => '192.0.2.230',
            'wireguard_ip' => '10.44.0.230',
            'user' => 'orbit',
        ]);
        $other = Node::query()->create([
            'name' => 'other',
            'status' => 'active',
            'platform' => 'linux',
            'public_ssh_host' => '192.0.2.231',
            'wireguard_ip' => '10.44.0.231',
            'user' => 'orbit',
        ]);
        $stranger->accessibleNodes()->attach($other->id);

        $this->withServerVariables(['REMOTE_ADDR' => $stranger->wireguard_ip])->getJson("/api/v1/operations/{$id}")
            ->assertStatus(403)->assertJsonPath('error.code', 'node_access.required');
        $this->withServerVariables(['REMOTE_ADDR' => $stranger->wireguard_ip])->getJson('/api/v1/operations')
            ->assertOk()->assertJsonPath('data', []);
        ($this->asCaller)()->getJson('/api/v1/operations/999999')->assertNotFound();
    });
});

it('cancels a queued Swamp intent and frees the Instance without a Gateway execution job', function (): void {
    $id = start_deploy($this)->assertAccepted()->json('data.id');
    ($this->asCaller)()->call('POST', "/api/v1/operations/{$id}/cancel", server: ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json'], content: '{}')->assertOk()
        ->assertJsonPath('data.status', 'cancelled')->assertJsonPath('data.cancel_requested', true);
    expect(Operation::query()->sole()->lock_key)->toBeNull()
        ->and(DB::table('jobs')->count())->toBe(0)->and(InstanceDeployment::query()->count())->toBe(0);
});
