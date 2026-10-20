<?php

declare(strict_types=1);

namespace App\Domain\Shared;

use App\Http\Responses\ApiErrorResponse;
use App\Infrastructure\Caddy\Build\NodeCaddyBuildException;
use App\Infrastructure\Logging\GatewayExceptionStatus;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;
use Throwable;

class ResourceOperationException extends RuntimeException
{
    /**
     * @param  array<string, mixed>  $details
     */
    public function __construct(
        public readonly string $errorCode,
        string $message,
        public readonly int $status = 422,
        ?Throwable $previous = null,
        public readonly array $details = [],
    ) {
        parent::__construct($message, 0, $previous);
    }

    /** The API error envelope, which the exception handler returns for this exception. */
    public function render(Request $request): JsonResponse
    {
        $request->attributes->set('orbit.error_code', $this->errorCode);
        $request->attributes->set('orbit.error_message', $this->getMessage());

        return ApiErrorResponse::make($request, $this->errorCode, $this->getMessage(), [...NodeCaddyBuildException::detailsIn($this), ...$this->details], GatewayExceptionStatus::for($this));
    }
}
