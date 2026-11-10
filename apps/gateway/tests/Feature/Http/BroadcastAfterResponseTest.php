<?php

declare(strict_types=1);

use App\Domain\Broadcasting\RecordEventType;
use App\Domain\Operations\OperationBroadcaster;
use App\Domain\Operations\OperationStatus;
use App\Domain\WebSocket\WebSocketCredentialManager;
use App\Domain\WebSocket\WebSocketCredentials;
use App\Infrastructure\Activity\ActivityShutdownFinalizer;
use App\Infrastructure\AgentView\WebSocketClient;
use App\Infrastructure\AgentView\WebSocketEndpoint;
use App\Infrastructure\Swamp\SwampConnection;
use App\Jobs\Operations\StartAdmittedSwampRun;
use App\Models\Activity;
use App\Models\Node;
use App\Models\Operation;
use Illuminate\Contracts\Broadcasting\Broadcaster;
use Illuminate\Foundation\Http\Events\RequestHandled;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\Broadcast;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Illuminate\Testing\TestResponse;
use Tests\Support\SwampNodeFixture;

uses(LazilyRefreshDatabase::class);

afterEach(fn () => app(SwampConnection::class)->release());

/** Stands in for Reverb: every event it is asked to send lands in `$sent`, in order. */
function reverb_recorder(bool $failing = false): object
{
    $reverb = new class($failing) implements Broadcaster
    {
        /** @var list<array{type: string, id: int|string, channels: list<string>, sequence: int|null}> */
        public array $sent = [];

        public function __construct(private readonly bool $failing) {}

        public function auth($request): never
        {
            throw new LogicException('Not used.');
        }

        public function validAuthenticationResponse($request, $result): never
        {
            throw new LogicException('Not used.');
        }

        public function broadcast(array $channels, $event, array $payload = []): void
        {
            if ($this->failing) {
                throw new RuntimeException('Reverb is unreachable.');
            }

            $this->sent[] = [
                'type' => $event, 'id' => $payload['id'],
                'channels' => array_map(static fn ($channel): string => (string) $channel, $channels),
                'sequence' => $payload['data']['sequence'] ?? null,
            ];
        }
    };

    app()->instance(WebSocketCredentialManager::class, new class implements WebSocketCredentialManager
    {
        public function ensure(Node $node): never
        {
            throw new LogicException('Not used.');
        }

        public function current(): WebSocketCredentials
        {
            return new WebSocketCredentials('app', 'key', 'secret', '10.44.0.5');
        }

        public function purge(Node $node, bool $runtimeStopped = false): void {}
    });
    Broadcast::extend('reverb', static fn (): Broadcaster => $reverb);

    return $reverb;
}

/** Swamp confirms every workflow start at once. */
function swamp_accepting_starts(): void
{
    $socket = new class implements WebSocketClient
    {
        private ?array $frame = null;

        private bool $open = false;

        public function connect(WebSocketEndpoint $endpoint, float $timeoutSeconds): void
        {
            $this->open = true;
        }

        public function send(array $message): void
        {
            $this->frame = $message;
        }

        public function receive(float $timeoutSeconds): array
        {
            $frame = $this->frame;
            $this->frame = null;

            return $frame === null ? [] : [['id' => $frame['id'], 'type' => 'event', 'event' => [
                'workflowName' => $frame['payload']['workflowIdOrName'], 'runId' => '986bc349-54dc-4dea-9f22-76e3353d1ca0', 'kind' => 'started',
            ]]];
        }

        public function close(): void
        {
            $this->open = false;
        }

        public function isConnected(): bool
        {
            return $this->open;
        }
    };
    app()->instance(WebSocketClient::class, $socket);
    app(SwampConnection::class)->release();
    config(['swamp.address' => 'swamp.test', 'swamp.ca_path' => '/tmp/ca.pem', 'swamp.run_token' => 'run-token']);
}

function broadcast_node(string $name, string $ip): Node
{
    return Node::withoutEvents(fn (): Node => Node::query()->create([
        'name' => $name, 'status' => 'active', 'platform' => 'linux', 'public_ssh_host' => $ip,
        'wireguard_ip' => $ip, 'user' => 'orbit', 'ssh_host_key_type' => 'ssh-ed25519', 'ssh_host_key' => 'test-host-key',
    ]));
}

/** @return array{0: TestResponse, 1: int} The response of a firewall:allow, and how many events had gone out when the kernel finished handling it. */
function allow_port_counting_sent(object $reverb): array
{
    SwampNodeFixture::ensure();
    $caller = broadcast_node('operator', '10.44.0.9');
    $target = broadcast_node('app-dev', '10.44.0.2');
    $caller->accessibleNodes()->attach($target->id);
    $sentBeforeResponse = null;
    Event::listen(RequestHandled::class, function () use ($reverb, &$sentBeforeResponse): void {
        $sentBeforeResponse = count($reverb->sent);
    });

    $response = test()->withServerVariables(['REMOTE_ADDR' => $caller->wireguard_ip])
        ->postJson("/api/v1/nodes/{$target->id}/firewall-rules/allow", ['port' => '8080', 'protocol' => 'tcp']);

    return [$response, $sentBeforeResponse ?? -1];
}

