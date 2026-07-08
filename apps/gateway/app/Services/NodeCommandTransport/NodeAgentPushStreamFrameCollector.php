<?php

declare(strict_types=1);

namespace App\Services\NodeCommandTransport;

use RuntimeException;

final class NodeAgentPushStreamFrameCollector
{
    private const int FRAME_TYPE_STDOUT = 1;

    private const int FRAME_TYPE_STDERR = 2;

    private const int FRAME_TYPE_AGENT_ERROR = 3;

    private const int FRAME_TYPE_EXIT = 4;

    private ?int $exitCode = null;

    /** @var array{code: string, message: string, retryable: bool}|null */
    private ?array $agentError = null;

    private bool $sawExitFrame = false;

    /**
     * @param  array{type: int, payload: string}  $frame
     * @param  callable(string): void  $onOutput
     */
    public function accept(array $frame, callable $onOutput): void
    {
        switch ($frame['type']) {
            case self::FRAME_TYPE_STDOUT:
            case self::FRAME_TYPE_STDERR:
                $onOutput($frame['payload']);

                break;
            case self::FRAME_TYPE_AGENT_ERROR:
                $this->agentError = $this->decodeAgentError($frame['payload']);

                break;
            case self::FRAME_TYPE_EXIT:
                $this->exitCode = $this->decodeExitCode($frame['payload']);
                $this->sawExitFrame = true;

                break;
        }
    }

    public function result(): NodeAgentPushStreamResult
    {
        if (! $this->sawExitFrame) {
            throw new RuntimeException('Orbit process stream ended without an exit frame.');
        }

        return new NodeAgentPushStreamResult(
            exitCode: $this->agentError !== null ? null : $this->exitCode,
            agentError: $this->agentError,
            legacyPassthrough: false,
        );
    }

    /**
     * @return array{code: string, message: string, retryable: bool}
     */
    private function decodeAgentError(string $payload): array
    {
        /** @var array<string, mixed>|null $decoded */
        $decoded = json_decode($payload, associative: true);

        if (! is_array($decoded)) {
            throw new RuntimeException('Orbit process stream agent_error frame must contain JSON.');
        }

        $code = $decoded['code'] ?? null;
        $message = $decoded['message'] ?? null;
        $retryable = $decoded['retryable'] ?? null;

        if (! is_string($code) || ! is_string($message) || ! is_bool($retryable)) {
            throw new RuntimeException('Orbit process stream agent_error frame JSON is invalid.');
        }

        return [
            'code' => $code,
            'message' => $message,
            'retryable' => $retryable,
        ];
    }

    private function decodeExitCode(string $payload): ?int
    {
        /** @var array<string, mixed>|null $decoded */
        $decoded = json_decode($payload, associative: true);

        if (! is_array($decoded)) {
            throw new RuntimeException('Orbit process stream exit frame must contain JSON.');
        }

        $exitCode = $decoded['exit_code'] ?? null;

        if ($exitCode === null) {
            return null;
        }

        if (! is_int($exitCode)) {
            throw new RuntimeException('Orbit process stream exit frame exit_code must be an integer or null.');
        }

        return $exitCode;
    }
}
