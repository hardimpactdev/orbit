<?php

declare(strict_types=1);

use GuzzleHttp\Psr7\Utils;
use Orbit\Core\Progress\ForkedFrameTicker;
use Orbit\Core\Progress\StreamIdleReader;
use Psr\Http\Message\StreamInterface;

function orbitCorePollingOnlyStream(callable $readCallback, callable $eofCallback): StreamInterface
{
    return new class($readCallback, $eofCallback) implements StreamInterface
    {
        private readonly Closure $readCallback;

        private readonly Closure $eofCallback;

        public function __construct(callable $readCallback, callable $eofCallback)
        {
            $this->readCallback = Closure::fromCallable($readCallback);
            $this->eofCallback = Closure::fromCallable($eofCallback);
        }

        public function __toString(): string
        {
            return '';
        }

        public function close(): void {}

        public function detach(): mixed
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
            return ($this->eofCallback)();
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
            return ($this->readCallback)($length);
        }

        public function getContents(): string
        {
            return '';
        }

        public function getMetadata(?string $key = null): mixed
        {
            $metadata = [];

            return $key === null ? $metadata : ($metadata[$key] ?? null);
        }
    };
}

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

it('reads selected native streams without blocking the descriptor during the PSR read', function (): void {
    [$readable, $writable] = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);

    if ($readable === false || $writable === false) {
        $this->markTestSkipped('stream_socket_pair is required to simulate a ready native stream.');
    }

    stream_set_blocking($readable, true);
    stream_set_blocking($writable, true);
    fwrite($writable, "event: ready\n\n");

    $blockedDuringRead = null;
    $inner = Utils::streamFor($readable);
    $stream = new class($inner, $readable, function (bool $blocked) use (&$blockedDuringRead): void {
        $blockedDuringRead = $blocked;
    }) implements StreamInterface
    {
        private readonly Closure $recordBlockingMode;

        public function __construct(
            private readonly StreamInterface $inner,
            private $resource,
            callable $recordBlockingMode,
        ) {
            $this->recordBlockingMode = Closure::fromCallable($recordBlockingMode);
        }

        public function __toString(): string
        {
            return $this->inner->__toString();
        }

        public function close(): void
        {
            $this->inner->close();
        }

        public function detach(): mixed
        {
            return $this->inner->detach();
        }

        public function getSize(): ?int
        {
            return $this->inner->getSize();
        }

        public function tell(): int
        {
            return $this->inner->tell();
        }

        public function eof(): bool
        {
            return $this->inner->eof();
        }

        public function isSeekable(): bool
        {
            return $this->inner->isSeekable();
        }

        public function seek(int $offset, int $whence = SEEK_SET): void
        {
            $this->inner->seek($offset, $whence);
        }

        public function rewind(): void
        {
            $this->inner->rewind();
        }

        public function isWritable(): bool
        {
            return $this->inner->isWritable();
        }

        public function write(string $string): int
        {
            return $this->inner->write($string);
        }

        public function isReadable(): bool
        {
            return $this->inner->isReadable();
        }

        public function read(int $length): string
        {
            $metadata = stream_get_meta_data($this->resource);
            ($this->recordBlockingMode)(($metadata['blocked'] ?? false) === true);

            return $this->inner->read($length);
        }

        public function getContents(): string
        {
            return $this->inner->getContents();
        }

        public function getMetadata(?string $key = null): mixed
        {
            if ($key === 'stream') {
                return $this->resource;
            }

            $metadata = (array) $this->inner->getMetadata();
            $metadata['stream'] = $this->resource;

            return $key === null ? $metadata : ($metadata[$key] ?? null);
        }
    };

    $ticker = new ForkedFrameTicker(50_000);
    $ticker->start(static function (): void {});

    $reader = new StreamIdleReader(50_000);

    try {
        $chunk = $reader->read($stream, 64);

        expect($chunk)->toContain('event: ready')
            ->and($blockedDuringRead)->toBeFalse()
            ->and(stream_get_meta_data($readable)['blocked'])->toBeTrue();
    } finally {
        $ticker->stop();

        if (is_resource($readable)) {
            fclose($readable);
        }

        if (is_resource($writable)) {
            fclose($writable);
        }
    }
});

it('polls PSR streams when guzzle stream wrappers cannot be selected', function (): void {
    $readAttempts = 0;
    $closed = false;
    $stream = orbitCorePollingOnlyStream(
        function () use (&$readAttempts, &$closed): string {
            $readAttempts++;

            if ($readAttempts < 3) {
                return '';
            }

            $closed = true;

            return "event: complete\n\n";
        },
        fn (): bool => $closed,
    );

    $tickCount = 0;
    $ticker = new ForkedFrameTicker(20_000);
    $ticker->start(function () use (&$tickCount): void {
        $tickCount++;
    });

    $reader = new StreamIdleReader(20_000);

    try {
        $chunk = $reader->read($stream, 64);

        expect($chunk)->toContain('event: complete')
            ->and($readAttempts)->toBe(3)
            ->and($tickCount)->toBeGreaterThanOrEqual(2);
    } finally {
        $ticker->stop();
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
