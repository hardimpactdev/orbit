<?php

declare(strict_types=1);

namespace Orbit\Extensions\Conn\Domain\Push;

/** Sends one resolved push notification to APNs. */
interface ApnsClient
{
    public function send(ApnsMessage $message): ApnsSendResult;
}
