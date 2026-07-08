<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Authorization\RequiresPermission;
use App\Http\Authorization\ServingNode;
use App\Models\Node;
use App\Models\OperationEvent;
use App\Models\OperationRun;
use App\Services\Operations\OperationEventRecorder;
use App\Services\Operations\OperationStreamFrameBroadcaster;
use App\Services\Operations\OperationStreamSubscriberLeases;
use App\Services\Operations\OperationStreamTokens;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Config;
use Orbit\Core\Http\JsonEnvelope;
use Throwable;

/**
 * @mago-expect lint:cyclomatic-complexity
 * @mago-expect lint:too-many-methods
 */
#[RequiresPermission('*', servingNode: ServingNode::Gateway)]
final readonly class OperationStreamControlPlaneController
{
    public function __construct(
        private OperationStreamSubscriberLeases $leases,
        private OperationStreamTokens $tokens,
        private OperationEventRecorder $events,
        private OperationStreamFrameBroadcaster $broadcaster,
    ) {}

    public function show(OperationRun $operationRun): JsonResponse
    {
        $channel = $this->channel($operationRun);
        $auth = $this->tokens->authToken($operationRun, $channel);

        return response()->json(JsonEnvelope::success([
            'operation' => [
                'uuid' => $operationRun->id,
                'operation_id' => $operationRun->operation_id,
            ],
            'channel' => [
                'name' => $channel,
                'private' => true,
            ],
            'reverb' => [
                'app_key' => Config::string('orbit.operations.reverb.app_key'),
                'host' => Config::string('orbit.operations.reverb.host'),
                'port' => $this->integerConfig('orbit.operations.reverb.port', 8080),
                'scheme' => Config::string('orbit.operations.reverb.scheme'),
            ],
            'auth' => [
                'endpoint' => "/api/operations/{$operationRun->id}/stream/auth",
                'method' => 'POST',
                'token' => $auth['token'],
                'expires_at' => $auth['expires_at']->toIso8601String(),
            ],
            'backfill' => $this->backfill($operationRun),
        ]));
    }

    public function publisherCredentials(Request $request, OperationRun $operationRun): JsonResponse
    {
        /** @var mixed $caller */
        $caller = $request->user();

        if (! $caller instanceof Node || $operationRun->target_node_id !== $caller->id) {
            return $this->error(
                'authorization_failed',
                'Only the trusted target agent may mint publisher credentials.',
                [],
                403,
            );
        }

        $channel = $this->channel($operationRun);
        $publisher = $this->tokens->publisherToken($operationRun, $channel);

        return response()->json(JsonEnvelope::success([
            'operation' => [
                'uuid' => $operationRun->id,
                'operation_id' => $operationRun->operation_id,
            ],
            'channel' => [
                'name' => $channel,
                'private' => true,
            ],
            'publisher' => [
                'token' => $publisher['token'],
                'expires_at' => $publisher['expires_at']->toIso8601String(),
                'scope' => [
                    'operation_uuid' => $operationRun->id,
                    'channel' => $channel,
                    'target_node_id' => $operationRun->target_node_id,
                ],
            ],
        ]));
    }

    /**
     * @mago-expect lint:halstead
     */
    public function publish(Request $request, OperationRun $operationRun): JsonResponse
    {
        /** @var mixed $caller */
        $caller = $request->user();

        if (! $caller instanceof Node || $operationRun->target_node_id !== $caller->id) {
            return $this->error(
                'authorization_failed',
                'Only the trusted target agent may publish operation stream frames.',
                [],
                403,
            );
        }

        $data = $request->validate([
            'publisher_token' => ['required', 'string'],
            'frame' => ['required', 'array'],
            'frame.operation_uuid' => ['required', 'string'],
            'frame.channel' => ['required', 'string'],
            'frame.sequence' => ['required', 'integer', 'min:1'],
            'frame.emitted_at' => ['required', 'date'],
            'frame.type' => ['required', 'string', 'in:stdout,stderr,status,control,error,terminal'],
            'frame.payload' => ['required', 'array'],
        ]);

        $channel = $this->channel($operationRun);
        $publisherToken = $this->stringInput($data, 'publisher_token');
        $inputFrame = $this->arrayInput($data, 'frame');
        $operationUuid = $this->stringInput($inputFrame, 'operation_uuid');
        $frameChannel = $this->stringInput($inputFrame, 'channel');
        $sequence = $this->integerInput($inputFrame, 'sequence');
        $emittedAt = $this->stringInput($inputFrame, 'emitted_at');
        $type = $this->stringInput($inputFrame, 'type');
        $payload = $this->arrayInput($inputFrame, 'payload');
        $token = $this->tokens->verify($publisherToken, $operationRun, $channel, 'publisher');

        if ($token['expired']) {
            return $this->error(
                'operation_stream.publisher_expired',
                'The operation stream publisher token has expired.',
                [],
                403,
            );
        }

        if (! $token['valid']) {
            return $this->error(
                'operation_stream.publisher_invalid',
                'The operation stream publisher token is invalid.',
                [],
                403,
            );
        }

        if (! hash_equals($operationRun->id, $operationUuid)) {
            return $this->error(
                'operation_stream.operation_denied',
                'The operation stream frame operation does not match the publish target.',
                [],
                403,
            );
        }

        if (! hash_equals($channel, $frameChannel)) {
            return $this->error(
                'operation_stream.channel_denied',
                'The operation stream frame channel does not match the publish target.',
                [],
                403,
            );
        }

        $frame = [
            'operation_uuid' => $operationRun->id,
            'channel' => $channel,
            'sequence' => $sequence,
            'emitted_at' => $emittedAt,
            'source_node' => [
                'id' => $caller->id,
                'name' => $caller->name,
            ],
            'type' => $type,
            'payload' => $payload,
            'durable_replay_cursor' => [
                'operation_uuid' => $operationRun->id,
                'event_sequence' => null,
                'event_id' => null,
            ],
        ];

        $event = $this->events->append($operationRun, 'operation_stream.frame', [
            'frame' => $frame,
        ]);

        $frame['durable_replay_cursor'] = [
            'operation_uuid' => $operationRun->id,
            'event_sequence' => $event->sequence,
            'event_id' => $event->id,
        ];

        $event->forceFill([
            'payload' => [
                'frame' => $frame,
            ],
        ])->save();

        $broadcast = [
            'delivered' => true,
            'error' => null,
        ];

        try {
            $this->broadcaster->broadcast($channel, $frame);
        } catch (Throwable $exception) {
            $broadcast = [
                'delivered' => false,
                'error' => [
                    'code' => 'operation_stream.broadcast_failed',
                    'message' => $exception->getMessage(),
                ],
            ];
        }

        return response()->json(JsonEnvelope::success([
            'operation' => [
                'uuid' => $operationRun->id,
                'operation_id' => $operationRun->operation_id,
            ],
            'frame' => $frame,
            'broadcast' => $broadcast,
        ]));
    }

    public function auth(Request $request, OperationRun $operationRun): JsonResponse
    {
        $data = $request->validate([
            'socket_id' => ['required', 'string'],
            'channel_name' => ['required', 'string'],
            'auth_token' => ['required', 'string'],
        ]);

        $channel = $this->channel($operationRun);
        $socketId = $this->stringInput($data, 'socket_id');
        $channelName = $this->stringInput($data, 'channel_name');
        $authToken = $this->stringInput($data, 'auth_token');

        if (! hash_equals($channel, $channelName)) {
            return $this->error(
                'operation_stream.channel_denied',
                'The requested operation channel is not allowed.',
                [],
                403,
            );
        }

        $token = $this->tokens->verify($authToken, $operationRun, $channel, 'subscriber');

        if ($token['expired']) {
            return $this->error(
                'operation_stream.auth_expired',
                'The operation stream authorization token has expired.',
                [],
                403,
            );
        }

        if (! $token['valid']) {
            return $this->error(
                'operation_stream.auth_invalid',
                'The operation stream authorization token is invalid.',
                [],
                403,
            );
        }

        $lease = $this->leases->join($operationRun, $channel, $socketId);

        return response()->json(JsonEnvelope::success([
            'auth' => $this->subscriptionAuth($socketId, $channel),
            'channel' => $channel,
            'lease' => [
                'subscriber' => $lease['subscriber'],
                'active_subscribers' => $lease['active_subscribers'],
                'expires_at' => $lease['expires_at']->toIso8601String(),
            ],
        ]));
    }

    public function leave(Request $request, OperationRun $operationRun): JsonResponse
    {
        $data = $request->validate([
            'socket_id' => ['required', 'string'],
        ]);

        $channel = $this->channel($operationRun);
        $socketId = $this->stringInput($data, 'socket_id');
        $lease = $this->leases->leave($operationRun, $channel, $socketId);

        return response()->json(JsonEnvelope::success([
            'channel' => $channel,
            'lease' => [
                'subscriber' => $lease['subscriber'],
                'active_subscribers' => $lease['active_subscribers'],
            ],
        ]));
    }

    public function stopDecision(OperationRun $operationRun): JsonResponse
    {
        $channel = $this->channel($operationRun);
        $activeSubscribers = $this->leases->activeCount($operationRun, $channel);

        return response()->json(JsonEnvelope::success([
            'operation' => [
                'uuid' => $operationRun->id,
            ],
            'channel' => [
                'name' => $channel,
            ],
            'active_subscribers' => $activeSubscribers,
            'should_stop_tail' => $activeSubscribers === 0,
        ]));
    }

    private function channel(OperationRun $operationRun): string
    {
        return "private-operations.{$operationRun->id}";
    }

    private function subscriptionAuth(string $socketId, string $channel): string
    {
        $appKey = Config::string('orbit.operations.reverb.app_key');
        $appSecret = Config::string('orbit.operations.reverb.app_secret');
        $signature = hash_hmac('sha256', "{$socketId}:{$channel}", $appSecret);

        return "{$appKey}:{$signature}";
    }

    /**
     * @return array{events_endpoint: string, cursor: int|null, from_sequence: int|null, available: int}
     */
    private function backfill(OperationRun $operationRun): array
    {
        $query = OperationEvent::query()->where('operation_run_id', $operationRun->id);

        return [
            'events_endpoint' => "/api/operations/{$operationRun->id}/events",
            'cursor' => $this->nullableInteger((clone $query)->max('sequence')),
            'from_sequence' => $this->nullableInteger((clone $query)->min('sequence')),
            'available' => (clone $query)->count(),
        ];
    }

    /**
     * @param  array<array-key, mixed>  $data
     */
    private function stringInput(array $data, string $key): string
    {
        return $this->stringFromMixed($data[$key] ?? null);
    }

    /**
     * @param  array<array-key, mixed>  $data
     * @return array<array-key, mixed>
     */
    private function arrayInput(array $data, string $key): array
    {
        return $this->arrayFromMixed($data[$key] ?? null);
    }

    /**
     * @param  array<array-key, mixed>  $data
     */
    private function integerInput(array $data, string $key): int
    {
        return $this->integerFromMixed($data[$key] ?? null);
    }

    private function stringFromMixed(mixed $value): string
    {
        return is_string($value) ? $value : '';
    }

    /**
     * @return array<array-key, mixed>
     */
    private function arrayFromMixed(mixed $value): array
    {
        return is_array($value) ? $value : [];
    }

    private function integerFromMixed(mixed $value): int
    {
        return is_numeric($value) ? (int) $value : 0;
    }

    private function nullableInteger(mixed $value): ?int
    {
        return is_numeric($value) ? (int) $value : null;
    }

    private function integerConfig(string $key, int $default): int
    {
        $value = Config::get($key, $default);

        return is_numeric($value) ? (int) $value : $default;
    }

    /**
     * @param  array<string, mixed>  $meta
     */
    private function error(string $code, string $message, array $meta, int $status): JsonResponse
    {
        return response()->json(JsonEnvelope::failure($code, $message, $meta), $status);
    }
}
