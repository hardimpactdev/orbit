<?php

declare(strict_types=1);

use App\Domain\Broadcasting\RealtimeConnection;
use App\Domain\Broadcasting\RecordBroadcast;
use App\Domain\Executor\ExecutorOrderBroadcast;
use App\Domain\Executor\ExecutorOrderSigner;
use App\Domain\Executor\ExecutorProofOperation;
use App\Domain\Operations\DirectOperation;
use App\Domain\Operations\OperationKinds;
use App\Domain\Operations\OperationStatus;
use App\Domain\Shared\ResourceOperationException;
use App\Domain\WebSocket\WebSocketCredentialManager;
use App\Domain\WebSocket\WebSocketCredentials;
use App\Http\Mcp\ApiDispatcher;
use App\Http\Mcp\ToolManifest;
use App\Http\Requests\Operations\StartsOperation;
use App\Infrastructure\Activity\CommandActivityInputSanitizer;
use App\Infrastructure\Executor\ExecutorReply;
use App\Infrastructure\Executor\ExecutorResultQueue;
use App\Infrastructure\Executor\ExecutorResults;
use App\Infrastructure\Executor\ExecutorResultWait;
use App\Infrastructure\Executor\ExecutorWaitStatus;
use App\Models\Instance;
use App\Models\Node;
use App\Models\Operation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Routing\Route as RoutingRoute;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Testing\Fakes\EventFake;
use Psr\Log\NullLogger;

/*
 * ADR 0266 slice 3 proof: the proof-only Operation kind `executor.proof` (docs/reference/executor.md#the-proof-operation). The
 * publish is faked and Valkey is an in-memory list, so these run without Reverb or a server.
 */

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Event::fake([ExecutorOrderBroadcast::class, RecordBroadcast::class]);
    $this->mock(WebSocketCredentialManager::class)->shouldReceive('current')->andReturn(new WebSocketCredentials('test-app', 'test-key', 'test-secret'));
    $this->app->forgetInstance(RealtimeConnection::class);
    $keyPair = sodium_crypto_sign_keypair();
    $this->publicKey = sodium_crypto_sign_publickey($keyPair);
    config(['orbit.executor.signing_key' => base64_encode(sodium_crypto_sign_secretkey($keyPair)), 'orbit.executor.wait_ms' => 1000]);
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

function onProofPublish(Closure $reply): void
{
    Event::swap(new class(app('events'), $reply) extends EventFake
    {
        public function __construct($dispatcher, private Closure $reply)
        {
            parent::__construct($dispatcher, [ExecutorOrderBroadcast::class, RecordBroadcast::class]);
        }

        public function dispatch($event, $payload = [], $halt = false)
        {
            if ($event instanceof ExecutorOrderBroadcast) {
                ($this->reply)($event);
            }

            return parent::dispatch($event, $payload, $halt);
        }
    });
}

