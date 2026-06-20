<?php

declare(strict_types=1);

namespace Orbit\Core\Progress;

use GuzzleHttp\Psr7\StreamWrapper;
use Psr\Http\Message\StreamInterface;

/**
 * Reads blocking HTTP/SSE streams with timed idle polls so progress tickers can
 * repaint while the caller waits for the next frame.
 */
final readonly class StreamIdleReader
{
    public function __construct(
        private int $idleIntervalMicroseconds = ForkedFrameTicker::DEFAULT_INTERVAL_MICROSECONDS,
    ) {}

    public function read(StreamInterface $stream, int $maxBytes): string
    {
        if (! ForkedFrameTicker::hasIdleCallback()) {
            return $stream->read($maxBytes);
        }

        $resource = $this->resolvePhpStream($stream);

        if ($resource === null) {
            return $this->readWithIdlePolling($stream, $maxBytes);
        }

        while (! $stream->eof()) {
            $read = [$resource];
            $write = null;
            $except = null;
            $seconds = intdiv($this->idleIntervalMicroseconds, 1_000_000);
            $microseconds = $this->idleIntervalMicroseconds % 1_000_000;

            set_error_handler(static fn (): bool => true);

            try {
                $ready = stream_select($read, $write, $except, $seconds, $microseconds);
            } finally {
                restore_error_handler();
            }

            if ($ready === false) {
                ForkedFrameTicker::invokeIdleCallback();
                usleep($this->idleIntervalMicroseconds);

                continue;
            }

            if ($ready > 0) {
                $chunk = $stream->read($maxBytes);

                if ($chunk !== '') {
                    return $chunk;
                }

                continue;
            }

            ForkedFrameTicker::invokeIdleCallback();
        }

        return '';
    }

    private function readWithIdlePolling(StreamInterface $stream, int $maxBytes): string
    {
        while (! $stream->eof()) {
            ForkedFrameTicker::invokeIdleCallback();
            usleep($this->idleIntervalMicroseconds);

            $chunk = $stream->read($maxBytes);

            if ($chunk !== '') {
                return $chunk;
            }
        }

        return '';
    }

    private function resolvePhpStream(StreamInterface $stream): mixed
    {
        $metadata = $stream->getMetadata();

        if (is_array($metadata)) {
            $candidate = $metadata['stream'] ?? null;

            if (is_resource($candidate) && get_resource_type($candidate) === 'stream') {
                return $candidate;
            }
        }

        try {
            $resource = StreamWrapper::getResource($stream);

            if (is_resource($resource) && get_resource_type($resource) === 'stream') {
                return $resource;
            }
        } catch (\Throwable) {
            return null;
        }

        return null;
    }
}
