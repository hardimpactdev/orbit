<?php

declare(strict_types=1);

namespace App\Enums\Processes;

use App\Enums\ProcessEventType;

enum ProcessRuntimeStatus: string
{
    case Running = 'running';
    case Stopped = 'stopped';
    case Crashed = 'crashed';
    case Unknown = 'unknown';

    public static function fromEventType(?ProcessEventType $type): self
    {
        return match ($type) {
            ProcessEventType::Started => self::Running,
            ProcessEventType::Stopped => self::Stopped,
            ProcessEventType::Crashed => self::Crashed,
            null => self::Unknown,
        };
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(
            static fn (self $status): string => $status->value,
            self::cases(),
        );
    }
}
