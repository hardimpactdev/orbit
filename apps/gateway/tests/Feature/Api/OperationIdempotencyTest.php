<?php

declare(strict_types=1);

use App\Actions\Instances\StartInstanceDeployAction;
use App\Domain\Operations\OperationDispatcher;
use App\Domain\Operations\OperationIdempotency;
use App\Domain\Operations\OperationKinds;
use App\Domain\Operations\OperationStatus;
use App\Models\Node;
use App\Models\Operation;
use App\Models\Project;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Tests\Support\Orb220DeploymentApiFixture;

beforeEach(function (): void {
    enable_operation_queue();
    $this->fixture = Orb220DeploymentApiFixture::create();
    $this->withServerVariables(['REMOTE_ADDR' => $this->fixture->caller->wireguard_ip]);
});

function keyed_operation_start(object $test, string $kind = 'deploy', string $input = '{}', string $key = 'work.handoff:c2-1_A', ?int $target = null): TestResponse
{
    $target ??= $test->fixture->instance->id;

    return $test->call('POST', "/api/v1/instances/{$target}/{$kind}", server: [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_ACCEPT' => 'application/json',
        'HTTP_IDEMPOTENCY_KEY' => $key,
    ], content: $input);
}

describe('operation start keys', function (): void {
    it('stores the key on the first start and queues one job', function (): void {
        $response = keyed_operation_start($this)->assertAccepted()->assertJsonPath('data.status', 'queued');

        $this->assertDatabaseHas('operations', ['id' => $response->json('data.id'), 'kind' => 'instance.deploy', 'idempotency_key' => 'work.handoff:c2-1_A']);
        $this->assertDatabaseCount('operations', 1);
        $this->assertDatabaseCount('jobs', 1);
    });

    it('replays one operation with status 202 before domain validation or queue availability', function (): void {
        $id = keyed_operation_start($this)->assertAccepted()->json('data.id');
        $this->fixture->instance->update(['deployment_branch' => 'bad..branch']);
        config(['orbit.gateway_queue.connection' => '']);

        keyed_operation_start($this)->assertAccepted()->assertJsonPath('data.id', $id)->assertJsonPath('data.status', 'queued');

        $this->assertDatabaseCount('operations', 1);
        $this->assertDatabaseCount('jobs', 1);
    });

    it('replays a finished operation without starting another', function (string $status): void {
        $id = keyed_operation_start($this)->assertAccepted()->json('data.id');
        Operation::query()->whereKey($id)->update(['status' => $status, 'lock_key' => null, 'finished_at' => now()]);

        keyed_operation_start($this)->assertAccepted()->assertJsonPath('data.id', $id)->assertJsonPath('data.status', $status);

        $this->assertDatabaseCount('operations', 1);
        $this->assertDatabaseCount('jobs', 1);
    })->with(['succeeded', 'failed', 'cancelled']);

    it('returns 409 for different input before further validation', function (string $input): void {
        keyed_operation_start($this, 'rollback', '{"release":"initial"}')->assertAccepted();

        keyed_operation_start($this, 'rollback', $input)->assertConflict()->assertJsonPath('error.code', 'operation.idempotency_conflict');

        $this->assertDatabaseCount('operations', 1);
        $this->assertDatabaseCount('jobs', 1);
    })->with(['another release' => '{"release":"fresh"}', 'invalid release' => '{"release":false}', 'unknown field' => '{"release":"initial","extra":1}', 'malformed JSON' => '{', 'non-finite number' => '{"release":1e400}', 'duplicate member' => '{"release":"fresh","release":"initial"}']);

    it('returns 422 for non-finite input on a new key', function (): void {
        keyed_operation_start($this, 'rollback', '{"release":1e400}')->assertUnprocessable()
            ->assertJsonPath('error.code', 'validation.failed')
            ->assertJsonPath('error.details.release.0', 'The release field must be a string.');

        $this->assertDatabaseCount('operations', 0);
        $this->assertDatabaseCount('jobs', 0);
    });

    it('returns 409 for another target before domain validation', function (): void {
        keyed_operation_start($this)->assertAccepted();
        $project = Project::query()->create(['name' => 'Other', 'slug' => 'other', 'repository_url' => 'https://example.test/other.git', 'default_branch' => 'main', 'root' => 'public']);
        $other = $this->fixture->instance->replicate()->fill(['project_id' => $project->id, 'name' => 'other', 'checkout_path' => '/home/other/releases/initial', 'production_home' => '/home/other', 'production_user' => 'other', 'deployment_branch' => 'bad..branch']);
        $other->save();

        keyed_operation_start($this, target: $other->id)->assertConflict()->assertJsonPath('error.code', 'operation.idempotency_conflict');

        $this->assertDatabaseCount('operations', 1);
        $this->assertDatabaseCount('jobs', 1);
    });

    it('allows the same key on another kind', function (): void {
        $deploy = keyed_operation_start($this)->assertAccepted()->json('data.id');

        $rollback = keyed_operation_start($this, 'rollback', '{"release":"initial"}')->assertAccepted()->assertJsonPath('data.kind', 'instance.rollback')->json('data.id');

        expect($rollback)->not->toBe($deploy);
        $this->assertDatabaseCount('operations', 2);
        $this->assertDatabaseHas('operations', ['id' => $rollback, 'idempotency_key' => 'work.handoff:c2-1_A']);
        $this->assertDatabaseCount('jobs', 2);
    });

    it('ignores JSON whitespace when replaying input', function (): void {
        $id = keyed_operation_start($this, 'rollback', '{"release":"initial"}')->assertAccepted()->json('data.id');

        keyed_operation_start($this, 'rollback', "{ \n \"release\" : \"initial\" }")->assertAccepted()->assertJsonPath('data.id', $id);

        $this->assertDatabaseCount('jobs', 1);
    });

    it('returns 422 for a malformed key before body validation', function (string $key): void {
        keyed_operation_start($this, input: '{', key: $key)->assertUnprocessable()
            ->assertJsonPath('error.code', 'validation.failed')
            ->assertJsonPath('error.details.Idempotency-Key.0', 'The Idempotency-Key header must have 1 to 128 characters from A-Z a-z 0-9 . _ : -.');

        $this->assertDatabaseCount('operations', 0);
        $this->assertDatabaseCount('jobs', 0);
    })->with(['empty' => '', 'too long' => str_repeat('a', 129), 'space' => 'key two', 'slash' => 'key/two', 'unicode' => 'clé', 'newline' => "key\n"]);

    it('accepts the maximum key length', function (): void {
        keyed_operation_start($this, key: str_repeat('a', 128))->assertAccepted();

        $this->assertDatabaseHas('operations', ['idempotency_key' => str_repeat('a', 128)]);
    });

    it('scopes the key across calling Nodes but still checks access', function (): void {
        $id = keyed_operation_start($this)->assertAccepted()->json('data.id');
        $caller = Node::query()->create([
            'name' => 'other-operator', 'status' => 'active', 'platform' => 'linux',
            'public_ssh_host' => '192.0.2.222', 'wireguard_ip' => '10.44.0.222', 'user' => 'orbit',
        ]);
        $this->withServerVariables(['REMOTE_ADDR' => $caller->wireguard_ip]);

        keyed_operation_start($this)->assertForbidden()->assertJsonPath('error.code', 'node_access.required');
        $this->assertDatabaseCount('operations', 1);
        $this->assertDatabaseCount('jobs', 1);

        $caller->accessibleNodes()->attach($this->fixture->owner->id);

        keyed_operation_start($this)->assertAccepted()->assertJsonPath('data.id', $id);

        $this->assertDatabaseCount('operations', 1);
        $this->assertDatabaseCount('jobs', 1);
    });

    it('refuses to record an operation when the start marker names another kind', function (): void {
        $identity = app(OperationIdempotency::class);
        $identity->kind = OperationKinds::InstanceRollback;
        $identity->key = 'wrong-kind';
        $identity->fingerprint = str_repeat('a', 64);

        expect(fn () => app(StartInstanceDeployAction::class)->execute($this->fixture->instance, 'test', null))
            ->toThrow(LogicException::class, 'Operation start [instance.deploy] does not match its StartsOperation kind [instance.rollback].');

        $this->assertDatabaseCount('operations', 0);
        $this->assertDatabaseCount('jobs', 0);
    });

    it('settles the concurrent insert race with one operation and one dispatch', function (bool $conflict): void {
        $reads = 0;
        $winner = null;
        // Insert the competing winner after the last preflight read returned no row. The losing INSERT must
        // hit the real unique index; this also covers a winner whose target differs and has no lock collision.
        DB::listen(function (QueryExecuted $query) use (&$reads, &$winner, $conflict): void {
            if (! str_starts_with($query->sql, 'select * from "operations" where "kind" =') || ++$reads !== 2) {
                return;
            }

            $identity = app(OperationIdempotency::class);
            $winner = Operation::query()->create([
                'kind' => 'instance.deploy', 'target_type' => 'instance', 'target_id' => $conflict ? 9999 : $this->fixture->instance->id,
                'node_id' => $this->fixture->owner->id, 'lock_key' => $conflict ? null : 'instance:'.$this->fixture->instance->id,
                'input' => [], 'status' => OperationStatus::Queued, 'sequence' => 1, 'attempts' => 0,
                'idempotency_key' => $identity->key, 'idempotency_fingerprint' => $conflict ? str_repeat('0', 64) : $identity->fingerprint,
            ]);
            app(OperationDispatcher::class)->dispatch($winner);
        });

        $response = keyed_operation_start($this);

        expect($winner)->toBeInstanceOf(Operation::class);
        if ($conflict) {
            $response->assertConflict()->assertJsonPath('error.code', 'operation.idempotency_conflict');
        } else {
            $response->assertAccepted()->assertJsonPath('data.id', $winner->id);
        }
        $this->assertDatabaseCount('operations', 1);
        $this->assertDatabaseCount('jobs', 1);
    })->with(['matching winner' => false, 'conflicting winner' => true]);
});
