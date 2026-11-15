<?php

declare(strict_types=1);

use App\Actions\Operations\CancelOperationAction;
use App\Actions\Operations\ExpireOperationAction;
use App\Actions\Operations\FailUnreadableResultAction;
use App\Domain\Instances\Deployment\InstanceDeployExecutionCopies;
use App\Domain\Operations\OperationStatus;
use App\Domain\Operations\OperationStore;
use App\Domain\Shared\ResourceOperationException;
use App\Infrastructure\AgentView\WebSocketClient;
use App\Infrastructure\AgentView\WebSocketEndpoint;
use App\Infrastructure\Swamp\StartSwampRun;
use App\Models\Node;
use App\Models\Operation;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;

uses(LazilyRefreshDatabase::class);

beforeEach(function (): void {
    Queue::fake();
    $this->node = Node::withoutEvents(fn (): Node => Node::query()->create([
        'name' => 'beast', 'status' => 'active', 'platform' => 'linux', 'wireguard_ip' => '10.44.0.7', 'public_ssh_host' => '10.44.0.7', 'user' => 'orbit',
    ]));
    // Serve as the Gateway sees it: a vault.delete removes a stored key or answers not_found.
    $this->socket = new class implements WebSocketClient
    {
        /** @var array<string,true> */
        public array $stored = [];

        /** @var list<string> */
        public array $deleted = [];

        public int $connects = 0;

        public bool $down = false;

        private array $last = [];

        public function connect(WebSocketEndpoint $endpoint, float $timeoutSeconds): void
        {
            if ($this->down) {
                throw new RuntimeException('serve is down');
            }
            $this->connects++;
        }

        public function send(array $message): void
        {
            $this->last = $message;
        }

        public function receive(float $timeoutSeconds): array
        {
            $key = $this->last['payload']['key'];
            if (! isset($this->stored[$key])) {
                return [['id' => $this->last['id'], 'type' => 'error', 'error' => ['code' => 'not_found']]];
            }
            unset($this->stored[$key]);
            $this->deleted[] = $key;

            return [['id' => $this->last['id'], 'type' => 'vault.delete', 'payload' => ['data' => ['vaultName' => 'orbit-control-inputs', 'secretKey' => $key]]]];
        }

        public function close(): void {}

        public function isConnected(): bool
        {
            return true;
        }
    };
    app()->instance(WebSocketClient::class, $this->socket);
    config(['swamp.address' => 'swamp.test', 'swamp.ca_path' => '/tmp/ca.pem', 'swamp.run_token' => 'test-token']);
});

/** A deploy whose run holds its copies on serve, as its start left them. */
function deployWithCopies(string $variant = 'container', OperationStatus $status = OperationStatus::Running, int $steps = 2): Operation
{
    $step = ['name' => 'build', 'phase' => 'before_activation', 'required' => true, 'timeout_seconds' => 300, 'command_digest' => str_repeat('a', 64)];
    $deploy = Operation::query()->create(['kind' => 'instance.deploy', 'target_type' => 'instance', 'target_id' => 1, 'node_id' => test()->node->id,
        'status' => $status, 'input' => ['deployment' => ['variant' => $variant, 'steps' => array_fill(0, $steps, $step)]],
        'state' => $status === OperationStatus::Queued ? [] : ['swamp_run_id' => 'run-1']]);
    foreach (InstanceDeployExecutionCopies::keys($deploy) as $key) {
        test()->socket->stored[$key] = true;
    }

    return $deploy;
}

function endDeploy(Operation $deploy, OperationStatus $status): void
{
    app(OperationStore::class)->change($deploy, ['status' => $status, 'finished_at' => now()]);
}

it('names a deploy\'s own copies after its id and variant', function (string $variant, array $keys): void {
    $deploy = deployWithCopies($variant);
    $id = $deploy->id;

    expect(InstanceDeployExecutionCopies::keys($deploy))->toBe(array_map(static fn (string $key): string => str_replace('{id}', (string) $id, $key), $keys));
})->with([
    'container default' => ['container', ['development-deploy-operation-{id}-credential', 'development-deploy-operation-{id}-processes',
        'development-deploy-operation-{id}-step-0', 'development-deploy-operation-{id}-step-1']],
    'checkout default' => ['development', ['development-deploy-operation-{id}-credential', 'development-deploy-operation-{id}-processes',
        'development-deploy-operation-{id}-step-0', 'development-deploy-operation-{id}-step-1']],
    'production' => ['production', ['instance-deploy-operation-{id}-credential', 'instance-deploy-operation-{id}-step-0', 'instance-deploy-operation-{id}-step-1']],
]);

it('deletes a deploy\'s copies over one connection when its run ends', function (OperationStatus $status): void {
    $deploy = deployWithCopies();
    $other = deployWithCopies();

    endDeploy($deploy, $status);

    expect($this->socket->deleted)->toBe(InstanceDeployExecutionCopies::keys($deploy))
        ->and($this->socket->connects)->toBe(1)
        ->and(array_keys($this->socket->stored))->toBe(InstanceDeployExecutionCopies::keys($other));
})->with([OperationStatus::Succeeded, OperationStatus::Failed, OperationStatus::Cancelled, OperationStatus::Interrupted]);

