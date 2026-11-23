<?php

declare(strict_types=1);

namespace Orbit\Extensions\Conn\Domain\Push;

/** The outcome of one APNs send attempt. */
final readonly class ApnsSendResult
{
    /** @var list<string> */
    public const array InvalidTokenReasons = ['BadDeviceToken', 'DeviceTokenNotForTopic'];

    public function __construct(
        public int $status,
        public ?string $apnsId = null,
        public ?string $reason = null,
    ) {}

    public function shouldDeleteDevice(): bool
    {
        if ($this->status === 410 && $this->reason === 'Unregistered') {
            return true;
        }

        return $this->status === 400 && in_array($this->reason, self::InvalidTokenReasons, true);
    }

    public function isRetryable(): bool
    {
        return in_array($this->status, [0, 429, 500, 503], true);
    }
}
