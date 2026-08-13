<?php

declare(strict_types=1);

namespace Orbit\Sdk\Laravel\Tests\Unit;

use Orbit\Sdk\Laravel\GatewayConnector;
use Orbit\Sdk\Laravel\GatewayStreamTransport;
use Orbit\Sdk\Laravel\Requests\GenericGatewayStreamRequest;
use Orbit\Sdk\Laravel\Testing\GatewayMockClient;
use Orbit\Sdk\Laravel\Testing\GatewayMockResponse;
use Orbit\Sdk\Laravel\Tests\TestCase;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Psr\Http\Message\StreamInterface;

uses(TestCase::class);

afterEach(function (): void {
    GatewayMockClient::destroyGlobal();
});

it('invokes idle callbacks while waiting for the next stream frame', function (): void {
    $stream = new IdleThenFramesStream(
        "event: complete\n".'data: {"exit_code":0}'."\n\n",
    );
    $idleCount = 0;

    GatewayMockClient::global([
        GenericGatewayStreamRequest::class => new SdkGatewayStreamMockResponse($stream),
    ]);

    $connector = new GatewayConnector(baseUrl: 'https://gateway.test', caPemPath: null);
    $exitCode = new GatewayStreamTransport($connector)->events(
        request: new GenericGatewayStreamRequest('/api/stream', [], 'post'),
        onEvent: fn () => null,
        unavailableMessage: 'Gateway stream unavailable.',
        requireTerminalFrame: true,
        onIdle: function () use (&$idleCount): void {
            $idleCount++;
        },
        idleIntervalMicroseconds: 1,
    );

    expect($exitCode)->toBe(0)->and($idleCount)->toBeGreaterThan(0);
});

it('returns a failure exit code for every failed terminal outcome', function (string $frame, int $expected): void {
    GatewayMockClient::global([
        GenericGatewayStreamRequest::class => new SdkGatewayStreamMockResponse(new IdleThenFramesStream($frame)),
    ]);

    $connector = new GatewayConnector(baseUrl: 'https://gateway.test', caPemPath: null);
    $exitCode = new GatewayStreamTransport($connector)->events(
        request: new GenericGatewayStreamRequest('/api/stream', [], 'post'),
        onEvent: fn () => null,
        unavailableMessage: 'Gateway stream unavailable.',
        requireTerminalFrame: true,
        idleIntervalMicroseconds: 1,
    );

    expect($exitCode)->toBe($expected);
})->with([
    'complete with nonzero exit code' => ["event: complete\ndata: {\"exit_code\":17}\n\n", 17],
    'complete with invalid exit code' => ["event: complete\ndata: {\"exit_code\":\"failed\"}\n\n", 1],
    'error with zero exit code' => ["event: error\ndata: {\"exit_code\":0}\n\n", 1],
    'error without exit code' => ["event: error\ndata: {}\n\n", 1],
]);

final class SdkGatewayStreamMockResponse extends GatewayMockResponse
{
    public function __construct(
        private readonly StreamInterface $stream,
    ) {
        parent::__construct('', 200, ['Content-Type' => 'text/event-stream']);
    }

    public function createPsrResponse(
        ResponseFactoryInterface $responseFactory,
        StreamFactoryInterface $streamFactory,
    ): ResponseInterface {
        return $responseFactory
            ->createResponse(200)
            ->withHeader('Content-Type', 'text/event-stream')
            ->withBody($this->stream);
    }
}

final class IdleThenFramesStream implements StreamInterface
{
    private int $offset = 0;

    private bool $idled = false;

    public function __construct(
        private readonly string $contents,
    ) {}

    public function __toString(): string
    {
        return substr($this->contents, $this->offset);
    }

    public function close(): void {}

    public function detach()
    {
        return null;
    }

    public function getSize(): int
    {
        return strlen($this->contents);
    }

    public function tell(): int
    {
        return $this->offset;
    }

    public function eof(): bool
    {
        return $this->offset >= strlen($this->contents);
    }

    public function isSeekable(): bool
    {
        return false;
    }

    public function seek(int $offset, int $whence = SEEK_SET): void
    {
        throw new \RuntimeException('Stream is not seekable.');
    }

    public function rewind(): void
    {
        $this->offset = 0;
        $this->idled = false;
    }

    public function isWritable(): bool
    {
        return false;
    }

    public function write(string $string): int
    {
        throw new \RuntimeException('Stream is not writable.');
    }

    public function isReadable(): bool
    {
        return true;
    }

    public function read(int $length): string
    {
        if (! $this->idled) {
            $this->idled = true;

            return '';
        }

        if ($this->eof()) {
            return '';
        }

        $chunk = substr($this->contents, $this->offset, $length);
        $this->offset += strlen($chunk);

        return $chunk;
    }

    public function getContents(): string
    {
        $contents = substr($this->contents, $this->offset);
        $this->offset = strlen($this->contents);

        return $contents;
    }

    public function getMetadata(?string $key = null)
    {
        return $key === null ? [] : null;
    }
}
