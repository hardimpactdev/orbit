<?php

declare(strict_types=1);

namespace App\Services\NodeCommandTransport;

use RuntimeException;

final class NodeAgentPushStreamFrameBuffer
{
    private const int FRAME_HEADER_SIZE = 8;

    private string $buffer = '';

    public function append(string $chunk): void
    {
        $this->buffer .= $chunk;
    }

    /**
     * @return list<array{type: int, payload: string}>
     */
    public function drainFrames(): array
    {
        $frames = [];

        while (strlen($this->buffer) >= self::FRAME_HEADER_SIZE) {
            $header = substr($this->buffer, offset: 0, length: self::FRAME_HEADER_SIZE);
            $payloadLength = unpack('N', substr($header, offset: 4, length: 4))[1];

            if (strlen($this->buffer) < (self::FRAME_HEADER_SIZE + $payloadLength)) {
                break;
            }

            $frames[] = [
                'type' => ord($header[0]),
                'payload' => substr($this->buffer, offset: self::FRAME_HEADER_SIZE, length: $payloadLength),
            ];
            $this->buffer = substr($this->buffer, offset: self::FRAME_HEADER_SIZE + $payloadLength);
        }

        return $frames;
    }

    public function assertEmpty(): void
    {
        if ($this->buffer !== '') {
            throw new RuntimeException('Orbit process stream ended with an incomplete frame.');
        }
    }
}
