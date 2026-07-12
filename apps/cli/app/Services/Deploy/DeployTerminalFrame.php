<?php

declare(strict_types=1);

namespace App\Services\Deploy;

use Orbit\Core\Progress\ProgressEventType;

final class DeployTerminalFrame
{
    /** @var array{type: ProgressEventType, payload: array<string, mixed>}|null */
    private ?array $frame = null;

    /**
     * @param  array<string, mixed>  $payload
     */
    public function capture(ProgressEventType $type, array $payload): void
    {
        $this->frame = [
            'type' => $type,
            'payload' => $payload,
        ];
    }

    /**
     * @return array{type: ProgressEventType, payload: array<string, mixed>}|null
     */
    public function frame(): ?array
    {
        return $this->frame;
    }
}
