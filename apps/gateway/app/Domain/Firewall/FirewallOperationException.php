<?php

declare(strict_types=1);

namespace App\Domain\Firewall;

use App\Http\Responses\ApiErrorResponse;
use App\Http\Responses\RendersApiError;
use App\Infrastructure\Logging\GatewayExceptionStatus;
use App\Infrastructure\Processes\CommandResult;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;
use Throwable;

final class FirewallOperationException extends RuntimeException implements RendersApiError
{
    public function __construct(
        public readonly string $step,
        public readonly string $errorCode,
        string $message,
        public readonly ?CommandResult $result = null,
        ?Throwable $previous = null,
        public readonly int $status = 502,
    ) {
        parent::__construct($message, previous: $previous);
    }

    #[\Override]
    public function apiErrorCode(): string
    {
        return $this->errorCode;
    }

    /** The API error envelope, which the exception handler returns for this exception. */
    #[\Override]
    public function render(Request $request): JsonResponse
    {
        $request->attributes->set('orbit.error_code', $this->apiErrorCode());
        $request->attributes->set('orbit.command_result', $this->result);

        return ApiErrorResponse::make($request, $this->apiErrorCode(), $this->getMessage(), ['step' => $this->step], GatewayExceptionStatus::for($this));
    }
}
