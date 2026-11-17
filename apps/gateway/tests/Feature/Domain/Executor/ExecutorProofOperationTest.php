<?php

declare(strict_types=1);

use App\Domain\Executor\ExecutorOrderBroadcast;
use App\Domain\Executor\ExecutorOrderSigner;
use App\Domain\Executor\ExecutorProofOperation;
use App\Domain\Operations\DirectOperation;
use App\Domain\Operations\OperationKinds;
use App\Domain\Operations\OperationStatus;
use App\Domain\Shared\ResourceOperationException;
use App\Domain\WebSocket\WebSocketCredentialManager;
use App\Domain\WebSocket\WebSocketCredentials;
use App\Http\Requests\Operations\StartsOperation;
use App\Infrastructure\Activity\CommandActivityInputSanitizer;
use App\Infrastructure\Executor\ExecutorReply;
use App\Infrastructure\Executor\ExecutorResultQueue;
use App\Infrastructure\Executor\ExecutorResults;
use App\Infrastructure\Executor\ExecutorResultWait;
use App\Infrastructure\Executor\ExecutorWaitStatus;
use App\Models\Node;
use App\Models\Operation;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Routing\Route as RoutingRoute;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Testing\Fakes\EventFake;
use Psr\Log\NullLogger;

/*
 * ADR 0266 slice 3 proof: the proof-only Operation kind `executor.proof` (docs/reference/executor.md#the-proof-operation). The
 * publish is faked and Valkey is an in-memory list, so these run without Reverb or a server.
 */

uses(LazilyRefreshDatabase::class);

beforeEach(function (): void {
    Event::fake([ExecutorOrderBroadcast::class]);
    $this->mock(WebSocketCredentialManager::class)->shouldReceive('current')->andReturn(new WebSocketCredentials('test-app', 'test-key', 'test-secret'));
    config(['orbit.executor.signing_key' => base64_encode(sodium_crypto_sign_secretkey(sodium_crypto_sign_keypair()))]);
    $this->node = Node::withoutEvents(fn (): Node => Node::query()->create([
        'name' => 'app-dev-1', 'status' => 'active', 'platform' => 'linux', 'wireguard_ip' => '10.44.0.8',
        'public_ssh_host' => '10.44.0.8', 'user' => 'orbit', 'ssh_host_fingerprint' => 'SHA256:test',
    ]));
    // Valkey as lists: push appends, pop takes the head of the first key that has one, as BLPOP does.
    $this->queue = new class implements ExecutorResultQueue
    {
        /** @var array<string, list<string>> */
        public array $lists = [];

        public function push(string $key, string $line, int $expirySeconds): void
        {
            $this->lists[$key][] = $line;
        }

        public bool $fails = false;

        public function pop(array $keys, float $timeoutSeconds): ?array
        {
            if ($this->fails) {
                throw new RuntimeException('Valkey is down.');
            }

            foreach ($keys as $key) {
                if (($this->lists[$key] ?? []) !== []) {
                    return [$key, array_shift($this->lists[$key])];
                }
            }

            return null;
        }
    };
    $clock = 0.0;
    $this->app->instance(ExecutorResultWait::class, new ExecutorResultWait($this->queue, static function () use (&$clock): float {
        return $clock += 0.1;
    }));
    $this->listener = new ExecutorResults($this->queue, new NullLogger, app(CommandActivityInputSanitizer::class));
    $this->proof = app(ExecutorProofOperation::class);
});

/** @param array<string, mixed> $line */
function proofReply(Operation $operation, array $line): ExecutorReply
{
    $reply = ExecutorReply::fromEvent(['operation' => (string) $operation->id, 'line' => json_encode($line)]);
    expect($reply)->toBeInstanceOf(ExecutorReply::class);

    return $reply;
}

/** @return array<string, mixed> */
function proofDone(Operation $operation, bool $repeat, int $clock): array
{
    return ['operation' => (string) $operation->id, 'action' => 'proof.noop', 'target' => (string) $operation->node_id,
        'status' => 'done', 'repeat' => $repeat, 'node_clock_ms' => $clock];
}

