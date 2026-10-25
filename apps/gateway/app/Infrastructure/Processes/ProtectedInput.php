<?php

declare(strict_types=1);

namespace App\Infrastructure\Processes;

use Generator;
use LogicException;
use RuntimeException;
use SensitiveParameter;
use Throwable;

final class ProtectedInput
{
    /** @var resource|null */
    private mixed $stream;

    /** @var resource|null */
    private mixed $hold = null;

    private bool $gitCredential = false;

    private ?self $body = null;

    private string $prefix = '';

    /** @param resource|null $stream */
    private function __construct(mixed $stream)
    {
        $this->stream = $stream;
    }

    public function __destruct()
    {
        $this->close();
    }

    public static function fromString(#[SensitiveParameter] string $contents): self
    {
        $stream = tmpfile();

        if ($stream === false) {
            throw new RuntimeException('Unable to create protected process input.');
        }

        $input = new self($stream);

        try {
            $metadata = stream_get_meta_data($stream);
            $path = $metadata['uri'] ?? null;

            if (! is_string($path) || $path === '') {
                throw new RuntimeException('Unable to protect process input.');
            }

            if (! chmod(filename: $path, permissions: 0o600)) {
                throw new RuntimeException('Unable to protect process input.');
            }

            $length = strlen($contents);
            $offset = 0;

            while ($offset < $length) {
                $written = fwrite($stream, substr($contents, $offset));

                if ($written === false || $written === 0) {
                    throw new RuntimeException('Unable to write protected process input.');
                }

                $offset += $written;
            }
        } catch (Throwable $exception) {
            $input->close();

            throw $exception;
        }

        return $input;
    }

    /**
     * A script that carries a GitHub credential, such as a `GitReadScript`. Orbit never sends one to a task VM
     * ([Git on a task VM](/reference/compute-drivers#git-on-a-task-vm)).
     */
    public static function gitCredential(#[SensitiveParameter] string $script): self
    {
        $input = self::fromString($script);
        $input->gitCredential = true;

        return $input;
    }

    public function carriesGitCredential(): bool
    {
        return $this->gitCredential;
    }

    /** Streams a caller-owned private file as it is, without a copy. */
    public static function openFile(string $path): self
    {
        $stream = fopen($path, 'rb');
        if ($stream === false) {
            throw new RuntimeException('Unable to read process input.');
        }

        return new self($stream);
    }

    /** Prefix an open private file without copying it. Closing this input leaves the caller's file open for replay. */
    public static function prefixed(self $body, #[SensitiveParameter] string $prefix, bool $credential = false): self
    {
        $input = new self(null);
        $input->body = $body;
        $input->prefix = $prefix;
        $input->gitCredential = $credential;

        return $input;
    }

    /** @return Generator<int, string|resource> */
    public function chunks(): Generator
    {
        if ($this->body !== null) {
            $stream = $this->body->stream();
            yield $this->prefix;
            yield $stream;
        } else {
            yield $this->stream();
        }
    }

    public static function holdOpen(): self
    {
        $pair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, 0);

        if ($pair === false) {
            throw new RuntimeException('Unable to create protected process input.');
        }

        $input = new self($pair[0]);
        $input->hold = $pair[1];

        return $input;
    }

    /** @return resource */
    public function stream(): mixed
    {
        if (! is_resource($this->stream)) {
            throw new LogicException('Protected process input is closed.');
        }

        $metadata = stream_get_meta_data($this->stream);

        if ($metadata['seekable'] && ! rewind($this->stream)) {
            throw new RuntimeException('Unable to read protected process input.');
        }

        return $this->stream;
    }

    public function close(): void
    {
        $this->body = null;
        $this->prefix = '';
        if (is_resource($this->hold)) {
            fclose($this->hold);
            $this->hold = null;
        }

        if (! is_resource($this->stream)) {
            return;
        }

        fclose($this->stream);
        $this->stream = null;
    }

    /** @return array{input: string} */
    public function __debugInfo(): array
    {
        return ['input' => '[PROTECTED]'];
    }
}
