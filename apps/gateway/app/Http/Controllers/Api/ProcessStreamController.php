<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Contracts\Loggable;
use App\Enums\ActivityLogType;
use App\Enums\Processes\ProcessRuntimeStatus;
use App\Enums\ProcessEventType;
use App\Models\Node;
use App\Models\ProcessEvent;
use App\Services\Processes\ProcessEventStreamer;
use App\Services\Processes\ProcessListPayload;
use App\Services\Processes\ProcessOwnerContextResolver;
use App\Services\Processes\ProcessStreamRuntimeConfig;
use App\Services\Processes\ProcessStreamScope;
use App\Support\Streaming\ProgressEventStreamEmitter;
use App\Support\Streaming\ProgressEventStreamResponseFactory;
use Dedoc\Scramble\Attributes\Response as OpenApiResponse;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Orbit\Sdk\Laravel\GatewayApiException;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

/**
 * Browser/gateway process lifecycle SSE stream.
 *
 * Auth matches process list: WireGuard peer identity + process:read grants.
 * Native EventSource cannot set X-Orbit-Client; that header is never required.
 * Durable process_events (DB cursor) is the cross-worker authority.
 *
 * Every connect (including EventSource reconnect with Last-Event-ID) emits a
 * fresh authoritative snapshot at a transactionally consistent high-water mark,
 * assigns that mark as the snapshot SSE id, then tails only rows after the mark.
 * Last-Event-ID is accepted as native resume input and never causes historical
 * regression after the snapshot.
 *
 * Tail scope is app_instance + workspace|null + node (not a frozen process-id
 * list), so processes added/converged after connect still stream.
 *
 * Public query contract: only {@code app}. Timing knobs are injected config.
 *
 * @mago-expect lint:cyclomatic-complexity
 * @mago-expect lint:too-many-methods
 */
