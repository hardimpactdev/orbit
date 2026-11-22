<?php

declare(strict_types=1);

namespace Orbit\Extensions\Conn\Domain\Push;

/** One resolved APNs push ready to send to a single device. Never log apnsToken or threadTitle. */
final readonly class ApnsMessage
{
    public function __construct(
        public int $deviceRowId,
        public string $deviceId,
        public string $apnsToken,
        public string $apnsEnvironment,
        public string $bundleId,
        public string $eventId,
        public string $type,
        public ?string $kind,
        public string $environmentId,
        public string $threadId,
        public ?string $threadTitle,
        public string $pushType,
        public int $priority,
        public string $collapseId,
    ) {}
}
