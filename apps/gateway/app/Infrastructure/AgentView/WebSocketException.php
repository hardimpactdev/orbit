<?php

declare(strict_types=1);

namespace App\Infrastructure\AgentView;

use RuntimeException;
use Throwable;

final class WebSocketException extends RuntimeException
{
    /** @param int|null $retryAfterSeconds the refusal's `Retry-After` in seconds, when the server named one */
    public function __construct(string $message = '', int $code = 0, ?Throwable $previous = null, public readonly ?int $retryAfterSeconds = null)
    {
        parent::__construct($message, $code, $previous);
    }
}
