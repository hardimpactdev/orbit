<?php

declare(strict_types=1);

use GuzzleHttp\Psr7\Utils;
use Orbit\Core\Progress\ForkedFrameTicker;
use Orbit\Core\Progress\StreamIdleReader;
use Psr\Http\Message\StreamInterface;

it('invokes idle callbacks while waiting for stream data', function (): void {
    [$readable, $writable] = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);

    if ($readable === false || $writable === false) {
        $this->markTestSkipped('stream_socket_pair is required to simulate blocking SSE reads.');
    }

    stream_set_blocking($readable, true);
    stream_set_blocking($writable, true);

    $stream = new class($readable) implements StreamInterface
    {
        public function __construct(private $resource) {}

        public function __toString(): string
        {
            return '';
        }

        public function close(): void {}

        public function detach()
        {
            return null;
        }

        public function getSize(): ?int
        {
            return null;
        }

        public function tell(): int
        {
            return 0;
        }

        public function eof(): bool
        {
            return feof($this->resource);
        }

        public function isSeekable(): bool
        {
            return false;
        }

        public function seek(int $offset, int $whence = SEEK_SET): void {}

        public function rewind(): void {}

        public function isWritable(): bool
        {
            return false;
        }

        public function write(string $string): int
        {
            return 0;
        }

        public function isReadable(): bool
        {
            return true;
        }

        public function read(int $length): string
        {
            $chunk = fread($this->resource, $length);

            return $chunk === false ? '' : $chunk;
        }

        public function getContents(): string
        {
            return '';
        }

        public function getMetadata(?string $key = null)
        {
            $metadata = ['stream' => $this->resource];

            return $key === null ? $metadata : ($metadata[$key] ?? null);
        }
    };

    $tickCount = 0;
    $written = false;
    $ticker = new ForkedFrameTicker(50_000);
    $ticker->start(function () use (&$tickCount, &$written, $writable): void {
        $tickCount++;

        if (! $written && $tickCount >= 2) {
            fwrite($writable, "event: keepalive\n\n");
            $written = true;
        }
    });

    $reader = new StreamIdleReader(50_000);

    try {
        $chunk = $reader->read($stream, 64);

        expect($chunk)->toContain('event: keepalive')
            ->and($tickCount)->toBeGreaterThanOrEqual(2);
    } finally {
        $ticker->stop();
        fclose($readable);
        fclose($writable);
    }
});

it('treats an invalid stream resource as closed without throwing', function (): void {
    [$readable, $writable] = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);

    if ($readable === false || $writable === false) {
        $this->markTestSkipped('stream_socket_pair is required to simulate closed SSE reads.');
    }

    fclose($readable);

    $stream = new class($readable) implements StreamInterface
    {
        public function __construct(private $resource) {}

        public function __toString(): string
        {
            return '';
        }

        public function close(): void {}

        public function detach()
        {
            return null;
        }

        public function getSize(): ?int
        {
            return null;
        }

        public function tell(): int
        {
            return 0;
        }

        public function eof(): bool
        {
            return false;
        }

        public function isSeekable(): bool
        {
            return false;
        }

        public function seek(int $offset, int $whence = SEEK_SET): void {}

        public function rewind(): void {}

        public function isWritable(): bool
        {
            return false;
        }

        public function write(string $string): int
        {
            return 0;
        }

        public function isReadable(): bool
        {
            return true;
        }

        public function read(int $length): string
        {
            return '';
        }

        public function getContents(): string
        {
            return '';
        }

        public function getMetadata(?string $key = null)
        {
            $metadata = ['stream' => $this->resource];

            return $key === null ? $metadata : ($metadata[$key] ?? null);
        }
    };

    $tickCount = 0;
    $ticker = new ForkedFrameTicker(50_000);
    $ticker->start(function () use (&$tickCount): void {
        $tickCount++;
    });

    $reader = new StreamIdleReader(50_000);

    try {
        $chunk = $reader->read($stream, 64);

        expect($chunk)->toBe('');
    } finally {
        $ticker->stop();
        fclose($writable);
    }
});

it('invokes idle callbacks while waiting for guzzle stream data', function (): void {
    [$readable, $writable] = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);

    if ($readable === false || $writable === false) {
        $this->markTestSkipped('stream_socket_pair is required to simulate blocking SSE reads.');
    }

    stream_set_blocking($readable, true);
    stream_set_blocking($writable, true);

    $stream = Utils::streamFor($readable);
    $tickCount = 0;
    $written = false;
    $ticker = new ForkedFrameTicker(50_000);
    $ticker->start(function () use (&$tickCount, &$written, $writable): void {
        $tickCount++;

        if (! $written && $tickCount >= 2) {
            fwrite($writable, "event: keepalive\n\n");
            $written = true;
        }
    });

    $reader = new StreamIdleReader(50_000);

    try {
        $chunk = $reader->read($stream, 64);

        expect($chunk)->toContain('event: keepalive')
            ->and($tickCount)->toBeGreaterThanOrEqual(2);
    } finally {
        $ticker->stop();
        $stream->close();
        fclose($writable);
    }
});