final readonly class ProcessStreamController implements Loggable
{
    public function __construct(
        private ProcessListPayload $payload,
        private ProcessOwnerContextResolver $contexts,
        private ProcessEventStreamer $streamer,
        private ProgressEventStreamResponseFactory $streams,
        private ProcessStreamRuntimeConfig $runtimeConfig,
    ) {}

    #[OpenApiResponse(
        status: 200,
        description: 'Server-sent process lifecycle stream: initial snapshot then ordered durable updates for the app hostname context.',
        type: 'string',
    )]
    public function __invoke(Request $request): JsonResponse|StreamedResponse
    {
        /** @var mixed $caller */
        $caller = $request->user();

        if (! $caller instanceof Node) {
            return $this->fail('authorization_failed', 'Peer identity unknown.', [], 403);
        }

        // Last-Event-ID may arrive on native EventSource reconnect. It is accepted
        // (CORS allows the header) but never used to replay history after the fresh
        // high-water snapshot below.

        $appHostname = $this->stringQuery($request, 'app');

        if ($appHostname === null) {
            return $this->fail(
                'validation_failed',
                'The app hostname is required for the process stream.',
                ['field' => 'app'],
                400,
            );
        }

        $queryBag = $request->query->all();

        foreach (array_keys($queryBag) as $queryKey) {
            if ($queryKey === 'app') {
                continue;
            }

            return $this->fail(
                'validation_failed',
                'The process stream accepts only the app hostname selector.',
                [
                    'field' => is_string($queryKey) ? $queryKey : 'query',
                    'reason' => 'stream_app_only',
                ],
                400,
            );
        }

        try {
            $context = $this->contexts->resolveVisible(
                nodeName: null,
                appName: null,
                workspaceName: null,
                caller: $caller,
                permission: 'process:read',
                allowSingleVisibleAppDefault: false,
                appHostname: $appHostname,
            );
            $scope = ProcessStreamScope::fromOwnerContext($context);
        } catch (GatewayApiException $e) {
            return $this->fail(
                code: $e->errorCode() ?? 'validation_failed',
                message: $e->getMessage(),
                meta: $e->errorMeta(),
                status: $e->errorCode() === 'authorization_failed' ? 403 : 400,
            );
        } catch (InvalidArgumentException $e) {
            return $this->fail(
                'validation_failed',
                $e->getMessage(),
                ['field' => 'app', 'reason' => 'stream_scope_required'],
                400,
            );
        }

        try {
            /**
             * Snapshot process list and high-water mark under one DB transaction
             * so the SSE snapshot id is consistent with the rows it describes and
             * both derive from the same target scope.
             *
             * @var array{0: int, 1: array{context: array<string, mixed>, processes: list<array<string, mixed>>}} $bound
             */
            $bound = DB::transaction(function () use ($scope, $caller, $appHostname): array {
                $highWater = $this->streamer->highWaterMark($scope);
                $snapshot = $this->payload->forContext(
                    nodeName: null,
                    appName: null,
                    workspaceName: null,
                    caller: $caller,
                    appHostname: $appHostname,
                );

                return [$highWater, $snapshot];
            });
        } catch (GatewayApiException $e) {
            return $this->fail(
                code: $e->errorCode() ?? 'validation_failed',
                message: $e->getMessage(),
                meta: $e->errorMeta(),
                status: $e->errorCode() === 'authorization_failed' ? 403 : 400,
            );
        }

        [$highWater, $snapshot] = $bound;
        $runtimeConfig = $this->runtimeConfig;

        return $this->streams->make(function (ProgressEventStreamEmitter $events) use (
            $appHostname,
            $snapshot,
            $highWater,
            $runtimeConfig,
            $scope,
        ): void {
            // Snapshot carries the durable high-water as its SSE id (0 when no events).
            $events->event(
                'snapshot',
                [
                    'app' => $appHostname,
                    'context' => $snapshot['context'],
                    'processes' => $snapshot['processes'],
                    'cursor' => [
                        'high_water_mark' => $highWater,
                    ],
                ],
                $highWater,
            );

            try {
                foreach ($this->streamer->follow(
                    scope: $scope,
                    afterId: $highWater,
                    config: $runtimeConfig,
                ) as $frame) {
                    if ($frame === 'heartbeat') {
                        $events->heartbeat();

                        continue;
                    }

                    $events->event(
                        'update',
                        $this->updatePayload($frame),
                        $frame->id,
                    );
                }
            } catch (Throwable $e) {
                $events->event('error', [
                    'code' => 'process.event_stream_failed',
                    'message' => $e->getMessage(),
                    'meta' => (object) [],
                ]);
            }
        });
    }

    /**
     * @return array<string, mixed>
     */
    private function updatePayload(ProcessEvent $event): array
    {
        $type = $event->event instanceof ProcessEventType
            ? $event->event
            : ProcessEventType::tryFrom((string) $event->event);

        $status = ProcessRuntimeStatus::fromEventType($type);
        $occurredAt = $event->recorded_at?->toIso8601String() ?? $event->created_at?->toIso8601String();
        // Durable process_name is authoritative; relation is legacy/backfill safety only.
        $snapshotName = $event->process_name;
        $relatedName = $event->process?->name;
        if ($snapshotName !== '') {
            $name = $snapshotName;
        } elseif (is_string($relatedName) && $relatedName !== '') {
            $name = $relatedName;
        } else {
            $name = 'unknown';
        }
        $eventType = $type instanceof ProcessEventType
            ? $type->value
            : (string) $event->getRawOriginal('event');

        // Use the related process label only when that row's current identity
        // still equals the durable event key. After a rename, process_id may
        // still resolve to the renamed row (new key + its label); pairing that
        // label with the old durable key would mislabel the update. Fall back
        // to the durable key so snapshot remains authoritative for display.
        $label = $name;
        $relatedProcess = $event->process;
        if (
            $relatedProcess !== null
            && $relatedProcess->name === $name
        ) {
            $relatedLabel = $relatedProcess->label;
            if (is_string($relatedLabel) && trim($relatedLabel) !== '') {
                $label = $relatedLabel;
            }
        }

        return [
            'id' => $event->id,
            'event' => $eventType,
            'status' => $status->value,
            'key' => $name,
            // Deprecated compatibility alias; new consumers use key (+ label).
            'name' => $name,
            'label' => $label,
            'node' => $event->node?->name,
            'app' => $event->app->name ?? $event->app?->name,
            'instance' => $event->instance?->name,
            'workspace' => $event->workspace?->name,
            'unit_name' => $event->unit_name,
            'occurred_at' => $occurredAt,
            'exit_code' => $event->exit_code,
            'exit_status' => $event->exit_status,
        ];
    }

    private function stringQuery(Request $request, string $key): ?string
    {
        $value = $request->query($key);

        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }

    /**
     * @param  array<string, mixed>  $meta
     */
    private function fail(string $code, string $message, array $meta, int $status): JsonResponse
    {
        return response()->json([
            'error' => [
                'code' => $code,
                'message' => $message,
                'meta' => $meta === [] ? (object) [] : $meta,
            ],
        ], $status);
    }

    public function effect(): ActivityLogType
    {
        return ActivityLogType::Read;
    }

    public function activityLogType(): ActivityLogType
    {
        return $this->effect();
    }

    public function type(): string
    {
        return 'api:GET /processes/stream';
    }

    public function activityLogAction(): string
    {
        return $this->type();
    }

    public function subject(): ?Model
    {
        return null;
    }

    public function activityLogSubject(): ?Model
    {
        return $this->subject();
    }

    /**
     * @return array<string, mixed>
     */
    public function properties(): array
    {
        return [];
    }

    /**
     * @return array<string, mixed>
     */
    public function activityLogProperties(): array
    {
        return $this->properties();
    }

    public function description(): ?string
    {
        return null;
    }

    public function activityLogDescription(): ?string
    {
        return $this->description();
    }
}
