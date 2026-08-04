<?php

declare(strict_types=1);

namespace App\Enums\Processes;

use App\Enums\ProcessEventType;

enum ProcessRuntimeStatus: string
{
    case Starting = 'starting';
    case Running = 'running';
    case Stopping = 'stopping';
    case Stopped = 'stopped';
    case Restarting = 'restarting';
    case Crashed = 'crashed';
    case Unknown = 'unknown';

    public static function fromEventType(?ProcessEventType $type): self
    {
        return match ($type) {
            ProcessEventType::Starting => self::Starting,
            ProcessEventType::Started => self::Running,
            ProcessEventType::Stopping => self::Stopping,
            ProcessEventType::Stopped => self::Stopped,
            ProcessEventType::Restarting => self::Restarting,
            ProcessEventType::Crashed => self::Crashed,
            ProcessEventType::Failed, null => self::Unknown,
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

    public function isTransitional(): bool
    {
        return match ($this) {
            self::Starting, self::Stopping, self::Restarting => true,
            default => false,
        };
    }
}
