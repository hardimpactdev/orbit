<?php

declare(strict_types=1);

namespace App\Services;

use App\Exceptions\GatewayApiException;
use App\Services\Processes\LocalProcessLogsOperationStream;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Orbit\Core\Operations\OperationStreamFrameDraft;
use Orbit\Core\Operations\OperationStreamFrameType;

final readonly class GatewayOperationStreamPublisher
{
    public function __construct(
        private ?string $baseUrl,
        private int $timeout,
        private ?string $caPemPath = null,
    ) {}

    public function publishProcessLogChunk(
        LocalProcessLogsOperationStream $stream,
        int $sequence,
        OperationStreamFrameType $type,
        string $output,
    ): void {
        $frame = OperationStreamFrameDraft::forNode(
            operationUuid: $stream->operationUuid,
            channel: $stream->channel,
            sequence: $sequence,
            emittedAt: Carbon::now()->toIso8601String(),
            type: $type,
            payload: [
                'data' => $output,
                'encoding' => 'utf-8',
            ],
        );

        try {
            $response = $this->pendingRequest($stream)->post($stream->publishEndpoint, [
                'publisher_token' => $stream->publisherToken,
                'frame' => $frame->toArray(),
            ]);
        } catch (ConnectionException $exception) {
            throw GatewayApiException::networkError($exception);
        }

        if ($response->failed()) {
            throw GatewayApiException::httpError($response->status(), $response->body());
        }
    }

    public function shouldStop(LocalProcessLogsOperationStream $stream): bool
    {
        try {
            $response = $this->pendingRequest($stream)->get($stream->stopDecisionEndpoint);
        } catch (ConnectionException $exception) {
            throw GatewayApiException::networkError($exception);
        }

        if ($response->failed()) {
            throw GatewayApiException::httpError($response->status(), $response->body());
        }

        return $response->json('success.data.should_stop_tail') === true;
    }

    private function pendingRequest(LocalProcessLogsOperationStream $stream): PendingRequest
    {
        $request = Http::baseUrl($this->normalizedBaseUrl($stream->gatewayUrl))
            ->acceptJson()
            ->timeout($this->timeout);

        $caPemPath = $stream->caPemPath ?? $this->caPemPath;

        if (is_string($caPemPath) && $caPemPath !== '' && is_file($caPemPath)) {
            $request = $request->withOptions(['verify' => $caPemPath]);
        }

        return $request;
    }

    private function normalizedBaseUrl(?string $streamBaseUrl = null): string
    {
        $baseUrl = is_string($streamBaseUrl) ? trim($streamBaseUrl) : '';

        if ($baseUrl === '') {
            $baseUrl = is_string($this->baseUrl) ? trim($this->baseUrl) : '';
        }

        if ($baseUrl === '') {
            throw new GatewayApiException('Gateway URL is not configured.');
        }

        return rtrim($baseUrl, characters: '/');
    }
}
