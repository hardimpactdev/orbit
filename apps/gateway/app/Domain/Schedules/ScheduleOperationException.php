<?php

declare(strict_types=1);

namespace App\Domain\Schedules;

use App\Http\Responses\ApiErrorResponse;
use App\Infrastructure\Logging\GatewayExceptionStatus;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;
use Throwable;

final class ScheduleOperationException extends RuntimeException
{
    public readonly string $errorCode;

    public function __construct(
        public readonly string $step,
        public readonly ScheduleErrorCode $error,
        string $message,
        ?Throwable $previous = null,
    ) {
        $this->errorCode = $error->value;
        parent::__construct($message, 0, $previous);
    }

    /** The API error envelope, which the exception handler returns for this exception. */
    public function render(Request $request): JsonResponse
    {
        $request->attributes->set('orbit.error_code', $this->errorCode);

        return ApiErrorResponse::make($request, $this->errorCode, $this->getMessage(), ['step' => $this->step], GatewayExceptionStatus::for($this));
    }
}