it('records a running executor.proof Operation on its Node that the Gateway closes, with no Swamp run', function (): void {
    $operation = $this->proof->run($this->node, fn (Closure $start): Operation => $start());

    expect($operation->refresh())
        ->kind->toBe(OperationKinds::ExecutorProof)
        ->node_id->toBe($this->node->id)
        ->target_type->toBe('node')
        ->target_id->toBe($this->node->id)
        ->status->toBe(OperationStatus::Running)
        ->input->toBe(['action' => 'proof.noop'])
        ->triggered_by->toBe('executor-proof')
        ->and($operation->state)->not->toHaveKey('swamp_start')
        ->and(OperationKinds::executor(OperationKinds::ExecutorProof))->toBe('gateway');
});

it('sends each order of the Operation as executor-order on its Node, with a new nonce each time', function (): void {
    $operation = $this->proof->run($this->node, fn (Closure $start): Operation => $start());
    $signer = ExecutorOrderSigner::fromConfig();

    $first = $this->proof->send($operation, $signer);
    $second = $this->proof->send($operation, $signer);

    $payload = json_decode(base64_decode(explode('.', $first->wire)[0]), true);
    expect($first->operation)->toBe((string) $operation->id)
        ->and($second->operation)->toBe((string) $operation->id)
        ->and($second->nonce)->not->toBe($first->nonce)
        ->and($payload)->toMatchArray(['action' => 'proof.noop', 'node' => (string) $this->node->id, 'target' => (string) $this->node->id]);
    Event::assertDispatchedTimes(ExecutorOrderBroadcast::class, 2);
    Event::assertDispatched(ExecutorOrderBroadcast::class, fn (ExecutorOrderBroadcast $event): bool => $event->broadcastOn()[0]->name === "presence-node.{$this->node->id}"
        && $event->broadcastWith() === ['operation' => (string) $operation->id, 'order' => $first->wire]);
});

it('hands the result the listener records to the waiting request and closes the Operation', function (): void {
    $operation = $this->proof->run($this->node, fn (Closure $start): Operation => $start());
    $done = proofDone($operation, repeat: false, clock: 1);

    $this->listener->receive($this->node->id, proofReply($operation, ['status' => 'accepted']));
    $this->listener->receive($this->node->id, proofReply($operation, $done));
    $result = $this->proof->wait($operation, 1.0);
    $this->proof->finish($operation, $result);

    expect($result->status)->toBe(ExecutorWaitStatus::Done)
        ->and($result->line)->toBe($done)
        ->and($operation->refresh())
        ->executor_result->toBe($done)
        ->status->toBe(OperationStatus::Succeeded)
        ->result->toBe($done);
});

it('recovers a lost hand-off by a repeat: the record keeps the first result and the waiter gets the repeat', function (): void {
    $operation = $this->proof->run($this->node, fn (Closure $start): Operation => $start());
    $first = proofDone($operation, repeat: false, clock: 1);
    $repeat = proofDone($operation, repeat: true, clock: 2);

    $this->listener->receive($this->node->id, proofReply($operation, $first));
    $this->queue->lists = [];
    $lost = $this->proof->wait($operation, 0.5);
    $this->listener->receive($this->node->id, proofReply($operation, $repeat));
    $recovered = $this->proof->wait($operation, 1.0);
    $this->proof->finish($operation, $recovered);

    expect($lost->status)->toBe(ExecutorWaitStatus::Timeout)
        ->and($recovered->status)->toBe(ExecutorWaitStatus::Done)
        ->and($recovered->line)->toBe($repeat)
        ->and($operation->refresh())
        ->executor_result->toBe($first)
        ->status->toBe(OperationStatus::Succeeded);
});

it('fails the Operation with its result status and sends no order for a closed one or another kind', function (): void {
    $operation = $this->proof->run($this->node, fn (Closure $start): Operation => $start());
    $this->listener->receive($this->node->id, proofReply($operation, ['status' => 'refused', 'reason' => 'expired', 'node_clock_ms' => 3]));
    $this->proof->finish($operation, $this->proof->wait($operation, 1.0));
    $other = Operation::query()->create(['kind' => 'instance.wake', 'target_type' => 'instance', 'target_id' => 7,
        'node_id' => $this->node->id, 'status' => OperationStatus::Running, 'input' => []]);

    expect($operation->refresh())->status->toBe(OperationStatus::Failed)->error_code->toBe('executor.proof_refused')
        ->and(fn () => $this->proof->send($operation, ExecutorOrderSigner::fromConfig()))->toThrow(InvalidArgumentException::class)
        ->and(fn () => $this->proof->send($other, ExecutorOrderSigner::fromConfig()))->toThrow(InvalidArgumentException::class);
    Event::assertNotDispatched(ExecutorOrderBroadcast::class);
});

