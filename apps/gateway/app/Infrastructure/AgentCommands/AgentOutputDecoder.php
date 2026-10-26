<?php

declare(strict_types=1);

namespace App\Infrastructure\AgentCommands;

use App\Infrastructure\Processes\CommandResult;
use App\Infrastructure\Processes\ProcessOutput;
use App\Infrastructure\Processes\ProcessOutputStream;
use JsonException;

/** Decodes arbitrary transport fragments without keeping a pack in memory or diagnostic output. */
final class AgentOutputDecoder
{
    public const int ChunkBytes = 65_536;

    private string $pending = '';

    private string $stderr = '';

    private int $remaining = 0;

    private int $bytes = 0;

    private ?string $result = null;

    public function __construct(private readonly string $command, private readonly AgentBinaryOutput $output, private readonly int $limit) {}

    public function consume(ProcessOutput $output): void
    {
        if ($output->stream === ProcessOutputStream::Stderr) {
            $this->stderr = substr($this->stderr.$output->value, -8192);

            return;
        }
        $this->pending .= $output->value;
        while ($this->pending !== '') {
            if ($this->result !== null) {
                if (trim($this->pending) !== '') {
                    $this->malformed('The agent sent output after its final result.');
                }
                $this->pending = '';

                return;
            }
            if ($this->remaining > 0) {
                $count = min($this->remaining, strlen($this->pending));
                if (! $this->output->write(substr($this->pending, 0, $count))) {
                    throw new AgentRunnerFailure($this->command, AgentRunnerFailure::OutputWrite, 'The binary output could not be written to its private sink.');
                }
                $this->bytes += $count;
                $this->remaining -= $count;
                $this->pending = substr($this->pending, $count);

                continue;
            }
            $newline = strpos($this->pending, "\n");
            if (($newline === false && strlen($this->pending) > AgentTransport::ResultBytes) || $newline > AgentTransport::ResultBytes) {
                $this->malformed('The agent output header exceeds its limit.');
            }
            if ($newline === false) {
                return;
            }
            $line = substr($this->pending, 0, $newline);
            $this->pending = substr($this->pending, $newline + 1);
            try {
                $value = json_decode($line, true, flags: JSON_THROW_ON_ERROR);
            } catch (JsonException) {
                $this->malformed('The agent sent an invalid binary output header.');
            }
            if (is_array($value) && array_key_exists('stream_bytes', $value)) {
                $count = $value['stream_bytes'];
                if (count($value) !== 1 || ! is_int($count) || $count < 1 || $count > self::ChunkBytes) {
                    $this->malformed('The agent sent an invalid binary chunk length.');
                }
                if ($count > min($this->limit, $this->output->maxBytes) - $this->bytes) {
                    throw new AgentRunnerFailure($this->command, AgentRunnerFailure::OutputLimit, 'The binary output exceeds its limit.');
                }
                $this->remaining = $count;
            } else {
                if (! is_array($value) || ! is_bool($value['ok'] ?? null)) {
                    $this->malformed('The agent sent an invalid final result.');
                }
                $error = $value['error'] ?? null;
                if ($this->bytes > 0 && is_array($error) && ($error['code'] ?? null) === NativeAgentCommandRunner::VersionMismatch) {
                    $this->malformed('An agent mismatch cannot follow binary output.');
                }
                $this->result = $line;
            }
        }
    }

    public function finish(CommandResult $transport): CommandResult
    {
        if ($this->pending !== '' || $this->remaining > 0) {
            $this->malformed('The agent binary output ended inside a chunk or result.');
        }
        if ($transport->exitCode !== 0 && $this->result !== null) {
            throw new AgentRunnerFailure($this->command, AgentRunnerFailure::Transport, 'The binary output transport failed.', ['exit_code' => $transport->exitCode]);
        }

        return new CommandResult($transport->exitCode, $this->result ?? '', $this->stderr, $transport->durationMs, false);
    }

    private function malformed(string $message): never
    {
        throw new AgentRunnerFailure($this->command, AgentRunnerFailure::MalformedResult, $message);
    }
}
