<?php

declare(strict_types=1);

namespace App\Infrastructure\AgentCommands;

use App\Infrastructure\Processes\CommandDeadline;
use App\Models\Node;
use RuntimeException;

/** Sends a pinned Pi artifact through its named receiver, with one bounded transfer budget. */
final readonly class PiArtifactDelivery
{
    private const int ChunkBytes = 2097152;

    private const int MaxBytes = 268435456;

    public function __construct(
        private AgentCommandRunner $nodes,
        private GuestAgentCommandRunner $guests,
        private CommandDeadline $deadline,
    ) {}

    public function deliver(Node|AgentGuest $target, string $file, string $sha256): void
    {
        $stream = @fopen($file, 'rb');
        if ($stream === false) {
            throw new RuntimeException('The pinned Pi artifact is missing.');
        }
        try {
            $size = fstat($stream)['size'] ?? 0;
            $hash = hash_init('sha256');
            hash_update_stream($hash, $stream);
            if ($size < 1 || $size > self::MaxBytes || hash_final($hash) !== $sha256) {
                throw new RuntimeException('The Pi artifact has an invalid size or digest.');
            }
            $this->deadline->within(900, function () use ($target, $stream, $size, $sha256): void {
                $offset = 0;
                while ($offset < $size) {
                    if (fseek($stream, $offset) !== 0) {
                        throw new RuntimeException('The Pi artifact could not be read.');
                    }
                    $bytes = fread($stream, self::ChunkBytes);
                    if ($bytes === false || $bytes === '') {
                        throw new RuntimeException('The Pi artifact ended before its recorded size.');
                    }
                    $input = ['sha256' => $sha256, 'offset' => $offset, 'total' => $size, 'bytes' => base64_encode($bytes)];
                    $remaining = $this->deadline->cap(900);
                    $result = $target instanceof Node
                        ? $this->nodes->run($target, 'pi.receive-artifact', $input, $remaining)
                        : $this->guests->runGuest($target, 'pi.receive-artifact', $input, $remaining);
                    $received = $result['received_bytes'] ?? null;
                    if (! is_int($received) || $received < $offset + strlen($bytes) || $received > $size
                        || ($result['verified'] ?? null) !== ($received === $size)) {
                        throw new AgentRunnerFailure('pi.receive-artifact', AgentRunnerFailure::MalformedResult, 'The Pi receiver returned invalid transfer progress.');
                    }
                    $offset = $received;
                }
            });
        } finally {
            fclose($stream);
        }
    }
}
