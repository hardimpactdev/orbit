<?php

declare(strict_types=1);

namespace App\Services;

use App\Exceptions\GatewayApiException;
use App\Exceptions\GatewayApiFailureKind;
use App\Services\Operations\OperationStreamWebSocketConnection;
use App\Services\Operations\OperationStreamWebSocketTransport;
use App\Services\Operations\RawOperationStreamWebSocketTransport;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use InvalidArgumentException;
use Orbit\Core\Operations\DurableOperationStreamFrame;
use Orbit\Core\Operations\OperationStreamFrameEvents;
use Orbit\Core\Progress\ProgressEventType;
use RuntimeException;

/**
 * @mago-expect lint:cyclomatic-complexity
 * @mago-expect lint:kan-defect
 * @mago-expect lint:too-many-methods
 */
class GatewayOperationStreamSubscriber
{
    public function __construct(
        private readonly ?string $baseUrl,
        private readonly int $timeout,
        private readonly GatewayOperationEventStreamClient $events,
        private readonly ?OperationStreamWebSocketTransport $transport = null,
        private readonly ?string $caPemPath = null,
    ) {}

    /**
     * @param  callable(array<string, mixed>): void  $onFrame
     */
    public function subscribe(string $operationRunId, ?int $lastReplayCursor, callable $onFrame): void
    {
        $descriptor = $this->descriptor($operationRunId);
        $channel = $this->stringValue($descriptor, 'channel.name');
        $socketId = null;
        $transport = $this->transport ?? new RawOperationStreamWebSocketTransport;
        $lastSequence = $lastReplayCursor;
        $seenSequences = [];

        try {
            $transport->connect($this->connection($descriptor));
            $socketId = $this->socketId($transport->receive());
            $auth = $this->authorize($descriptor, $socketId, $channel);

            $transport->send([
                'event' => 'pusher:subscribe',
                'data' => [
                    'auth' => $auth,
                    'channel' => $channel,
                ],
            ]);

            $this->waitForSubscription($transport, $channel);
            $lastSequence = $this->replayBackfill($descriptor, $lastSequence, $onFrame, $seenSequences, force: true);
            $this->receiveFrames(
                $operationRunId,
                $descriptor,
                $transport,
                $socketId,
                $channel,
                $onFrame,
                $seenSequences,
                $lastSequence,
            );
            $lastSequence = $this->replayBackfill($descriptor, $lastSequence, $onFrame, $seenSequences, force: true);
        } catch (GatewayApiException $exception) {
            if (! $this->shouldReplayFallback($exception)) {
                throw $exception;
            }

            $this->replayBackfill($descriptor, $lastSequence, $onFrame, $seenSequences, force: true);
        } finally {
            if (is_string($socketId) && $socketId !== '') {
                $this->leave($descriptor, $socketId);
            }

            $transport->close();
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function descriptor(string $operationRunId): array
    {
        $response = $this->pendingRequest()->get("/api/operations/{$operationRunId}/stream");

        if ($response->failed()) {
            throw GatewayApiException::httpError($response->status(), $response->body());
        }

        $data = $response->json('success.data');

        if (! is_array($data)) {
            throw GatewayApiException::streamMalformed(new RuntimeException('Operation stream descriptor is missing.'));
        }

        /** @var array<string, mixed> $data */
        return $data;
    }

    /**
     * @param  array<string, mixed>  $descriptor
     * @param  callable(array<string, mixed>): void  $onFrame
     * @param  array<int, true>  $seenSequences
     */
    private function replayBackfill(
        array $descriptor,
        ?int $lastSequence,
        callable $onFrame,
        array &$seenSequences,
        bool $force = false,
    ): ?int {
        $eventsEndpoint = $this->stringValue($descriptor, 'backfill.events_endpoint');
        $cursor = $this->integerValue($descriptor, 'backfill.cursor');

        if (! $force && $cursor === null && $lastSequence === null) {
            return $lastSequence;
        }

        $this->events->replay(
            $eventsEndpoint,
            $lastSequence,
            function (ProgressEventType $type, array $payload, ?int $eventSequence) use (
                $onFrame,
                &$seenSequences,
                &$lastSequence,
            ): void {
                $frame = $payload['frame'] ?? null;

                if (($payload['event'] ?? null) !== OperationStreamFrameEvents::Journal || ! is_array($frame)) {
                    return;
                }

                /** @var array<string, mixed> $frame */
                $this->dispatchFrame($frame, $onFrame, $seenSequences, $lastSequence, $eventSequence);
            },
        );

        return $lastSequence;
    }

    /**
     * @param  array<string, mixed>  $descriptor
     */
    private function connection(array $descriptor): OperationStreamWebSocketConnection
    {
        $endpoint = $this->gatewayWebSocketEndpoint($descriptor);

        return new OperationStreamWebSocketConnection(
            scheme: $this->websocketScheme($endpoint['scheme']),
            host: $endpoint['host'],
            port: $endpoint['port'],
            appKey: $this->stringValue($descriptor, 'reverb.app_key'),
            timeout: $this->timeout,
            caPemPath: $this->caPemPath,
        );
    }

    /**
     * @param  array<string, mixed>  $descriptor
     * @return array{host: string, port: int, scheme: string}
     */
    private function gatewayWebSocketEndpoint(array $descriptor): array
    {
        $baseUrl = is_string($this->baseUrl) ? trim($this->baseUrl) : '';
        $parts = $baseUrl !== '' ? parse_url($baseUrl) : false;
        $host = is_array($parts) && is_string($parts['host'] ?? null) ? $parts['host'] : null;
        $scheme = is_array($parts) && is_string($parts['scheme'] ?? null) ? strtolower($parts['scheme']) : null;
        $port = is_array($parts) && is_numeric($parts['port'] ?? null)
            ? (int) $parts['port']
            : null;

        if ($host !== null && $scheme !== null) {
            return [
                'host' => $host,
                'port' => $port ?? $this->defaultPort($scheme),
                'scheme' => $scheme,
            ];
        }

        return [
            'host' => $this->stringValue($descriptor, 'reverb.host'),
            'port' => $this->integerValue($descriptor, 'reverb.port') ?? 443,
            'scheme' => $this->stringValue($descriptor, 'reverb.scheme'),
        ];
    }

    private function defaultPort(string $scheme): int
    {
        return match ($scheme) {
            'http', 'ws' => 80,
            'https', 'wss' => 443,
            default => 443,
        };
    }

    /**
     * @param  array<string, mixed>|null  $message
     */
    private function socketId(?array $message): string
    {
        if (! is_array($message) || ($message['event'] ?? null) !== 'pusher:connection_established') {
            throw GatewayApiException::streamMalformed(
                new RuntimeException('Operations websocket did not establish a Pusher connection.'),
            );
        }

        $data = $this->messageData($message);
        $socketId = $data['socket_id'] ?? null;

        if (! is_string($socketId) || $socketId === '') {
            throw GatewayApiException::streamMalformed(
                new RuntimeException('Operations websocket connection omitted socket_id.'),
            );
        }

        return $socketId;
    }

    /**
     * @param  array<string, mixed>  $descriptor
     */
    private function authorize(array $descriptor, string $socketId, string $channel): string
    {
        $response = $this->pendingRequest()->post($this->stringValue($descriptor, 'auth.endpoint'), [
            'socket_id' => $socketId,
            'channel_name' => $channel,
            'auth_token' => $this->stringValue($descriptor, 'auth.token'),
        ]);

        if ($response->failed()) {
            throw GatewayApiException::httpError($response->status(), $response->body());
        }

        $auth = $response->json('success.data.auth');

        if (! is_string($auth) || $auth === '') {
            throw GatewayApiException::streamMalformed(
                new RuntimeException('Operation stream auth response omitted subscription auth.'),
            );
        }

        return $auth;
    }

    private function waitForSubscription(OperationStreamWebSocketTransport $transport, string $channel): void
    {
        while (($message = $transport->receive()) !== null) {
            if (($message['event'] ?? null) === 'pusher:ping') {
                $transport->send(['event' => 'pusher:pong', 'data' => new \stdClass]);

                continue;
            }

            if ($this->isProtocolFailure($message)) {
                throw GatewayApiException::streamMalformed(
                    new RuntimeException('Operations websocket subscription failed.'),
                );
            }

            if (($message['event'] ?? null) !== 'pusher_internal:subscription_succeeded') {
                continue;
            }

            if (($message['channel'] ?? null) === $channel) {
                return;
            }
        }

        throw GatewayApiException::streamClosedBeforeTerminal(
            new RuntimeException('Operation stream subscription was not confirmed.'),
        );
    }

    /**
     * @param  callable(array<string, mixed>): void  $onFrame
     * @param  array<string, mixed>  $descriptor
     * @param  array<int, true>  $seenSequences
     *
     * @mago-expect lint:excessive-parameter-list
     */
    private function receiveFrames(
        string $operationRunId,
        array $descriptor,
        OperationStreamWebSocketTransport $transport,
        string $socketId,
        string $channel,
        callable $onFrame,
        array &$seenSequences,
        ?int &$lastSequence,
    ): void {
        while (($message = $transport->receive()) !== null) {
            if (($message['event'] ?? null) === 'pusher:ping') {
                $descriptor = $this->renewLease($operationRunId, $descriptor, $socketId, $channel);
                $transport->send(['event' => 'pusher:pong', 'data' => new \stdClass]);

                continue;
            }

            if ($this->isProtocolFailure($message)) {
                throw GatewayApiException::streamMalformed(new RuntimeException('Operations websocket stream failed.'));
            }

            if (($message['event'] ?? null) !== OperationStreamFrameEvents::Live) {
                continue;
            }

            $this->dispatchFrame($this->messageData($message), $onFrame, $seenSequences, $lastSequence);
        }
    }

    /**
     * @param  array<string, mixed>  $descriptor
     * @return array<string, mixed>
     */
    private function renewLease(string $operationRunId, array $descriptor, string $socketId, string $channel): array
    {
        try {
            $this->authorize($descriptor, $socketId, $channel);

            return $descriptor;
        } catch (GatewayApiException $exception) {
            if (
                $exception->failureKind() !== GatewayApiFailureKind::Http
                || $exception->gatewayErrorCode() !== 'operation_stream.auth_expired'
            ) {
                throw $exception;
            }
        }

        $descriptor = $this->descriptor($operationRunId);
        $this->authorize($descriptor, $socketId, $channel);

        return $descriptor;
    }

    /**
     * @param  array<string, mixed>  $message
     */
    private function isProtocolFailure(array $message): bool
    {
        return in_array(
            $message['event'] ?? null,
            [
                'pusher:error',
                'pusher_internal:subscription_error',
            ],
            strict: true,
        );
    }

    private function shouldReplayFallback(GatewayApiException $exception): bool
    {
        if (
            $exception->failureKind() === GatewayApiFailureKind::StreamMalformed
            && $exception->getPrevious() instanceof InvalidArgumentException
        ) {
            return false;
        }

        return in_array(
            $exception->failureKind(),
            [
                GatewayApiFailureKind::Network,
                GatewayApiFailureKind::StreamClosedBeforeTerminal,
                GatewayApiFailureKind::StreamMalformed,
                GatewayApiFailureKind::WireguardUnreachable,
            ],
            strict: true,
        );
    }

    /**
     * @param  array<string, mixed>  $frame
     * @param  callable(array<string, mixed>): void  $onFrame
     * @param  array<int, true>  $seenSequences
     */
    private function dispatchFrame(
        array $frame,
        callable $onFrame,
        array &$seenSequences,
        ?int &$lastSequence,
        ?int $journalSequence = null,
    ): void {
        try {
            $durableFrame = DurableOperationStreamFrame::fromArray($frame);
        } catch (InvalidArgumentException $exception) {
            if ($journalSequence !== null && $this->isLegacyCursorlessJournalFrame($frame)) {
                $lastSequence = max($lastSequence ?? 0, $journalSequence);
                $onFrame($frame);

                return;
            }

            throw GatewayApiException::streamMalformed($exception);
        }

        $eventSequence = $durableFrame->cursor()->resumeSequence();

        if (array_key_exists($eventSequence, $seenSequences)) {
            $lastSequence = max($lastSequence ?? 0, $eventSequence);

            return;
        }

        $seenSequences[$eventSequence] = true;
        $lastSequence = max($lastSequence ?? 0, $eventSequence);

        $onFrame($durableFrame->toArray());
    }

    /**
     * @param  array<string, mixed>  $frame
     */
    private function isLegacyCursorlessJournalFrame(array $frame): bool
    {
        $cursor = $frame['durable_replay_cursor'] ?? null;

        if ($cursor === null) {
            return true;
        }

        if (! is_array($cursor)) {
            return false;
        }

        return (
            ($cursor['operation_uuid'] ?? null) === ($frame['operation_uuid'] ?? null)
            && array_key_exists('event_sequence', $cursor)
            && $cursor['event_sequence'] === null
            && array_key_exists('event_id', $cursor)
            && $cursor['event_id'] === null
        );
    }

    /**
     * @param  array<string, mixed>  $descriptor
     */
    private function leave(array $descriptor, string $socketId): void
    {
        $this->pendingRequest()->post($this->leaveEndpoint($descriptor), [
            'socket_id' => $socketId,
        ]);
    }

    /**
     * @param  array<string, mixed>  $descriptor
     */
    private function leaveEndpoint(array $descriptor): string
    {
        $endpoint = $this->stringValue($descriptor, 'auth.endpoint');

        return preg_replace(pattern: '#/auth$#', replacement: '/leave', subject: $endpoint) ?? $endpoint;
    }

    private function pendingRequest(): PendingRequest
    {
        $request = Http::baseUrl($this->normalizedBaseUrl())
            ->acceptJson()
            ->timeout($this->timeout);

        if (is_string($this->caPemPath) && $this->caPemPath !== '' && is_file($this->caPemPath)) {
            $request = $request->withOptions(['verify' => $this->caPemPath]);
        }

        return $request;
    }

    private function normalizedBaseUrl(): string
    {
        if (is_string($this->baseUrl) && trim($this->baseUrl) !== '') {
            return rtrim($this->baseUrl, characters: '/');
        }

        throw GatewayApiException::networkError(new ConnectionException('Gateway URL is not configured.'));
    }

    private function websocketScheme(string $scheme): string
    {
        return match ($scheme) {
            'https', 'wss' => 'wss',
            'http', 'ws' => 'ws',
            default => throw GatewayApiException::streamMalformed(
                new RuntimeException("Unsupported operation stream scheme [{$scheme}]."),
            ),
        };
    }

    /**
     * @param  array<string, mixed>  $message
     * @return array<string, mixed>
     */
    private function messageData(array $message): array
    {
        $data = $message['data'] ?? [];

        if (is_string($data)) {
            $data = json_decode($data, associative: true);
        }

        if (! is_array($data)) {
            throw GatewayApiException::streamMalformed(
                new RuntimeException('Operation stream frame payload is malformed.'),
            );
        }

        /** @var array<string, mixed> $data */
        return $data;
    }

    /**
     * @param  array<string, mixed>  $source
     */
    private function stringValue(array $source, string $path): string
    {
        $value = data_get($source, $path);

        if (! is_string($value) || $value === '') {
            throw GatewayApiException::streamMalformed(
                new RuntimeException("Operation stream descriptor is missing [{$path}]."),
            );
        }

        return $value;
    }

    /**
     * @param  array<string, mixed>  $source
     */
    private function integerValue(array $source, string $path): ?int
    {
        $value = data_get($source, $path);

        if ($value === null) {
            return null;
        }

        if (! is_numeric($value)) {
            throw GatewayApiException::streamMalformed(
                new RuntimeException("Operation stream descriptor [{$path}] must be numeric."),
            );
        }

        return (int) $value;
    }
}
