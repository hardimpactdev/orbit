<?php

declare(strict_types=1);

namespace Orbit\Core\Progress;

use GuzzleHttp\Psr7\StreamWrapper;
use Psr\Http\Message\StreamInterface;
use ReflectionObject;

/**
 * Reads blocking HTTP/SSE streams with timed idle polls so progress tickers can
 * repaint while the caller waits for the next frame.
 */
final readonly class StreamIdleReader
{
    private const int SELECTED_RESOURCE_READ_BYTES = 1;

    public function __construct(
        private int $idleIntervalMicroseconds = ForkedFrameTicker::DEFAULT_INTERVAL_MICROSECONDS,
        private mixed $onIdle = null,
    ) {}

    public function read(StreamInterface $stream, int $maxBytes): string
    {
        if (! ForkedFrameTicker::hasIdleCallback() && ! is_callable($this->onIdle)) {
            return $stream->read($maxBytes);
        }

        $resource = $this->resolvePhpStream($stream);

        if ($resource === null) {
            return $this->readWithIdlePolling($stream, $maxBytes);
        }

        if (! is_resource($resource)) {
            return '';
        }

        while (! $stream->eof()) {
            if (! $this->isSelectableStreamResource($resource)) {
                return $this->readWithIdlePolling($stream, $maxBytes);
            }

            $read = [$resource];
            $write = null;
            $except = null;
            $seconds = intdiv($this->idleIntervalMicroseconds, 1_000_000);
            $microseconds = $this->idleIntervalMicroseconds % 1_000_000;

            set_error_handler(static fn (): bool => true);

            try {
                $ready = stream_select($read, $write, $except, $seconds, $microseconds);
            } catch (\ValueError|\TypeError) {
                return $this->readWithIdlePolling($stream, $maxBytes);
            } finally {
                restore_error_handler();
            }

            if ($ready === false) {
                $this->invokeIdleCallback();
                usleep(max(0, $this->idleIntervalMicroseconds));

                continue;
            }

            if ($ready > 0) {
                $chunk = $this->readSelectedChunk($resource, $maxBytes);

                if ($chunk !== '') {
                    return $chunk;
                }

                $this->invokeIdleCallback();
                usleep(max(0, $this->idleIntervalMicroseconds));

                continue;
            }

            $this->invokeIdleCallback();
        }

        return '';
    }

    private function readSelectedChunk(mixed $resource, int $maxBytes): string
    {
        if ($maxBytes <= 0 || ! $this->isSelectableStreamResource($resource)) {
            return '';
        }

        if (! is_resource($resource)) {
            return '';
        }

        $restoreBlocking = $this->isBlockingStream($resource);

        stream_set_blocking($resource, false);

        try {
            // TLS streams can block trying to fill a large fread() length after
            // readiness. A one-byte resource read returns only available data.
            $chunk = fread($resource, min($maxBytes, self::SELECTED_RESOURCE_READ_BYTES));

            return $chunk === false ? '' : $chunk;
        } finally {
            if ($restoreBlocking) {
                stream_set_blocking($resource, true);
            }
        }
    }

    private function isBlockingStream(mixed $resource): bool
    {
        if (! is_resource($resource)) {
            return false;
        }

        $metadata = stream_get_meta_data($resource) + ['blocked' => false];

        return $metadata['blocked'] === true;
    }

    private function readWithIdlePolling(StreamInterface $stream, int $maxBytes): string
    {
        while (! $stream->eof()) {
            $chunk = $stream->read($maxBytes);

            if ($chunk !== '') {
                return $chunk;
            }

            $this->invokeIdleCallback();
            usleep(max(0, $this->idleIntervalMicroseconds));
        }

        return '';
    }

    private function invokeIdleCallback(): void
    {
        if (is_callable($this->onIdle)) {
            ($this->onIdle)();

            return;
        }

        ForkedFrameTicker::invokeIdleCallback();
    }

    private function isSelectableStreamResource(mixed $resource): bool
    {
        if (! is_resource($resource) || get_resource_type($resource) !== 'stream') {
            return false;
        }

        $metadata = stream_get_meta_data($resource);
        $metadata += [
            'wrapper_type' => null,
            'stream_type' => null,
        ];

        return $metadata['wrapper_type'] !== 'user-space' && $metadata['stream_type'] !== 'user-space';
    }

    private function resolvePhpStream(StreamInterface $stream): mixed
    {
        $metadata = $stream->getMetadata();

        if (is_array($metadata)) {
            if (! array_key_exists('stream', $metadata)) {
                $candidate = null;
            } else {
                $candidate = $metadata['stream'];
            }

            if ($this->isSelectableStreamResource($candidate)) {
                return $candidate;
            }

            if (array_key_exists('stream', $metadata)) {
                return $candidate;
            }
        }

        $resource = $this->resourceFromKnownPsrStream($stream);

        if ($this->isSelectableStreamResource($resource)) {
            return $resource;
        }

        try {
            $resource = StreamWrapper::getResource($stream);

            if ($this->isSelectableStreamResource($resource)) {
                return $resource;
            }
        } catch (\Throwable) {
            return null;
        }

        return null;
    }

    private function resourceFromKnownPsrStream(StreamInterface $stream): mixed
    {
        try {
            $reflection = new ReflectionObject($stream);

            do {
                if (! $reflection->hasProperty('stream')) {
                    $reflection = $reflection->getParentClass() ?: null;

                    continue;
                }

                $property = $reflection->getProperty('stream');
                $resource = $property->getValue($stream);

                if (is_resource($resource)) {
                    return $resource;
                }

                $reflection = $reflection->getParentClass() ?: null;
            } while ($reflection !== null);
        } catch (\Throwable) {
            return null;
        }

        return null;
    }
}