it('deletes the copies only once the transaction that ended the deploy commits', function (): void {
    $deploy = deployWithCopies();

    DB::transaction(function () use ($deploy): void {
        endDeploy($deploy, OperationStatus::Succeeded);
        expect($this->socket->deleted)->toBe([]);
    });

    expect($this->socket->deleted)->toBe(InstanceDeployExecutionCopies::keys($deploy));
});

it('does not delete again when an ended deploy changes once more', function (): void {
    $deploy = deployWithCopies();
    endDeploy($deploy, OperationStatus::Failed);
    $this->socket->connects = 0;

    app(OperationStore::class)->change($deploy, ['failed_step' => 'Finish the container']);

    expect($this->socket->connects)->toBe(0);
});

it('keeps the copies of a running deploy', function (): void {
    $deploy = deployWithCopies(status: OperationStatus::Queued);

    app(OperationStore::class)->change($deploy, ['status' => OperationStatus::Running, 'started_at' => now()]);

    expect($this->socket->connects)->toBe(0)->and($this->socket->stored)->toHaveCount(4);
});

it('deletes the copies of a deploy whose start was refused after they were written', function (): void {
    $deploy = deployWithCopies();
    $deploy->update(['state' => []]);

    app(StartSwampRun::class)->refuse($deploy, new ResourceOperationException('swamp.start_unavailable', 'The native workflow start is unavailable.', 503));

    expect($deploy->refresh()->status)->toBe(OperationStatus::Failed)->and($this->socket->stored)->toBe([]);
});

it('deletes the copies of a deploy cancelled before its run', function (): void {
    $deploy = deployWithCopies(status: OperationStatus::Queued);

    app(CancelOperationAction::class)->execute($deploy);

    expect($deploy->refresh()->status)->toBe(OperationStatus::Cancelled)->and($this->socket->stored)->toBe([]);
});

it('deletes the copies of a deploy whose run ended without a readable result', function (): void {
    $deploy = deployWithCopies();

    app(FailUnreadableResultAction::class)->execute($deploy, 'run-1', ['status' => 'succeeded', 'completedAt' => now()->toIso8601ZuluString()],
        'Finish the container', 'skipped');

    expect($deploy->refresh()->error_code)->toBe('operation.result_unreadable')->and($this->socket->stored)->toBe([]);
});

it('keeps a timed-out deploy\'s copies while its run may still read them, and deletes them once Swamp confirms the run ended', function (): void {
    $deploy = deployWithCopies();
    // A deploy holds its Instance until its run is proven ended.
    $deploy->update(['started_at' => now()->subDays(1), 'lock_key' => 'instance:1']);

    app(ExpireOperationAction::class)->execute($deploy->refresh());

    expect($deploy->refresh()->error_code)->toBe('operation.timed_out')
        ->and(InstanceDeployExecutionCopies::ended($deploy))->toBeFalse()
        ->and($this->socket->stored)->toHaveCount(4);

    app(ExpireOperationAction::class)->settle($deploy, 'run-1', 'cancelled');

    expect($this->socket->stored)->toBe([]);
});

it('keeps the deploy\'s end when serve cannot delete its copies', function (): void {
    $deploy = deployWithCopies();
    $this->socket->down = true;
    Log::spy();

    endDeploy($deploy, OperationStatus::Succeeded);

    expect($deploy->refresh()->status)->toBe(OperationStatus::Succeeded)->and($this->socket->stored)->toHaveCount(4);
    Log::shouldHaveReceived('warning')->with('Deploy execution copies could not be deleted', ['operation_id' => $deploy->id, 'exception_type' => ResourceOperationException::class]);
});

it('prunes the copies of every ended deploy and keeps those of open and unsettled ones', function (): void {
    $ended = collect([OperationStatus::Succeeded, OperationStatus::Failed, OperationStatus::Cancelled])
        ->map(fn (OperationStatus $status): Operation => deployWithCopies(status: $status));
    $production = deployWithCopies('production', OperationStatus::Succeeded);
    $running = deployWithCopies();
    $queued = deployWithCopies(status: OperationStatus::Queued);
    $unsettled = deployWithCopies(status: OperationStatus::Failed);
    $unsettled->update(['error_code' => 'operation.timed_out', 'state' => ['swamp_run_id' => 'run-1', 'native_timeout' => ['budget_seconds' => 1]]]);
    $open = [...InstanceDeployExecutionCopies::keys($running), ...InstanceDeployExecutionCopies::keys($queued), ...InstanceDeployExecutionCopies::keys($unsettled)];

    $this->artisan('orbit:instance-deploy-copies-prune', ['--dry-run' => true])
        ->expectsOutput('Would delete 15 key names of 4 ended deploys; kept 1 timed-out deploys whose run has not settled.')->assertSuccessful();
    expect($this->socket->connects)->toBe(0);

    $this->artisan('orbit:instance-deploy-copies-prune')
        ->expectsOutput('Deleted 15 key names of 4 ended deploys; kept 1 timed-out deploys whose run has not settled.')->assertSuccessful();

    expect(array_keys($this->socket->stored))->toEqualCanonicalizing($open)
        ->and($this->socket->deleted)->toContain(...InstanceDeployExecutionCopies::keys($production), ...InstanceDeployExecutionCopies::keys($ended->first()));
});