describe('record events of a request', function (): void {
    it('go out after the response of a command admission, not inside it', function (): void {
        Queue::fake([StartAdmittedSwampRun::class]);
        $reverb = reverb_recorder();
        swamp_accepting_starts();

        [$response, $sentBeforeResponse] = allow_port_counting_sent($reverb);

        $response->assertStatus(202);
        Queue::assertPushed(StartAdmittedSwampRun::class, 1);
        app('request')->setRouteResolver(static fn () => null);
        app()->call([Queue::pushed(StartAdmittedSwampRun::class)->sole(), 'handle']);

        expect($sentBeforeResponse)->toBe(0)
            ->and(array_column($reverb->sent, 'type'))->toContain('activity.created', 'operation.created', 'operation.updated', 'activity.updated');
    });

    it('keep the order of one record, so the sequence of an operation never goes down', function (): void {
        Queue::fake([StartAdmittedSwampRun::class]);
        $reverb = reverb_recorder();
        swamp_accepting_starts();

        [$response, $sentBeforeResponse] = allow_port_counting_sent($reverb);
        $response->assertStatus(202);
        expect($sentBeforeResponse)->toBe(0);
        Queue::assertPushed(StartAdmittedSwampRun::class, 1);
        app('request')->setRouteResolver(static fn () => null);
        app()->call([Queue::pushed(StartAdmittedSwampRun::class)->sole(), 'handle']);

        $types = array_column($reverb->sent, 'type');
        $operationId = Operation::query()->value('id');
        $sequences = array_column(array_filter($reverb->sent, static fn (array $event): bool => $event['type'] === 'operation.updated'
            && $event['channels'] === ['private-operation.'.$operationId]), 'sequence');
        expect(array_search('activity.created', $types, true))->toBeLessThan(array_search('operation.created', $types, true))
            ->and(array_search('activity.updated', $types, true))->toBeGreaterThan(array_search('operation.created', $types, true))
            ->and($sequences)->not->toBeEmpty()
            ->and($sequences)->toBe(collect($sequences)->sort()->values()->all());
    });

    it('are not lost when a terminating callback changes a record after the response', function (): void {
        $reverb = reverb_recorder();
        swamp_accepting_starts();
        $late = Operation::query()->create(['kind' => 'firewall.allow', 'target_type' => 'node_firewall', 'target_id' => 1, 'node_id' => null,
            'status' => OperationStatus::Queued, 'state' => [], 'input' => [], 'sequence' => 1]);
        app()->terminating(fn () => app(OperationBroadcaster::class)->created($late));

        allow_port_counting_sent($reverb);

        $last = $reverb->sent[array_key_last($reverb->sent)];
        expect([$last['type'], $last['id']])->toBe(['operation.created', $late->id]);
    });

    it('never fail the request when Reverb is unreachable', function (): void {
        $reverb = reverb_recorder(failing: true);
        swamp_accepting_starts();

        [$response] = allow_port_counting_sent($reverb);

        $response->assertStatus(202);
        expect(Operation::query()->count())->toBe(1);
    });
});

it('goes out at once from work that no HTTP request carries, such as a queue job', function (): void {
    $reverb = reverb_recorder();
    $node = broadcast_node('app-dev', '10.44.0.2');
    $operation = Operation::query()->create(['kind' => 'firewall.allow', 'target_type' => 'node_firewall', 'target_id' => $node->id, 'node_id' => $node->id,
        'status' => OperationStatus::Queued, 'state' => [], 'input' => [], 'sequence' => 1]);

    app(OperationBroadcaster::class)->created($operation);

    expect(array_column($reverb->sent, 'type'))->toBe([RecordEventType::OperationCreated->value]);
});

it('sends the notice of an Activity that a fatal error ended at once, because the application never terminates then', function (): void {
    $reverb = reverb_recorder();
    app('request')->setRouteResolver(static fn (): Route => new Route('POST', 'x', []));
    $activity = Activity::query()->create(['log_name' => 'commands', 'description' => 'firewall:allow', 'event' => 'command', 'properties' => [],
        'request_id' => 'req-1', 'command' => 'firewall:allow', 'status' => 'running']);

    ActivityShutdownFinalizer::arm($activity)->finalize();

    expect(array_column($reverb->sent, 'type'))->toBe(['activity.updated']);
});
