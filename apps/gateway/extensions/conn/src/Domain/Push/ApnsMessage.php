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
        public bool $isSummary = false,
    ) {}

    public function asSummary(): self
    {
        return new self(
            deviceRowId: $this->deviceRowId,
            deviceId: $this->deviceId,
            apnsToken: $this->apnsToken,
            apnsEnvironment: $this->apnsEnvironment,
            bundleId: $this->bundleId,
            eventId: $this->eventId,
            type: $this->type,
            kind: $this->kind,
            environmentId: $this->environmentId,
            threadId: $this->threadId,
            threadTitle: null,
            pushType: 'alert',
            priority: 5,
            collapseId: hash('sha256', 'summary:'.$this->deviceId),
            isSummary: true,
        );
    }

    /** @return array<string, mixed> */
    public function __debugInfo(): array
    {
        return [
            'deviceRowId' => $this->deviceRowId,
            'deviceId' => $this->deviceId,
            'apnsToken' => '[REDACTED]',
            'apnsEnvironment' => $this->apnsEnvironment,
            'bundleId' => $this->bundleId,
            'eventId' => $this->eventId,
            'type' => $this->type,
            'kind' => $this->kind,
            'environmentId' => $this->environmentId,
            'threadId' => $this->threadId,
            'threadTitle' => $this->threadTitle === null ? null : '[REDACTED]',
            'pushType' => $this->pushType,
            'priority' => $this->priority,
            'collapseId' => $this->collapseId,
            'isSummary' => $this->isSummary,
        ];
    }
}