it('is not reachable through the public API, the direct Operation routes or any other Gateway code', function (): void {
    $starts = collect(Route::getRoutes()->getRoutes())
        ->filter(fn (RoutingRoute $route): bool => is_string($controller = $route->getControllerClass()) && method_exists($controller, $route->getActionMethod()))
        ->map(fn (RoutingRoute $route): ?StartsOperation => StartsOperation::forRoute($route))->filter()
        ->flatMap(fn (StartsOperation $start): array => [$start->kind, ...array_values($start->kindWhenTrue)]);
    $operation = $this->proof->run($this->node, fn (Closure $start): Operation => $start());
    // Only the registry and the proof class name the kind; the proof support on a nested Gateway is the one caller of that class.
    $naming = collect([...glob(app_path('{,*/,*/*/,*/*/*/,*/*/*/*/}*.php'), GLOB_BRACE), ...glob(base_path('routes/{,*/}*.php'), GLOB_BRACE)])
        ->filter(fn (string $path): bool => preg_match('/executor\.proof\b|ExecutorProof\b|ExecutorProofOperation\b/', (string) file_get_contents($path)) === 1)
        ->map(fn (string $path): string => str_replace(base_path().'/', '', $path))->sort()->values()->all();

    expect($starts)->not->toContain(OperationKinds::ExecutorProof)
        ->and(app(DirectOperation::class)->definition(OperationKinds::ExecutorProof))->toBeNull()
        ->and(fn () => app(DirectOperation::class)->projection($operation))->toThrow(ResourceOperationException::class)
        ->and($naming)->toBe(['app/Domain/Executor/ExecutorProofOperation.php', 'app/Domain/Operations/OperationKinds.php']);
});

it('fails every Operation of a run that throws in a wait, so none stays running', function (): void {
    $this->queue->fails = true;

    expect(fn () => $this->proof->run($this->node, function (Closure $start): void {
        $operation = $start();
        $this->proof->send($operation, ExecutorOrderSigner::fromConfig());
        $this->proof->wait($operation, 1.0);
    }))->toThrow(RuntimeException::class, 'Valkey is down.');

    expect(Operation::query()->where('kind', OperationKinds::ExecutorProof)->get(['status', 'error_code'])->toArray())
        ->toBe([['status' => 'failed', 'error_code' => ExecutorProofOperation::Aborted]]);
});

it('fails every open Operation of a burst whose third publish throws, and keeps the ones that closed', function (): void {
    // Reverb refuses the third `executor-order`, so its publish throws.
    Event::swap(new class(app('events'), [ExecutorOrderBroadcast::class]) extends EventFake
    {
        public int $accepted = 2;

        public function dispatch($event, $payload = [], $halt = false)
        {
            if ($event instanceof ExecutorOrderBroadcast && $this->accepted-- <= 0) {
                throw new RuntimeException('Reverb refused the event.');
            }

            return parent::dispatch($event, $payload, $halt);
        }
    });
    $done = null;

    expect(fn () => $this->proof->run($this->node, function (Closure $start): void {
        $operations = [];
        for ($index = 0; $index < 5; $index++) {
            $operations[] = $operation = $start();
            $this->proof->send($operation, ExecutorOrderSigner::fromConfig());
            if ($index === 0) {
                $this->listener->receive($this->node->id, proofReply($operation, proofDone($operation, repeat: false, clock: 1)));
                $this->proof->finish($operation, $this->proof->wait($operation, 1.0));
            }
        }
    }))->toThrow(RuntimeException::class, 'Reverb refused the event.');

    $statuses = Operation::query()->where('kind', OperationKinds::ExecutorProof)->orderBy('id')->get(['status', 'error_code'])->toArray();
    expect($statuses)->toBe([['status' => 'succeeded', 'error_code' => null], ['status' => 'failed', 'error_code' => ExecutorProofOperation::Aborted],
        ['status' => 'failed', 'error_code' => ExecutorProofOperation::Aborted]])
        ->and(Operation::query()->where('kind', OperationKinds::ExecutorProof)->where('status', OperationStatus::Running)->exists())->toBeFalse();
});
