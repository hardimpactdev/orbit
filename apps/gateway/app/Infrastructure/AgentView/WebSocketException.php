<?php

declare(strict_types=1);

namespace App\Infrastructure\AgentView;

use RuntimeException;
use Throwable;

final class WebSocketException extends RuntimeException
{
    /**
     * @param  int|null  $retryAfterSeconds  the refusal's `Retry-After` in seconds, when the server named one
     * @param  bool  $notUp  Reverb did not answer: nothing listens yet, there is no route yet, or the proxy in front of it answers
     *                       502, 503 or 504
     */
    public function __construct(string $message = '', int $code = 0, ?Throwable $previous = null, public readonly ?int $retryAfterSeconds = null,
        public readonly bool $notUp = false)
    {
        parent::__construct($message, $code, $previous);
    }
}
