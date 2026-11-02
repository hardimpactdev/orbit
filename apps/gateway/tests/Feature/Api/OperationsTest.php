<?php

declare(strict_types=1);

use App\Models\Node;
use App\Models\Operation;
use Illuminate\Testing\TestResponse;
use Tests\Support\Orb220DeploymentApiFixture;

beforeEach(function (): void {
    $this->fixture = Orb220DeploymentApiFixture::create();
    $this->deployUrl = "/api/v1/instances/{$this->fixture->instance->id}/deploy";
    $this->asCaller = fn () => $this->withServerVariables(['REMOTE_ADDR' => $this->fixture->caller->wireguard_ip]);
});

/** An instance.deploy Operation on the fixture Instance, as the Swamp admission records it. */
function operation_row(object $test, string $status = 'running', array $state = []): Operation
{
    return Operation::query()->create(['kind' => 'instance.deploy', 'target_type' => 'instance', 'target_id' => $test->fixture->instance->id,
        'node_id' => $test->fixture->owner->id, 'status' => $status, 'input' => ['instance_id' => $test->fixture->instance->id],
        'lock_key' => in_array($status, ['queued', 'running'], true) ? 'instance:'.$test->fixture->instance->id : null, 'state' => $state,
        'started_at' => now(), 'finished_at' => in_array($status, ['queued', 'running'], true) ? null : now()]);
}

function cancel_operation(object $test, int $id): TestResponse
{
    return ($test->asCaller)()->call('POST', "/api/v1/operations/{$id}/cancel", server: ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json'], content: '{}');
}

describe('cancelling', function (): void {
    it('cancels an Operation whose run has not started at once and frees the Instance', function (): void {
        $operation = operation_row($this, 'queued');

        cancel_operation($this, $operation->id)->assertOk()
            ->assertJsonPath('data.status', 'cancelled')
            ->assertJsonPath('data.cancel_requested', true);

        expect($operation->refresh()->lock_key)->toBeNull();
    });

    it('leaves a finished operation as it is', function (): void {
        $operation = operation_row($this, 'succeeded');
        $sequence = $operation->refresh()->sequence;

        cancel_operation($this, $operation->id)->assertOk()->assertJsonPath('data.status', 'succeeded');

        expect($operation->refresh()->sequence)->toBe($sequence)->and($operation->cancel_requested_at)->toBeNull();
    });
});

describe('reading operations', function (): void {
    it('lists operations newest first with filters and without steps', function (): void {
        $first = operation_row($this, 'succeeded')->id;
        $second = operation_row($this, 'queued')->id;

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
        operation_row($this);

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
        $id = operation_row($this)->id;
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