describe('the Node executor diagnostic', function (): void {
    beforeEach(function (): void {
        $this->gateway = Node::withoutEvents(fn (): Node => Node::query()->create([
            'name' => 'gateway', 'status' => 'active', 'platform' => 'linux', 'wireguard_ip' => '10.44.0.1',
            'public_ssh_host' => '10.44.0.1', 'user' => 'orbit', 'ssh_host_fingerprint' => 'SHA256:test',
        ]));
        $this->gateway->roles()->create(['role' => 'gateway', 'status' => 'active']);
        $this->operator = Node::withoutEvents(fn (): Node => Node::query()->create([
            'name' => 'operator', 'status' => 'active', 'platform' => 'linux', 'wireguard_ip' => '10.44.0.2',
            'public_ssh_host' => '10.44.0.2', 'user' => 'orbit', 'ssh_host_fingerprint' => 'SHA256:test',
        ]));
        $this->path = "/api/v1/nodes/{$this->node->id}/executor/proof";
    });

    it('sends exactly one signed no-op order and returns its durable verdict', function (string $sequence, string $status, ?string $error): void {
        $this->operator->accessibleNodes()->attach($this->gateway);
        $reply = function (ExecutorOrderBroadcast $event) use ($sequence): void {
            ['operation' => $id, 'order' => $wire] = $event->broadcastWith();
            $operation = Operation::query()->findOrFail($id);
            [$payload, $signature] = array_map(base64_decode(...), explode('.', $wire));

            expect(sodium_crypto_sign_verify_detached($signature, $payload, $this->publicKey))->toBeTrue()
                ->and($event->broadcastOn()[0]->name)->toBe("presence-node.{$this->node->id}")
                ->and(json_decode($payload, true))->toMatchArray([
                    'action' => 'proof.noop', 'target' => (string) $this->node->id, 'node' => (string) $this->node->id, 'operation' => $id,
                ]);

            $accepted = proofReply($operation, ['status' => 'accepted']);
            if ($sequence === 'cancelled first') {
                $this->node->accessibleNodes()->attach($this->node);
                $this->call('POST', "/api/v1/operations/{$operation->id}/cancel", [], [], [], [
                    'REMOTE_ADDR' => $this->node->wireguard_ip, 'HTTP_ACCEPT' => 'application/json',
                ])->assertOk()->assertJsonPath('data.status', 'cancelled');
            }
            if (in_array($sequence, ['accepted first', 'cancelled first'], true)) {
                $this->listener->receive($this->node->id, $accepted);
            } elseif ($sequence === 'wrong Node') {
                $this->listener->receive($this->operator->id, $accepted);
            }
            $done = proofDone($operation, repeat: false, clock: 1);
            if ($sequence === 'wrong target') {
                $done['target'] = (string) $this->operator->id;
                $this->listener->receive($this->node->id, $accepted);
            }
            $this->listener->receive($this->node->id, proofReply($operation, $done));
            if ($sequence === 'done first') {
                $this->listener->receive($this->node->id, $accepted);
            }
        };
        onProofPublish($reply);

        $response = $this->withServerVariables(['REMOTE_ADDR' => $this->operator->wireguard_ip])
            ->post($this->path, headers: ['Accept' => 'application/json'])
            ->assertOk()
            ->assertJsonPath('data.kind', 'executor.proof')
            ->assertJsonPath('data.target_type', 'node')
            ->assertJsonPath('data.target_id', $this->node->id)
            ->assertJsonPath('data.status', $status)
            ->assertJsonPath('data.error_code', $error);

        Event::assertDispatchedTimes(ExecutorOrderBroadcast::class, 1);
        $operation = Operation::query()->sole();
        expect($response->json('data.id'))->toBe($operation->id)
            ->and($response->json('meta.request_id'))->toBe($operation->request_id)
            ->and($operation->node_id)->toBe($this->node->id)
            ->and($operation->status->value)->toBe($status)
            ->and($operation->executor_result)->toMatchArray(['status' => 'done', 'operation' => (string) $operation->id])
            ->and($operation->result)->toBe($status === 'succeeded' ? $operation->executor_result : null);
    })->with([
        'accepted and done already queued' => ['accepted first', 'succeeded', null],
        'done only' => ['done only', 'failed', 'executor.proof_not_accepted'],
        'owning Node cancels before replies arrive' => ['cancelled first', 'cancelled', null],
        'accepted after done' => ['done first', 'failed', 'executor.proof_not_accepted'],
        'foreign accepted then owning Node done' => ['wrong Node', 'failed', 'executor.proof_not_accepted'],
        'done names another target' => ['wrong target', 'failed', 'executor.proof_result_mismatch'],
    ]);

    it('returns the failed Operation when signing, publishing or waiting throws', function (string $stage): void {
        $this->operator->accessibleNodes()->attach($this->gateway);
        match ($stage) {
            'sign' => config(['orbit.executor.signing_key' => '']),
            'publish' => onProofPublish(static fn () => throw new RuntimeException('Reverb failed.')),
            'wait' => $this->queue->fails = true,
        };

        $response = $this->withServerVariables(['REMOTE_ADDR' => $this->operator->wireguard_ip])
            ->post($this->path, headers: ['Accept' => 'application/json'])
            ->assertOk()
            ->assertJsonPath('data.status', 'failed')
            ->assertJsonPath('data.error_code', ExecutorProofOperation::Aborted);

        $operation = Operation::query()->sole();
        expect($response->json('data.id'))->toBe($operation->id)
            ->and($operation->status)->toBe(OperationStatus::Failed)
            ->and($operation->error_code)->toBe(ExecutorProofOperation::Aborted)
            ->and($operation->finished_at)->not->toBeNull();
    })->with(['sign', 'publish', 'wait']);

    it('rejects a caller without both an active peer and Gateway authority before signing', function (string $caller, string $error): void {
        if ($caller === 'inactive operator') {
            $this->operator->accessibleNodes()->attach($this->gateway);
            $this->operator->updateQuietly(['status' => 'failed']);
        } elseif ($caller === 'target grant only') {
            $this->operator->accessibleNodes()->attach($this->node);
        }
        $this->withServerVariables(['REMOTE_ADDR' => $caller === 'unknown' ? '10.44.0.99' : $this->operator->wireguard_ip])
            ->post($this->path, headers: ['Accept' => 'application/json'])
            ->assertForbidden()
            ->assertJsonPath('error.code', $error);

        Event::assertNotDispatched(ExecutorOrderBroadcast::class);
        expect(Operation::query()->count())->toBe(0);
    })->with([
        'unknown peer' => ['unknown', 'peer.identity_unknown'],
        'inactive peer with Gateway authority' => ['inactive operator', 'peer.identity_unknown'],
        'no target grant' => ['no grants', 'node_access.required'],
        'target grant alone' => ['target grant only', 'node_access.required'],
    ]);

    it('rejects a request body or query before starting a diagnostic', function (string $contentType, string $body, string $query, ?int $contentLength = null): void {
        $this->operator->accessibleNodes()->attach($this->gateway);

        $this->call('POST', $this->path.$query, [], [], [], [
            'REMOTE_ADDR' => $this->operator->wireguard_ip,
            'HTTP_ACCEPT' => 'application/json',
            'CONTENT_TYPE' => $contentType,
            'CONTENT_LENGTH' => (string) ($contentLength ?? strlen($body)),
        ], $body)->assertUnprocessable()->assertJsonPath('error.code', 'validation.failed');

        Event::assertNotDispatched(ExecutorOrderBroadcast::class);
        expect(Operation::query()->count())->toBe(0);
    })->with([
        'power action' => ['application/json', '{"action":"sleep","target":"7"}', ''],
        'malformed JSON' => ['application/json', '{"action":', ''],
        'plain text' => ['text/plain', 'action=sleep', ''],
        'empty JSON object' => ['application/json', '{}', ''],
        'multipart consumed by PHP' => ['multipart/form-data; boundary=orbit', '', '', 11],
        'query parameter' => ['application/json', '', '?action=sleep'],
    ]);

    it('runs the bodyless diagnostic through its generated MCP tool', function (): void {
        $this->operator->accessibleNodes()->attach($this->gateway);
        onProofPublish(function (ExecutorOrderBroadcast $event): void {
            $operation = Operation::query()->findOrFail($event->broadcastWith()['operation']);
            $this->listener->receive($this->node->id, proofReply($operation, ['status' => 'accepted']));
            $this->listener->receive($this->node->id, proofReply($operation, proofDone($operation, repeat: false, clock: 1)));
        });
        $tool = collect(ToolManifest::default()->definitions())->firstWhere('name', 'node-executor-proof');
        $caller = Request::create('/mcp', server: ['REMOTE_ADDR' => $this->operator->wireguard_ip]);

        $response = app(ApiDispatcher::class)->dispatch($tool, ['node' => $this->node->id], $caller);

        expect($response->status)->toBe(200)
            ->and(json_decode($response->body, true, flags: JSON_THROW_ON_ERROR)['data']['status'])->toBe('succeeded');
        Event::assertDispatchedTimes(ExecutorOrderBroadcast::class, 1);
        expect(Operation::query()->sole()->node_id)->toBe($this->node->id);
    });
});

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

