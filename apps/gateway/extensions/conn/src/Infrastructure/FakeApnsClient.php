<?php

declare(strict_types=1);

namespace Orbit\Extensions\Conn\Infrastructure;

use Illuminate\Support\Str;
use Orbit\Extensions\Conn\Domain\Push\ApnsClient;
use Orbit\Extensions\Conn\Domain\Push\ApnsMessage;
use Orbit\Extensions\Conn\Domain\Push\ApnsSendResult;

/** In-memory APNs client that records sent messages without contacting Apple. */
final class FakeApnsClient implements ApnsClient
{
    /** @var list<ApnsMessage> */
    public array $sent = [];

    public function send(ApnsMessage $message): ApnsSendResult
    {
        $this->sent[] = $message;

        return new ApnsSendResult(status: 200, apnsId: (string) Str::uuid());
    }

    public function reset(): void
    {
        $this->sent = [];
    }
}
