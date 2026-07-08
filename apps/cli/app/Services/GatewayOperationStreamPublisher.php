<?php

declare(strict_types=1);

namespace App\Services;

use App\Exceptions\GatewayApiException;
use App\Services\Processes\LocalProcessLogsOperationStream;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;

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
        string $type,
        string $output,
    ): void {
        try {
            $response = $this->pendingRequest()->post($stream->publishEndpoint, [
                'publisher_token' => $stream->publisherToken,
                'frame' => [
                    'operation_uuid' => $stream->operationUuid,
                    'channel' => $stream->channel,
                    'sequence' => $sequence,
                    'emitted_at' => Carbon::now()->toIso8601String(),
                    'type' => $type,
                    'payload' => [
                        'data' => $output,
                        'encoding' => 'utf-8',
                    ],
                ],
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
            $response = $this->pendingRequest()->get($stream->stopDecisionEndpoint);
        } catch (ConnectionException $exception) {
            throw GatewayApiException::networkError($exception);
        }

        if ($response->failed()) {
            throw GatewayApiException::httpError($response->status(), $response->body());
        }

        return $response->json('success.data.should_stop_tail') === true;
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
        $baseUrl = is_string($this->baseUrl) ? trim($this->baseUrl) : '';

        if ($baseUrl === '') {
            throw new GatewayApiException('Gateway URL is not configured.');
        }

        return rtrim($baseUrl, characters: '/');
    }
}