it('refuses a non-string stored action before signing or publishing an order', function (): void {
    $operation = $this->proof->run($this->node, fn (Closure $start): Operation => $start());
    $operation->update(['input' => ['action' => ['sleep']]]);

    expect(fn () => $this->proof->send($operation, ExecutorOrderSigner::fromConfig()))
        ->toThrow(InvalidArgumentException::class, 'An executor proof Operation needs a string action.');

    Event::assertNotDispatched(ExecutorOrderBroadcast::class);
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

    $this->listener->receive($this->node->id, proofReply($operation, ['status' => 'accepted']));
    $this->listener->receive($this->node->id, proofReply($operation, $first));
    $this->queue->lists = [];
    $lost = $this->proof->wait($operation, 0.5);
    $this->listener->receive($this->node->id, proofReply($operation, ['status' => 'accepted']));
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

it('keeps generic start and direct Operation paths closed for both executor proof kinds', function (): void {
    $starts = collect(Route::getRoutes()->getRoutes())
        ->filter(fn (RoutingRoute $route): bool => is_string($controller = $route->getControllerClass()) && method_exists($controller, $route->getActionMethod()))
        ->map(fn (RoutingRoute $route): ?StartsOperation => StartsOperation::forRoute($route))->filter()
        ->flatMap(fn (StartsOperation $start): array => [$start->kind, ...array_values($start->kindWhenTrue)]);
    $operation = $this->proof->run($this->node, fn (Closure $start): Operation => $start());
    // The diagnostic controller is the sole API caller. The power proof stays inside the nested proof support.
    $naming = collect([...glob(app_path('{,*/,*/*/,*/*/*/,*/*/*/*/}*.php'), GLOB_BRACE), ...glob(base_path('routes/{,*/}*.php'), GLOB_BRACE)])
        ->filter(fn (string $path): bool => preg_match('/executor\.(?:proof|power_proof)\b|Executor(?:Power)?Proof\b|ExecutorProofOperation\b/', (string) file_get_contents($path)) === 1)
        ->map(fn (string $path): string => str_replace(base_path().'/', '', $path))->sort()->values()->all();

    foreach ([OperationKinds::ExecutorProof, OperationKinds::ExecutorPowerProof] as $kind) {
        $operation->kind = $kind;
        expect($starts)->not->toContain($kind)
            ->and(app(DirectOperation::class)->definition($kind))->toBeNull()
            ->and(fn () => app(DirectOperation::class)->projection($operation))->toThrow(ResourceOperationException::class);
    }

    expect($naming)->toBe([
        'app/Domain/Executor/ExecutorProofOperation.php',
        'app/Domain/Operations/OperationKinds.php',
        'app/Http/Controllers/Api/NodeExecutorProofController.php',
    ]);
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
    Event::swap(new class(app('events'), [ExecutorOrderBroadcast::class, RecordBroadcast::class]) extends EventFake
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
                $this->listener->receive($this->node->id, proofReply($operation, ['status' => 'accepted']));
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

it('records sleep, wake and discard as durable power proof Operations on the Instance and sends their signed target', function (string $action): void {
    $instance = (new Instance)->forceFill(['id' => 23, 'node_id' => $this->node->id]);
    $operation = $this->proof->run($this->node, fn (Closure $start): Operation => $start(), $instance, $action);
    $order = $this->proof->send($operation, ExecutorOrderSigner::fromConfig());
    $payload = json_decode(base64_decode(explode('.', $order->wire)[0]), true);

    expect($operation->refresh())->kind->toBe(OperationKinds::ExecutorPowerProof)
        ->target_type->toBe('instance')->target_id->toBe(23)->node_id->toBe($this->node->id)
        ->input->toBe(['action' => $action])->status->toBe(OperationStatus::Running)
        ->and($payload)->toMatchArray(['action' => $action, 'target' => '23', 'operation' => (string) $operation->id]);
    Event::assertDispatched(ExecutorOrderBroadcast::class);

    $done = ['operation' => (string) $operation->id, 'action' => $action, 'target' => '23', 'status' => 'done', 'repeat' => false];
    $this->listener->receive($this->node->id, proofReply($operation, $done));
    $this->proof->finish($operation, $this->proof->wait($operation, 1.0));
    expect($operation->refresh())->status->toBe(OperationStatus::Succeeded)->executor_result->toBe($done);
})->with(['sleep', 'wake', 'discard']);

it('succeeds a wake proof on a partial result: the container runs again before its whole restore', function (): void {
    $instance = (new Instance)->forceFill(['id' => 23, 'node_id' => $this->node->id]);
    $operation = $this->proof->run($this->node, fn (Closure $start): Operation => $start(), $instance, 'wake');
    $partial = ['operation' => (string) $operation->id, 'action' => 'wake', 'target' => '23', 'status' => 'partial',
        'code' => 'instance.restore_deadline', 'repeat' => false];
    $this->listener->receive($this->node->id, proofReply($operation, $partial));
    $this->proof->finish($operation, $this->proof->wait($operation, 1.0));

    expect($operation->refresh())->status->toBe(OperationStatus::Succeeded)->executor_result->toBe($partial)->result->toBe($partial);
});

it('refuses a power proof of another Node or an action outside power before recording an Operation', function (): void {
    $instance = (new Instance)->forceFill(['id' => 23, 'node_id' => $this->node->id + 1]);
    expect(fn () => $this->proof->run($this->node, fn (Closure $start): Operation => $start(), $instance, 'sleep'))
        ->toThrow(InvalidArgumentException::class);
    $instance->node_id = $this->node->id;
    expect(fn () => $this->proof->run($this->node, fn (Closure $start): Operation => $start(), $instance, 'proof.noop'))
        ->toThrow(InvalidArgumentException::class);
    expect(Operation::query()->where('kind', OperationKinds::ExecutorPowerProof)->count())->toBe(0);
});
