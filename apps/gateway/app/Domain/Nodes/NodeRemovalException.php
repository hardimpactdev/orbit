<?php

declare(strict_types=1);

namespace App\Domain\Nodes;

use App\Http\Responses\ApiErrorResponse;
use App\Infrastructure\Logging\GatewayExceptionStatus;
use App\Infrastructure\Processes\CommandResult;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;
use Throwable;

final class NodeRemovalException extends RuntimeException
{
    public function __construct(
        public readonly string $step,
        public readonly string $errorCode,
        string $message,
        public readonly ?CommandResult $result = null,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, previous: $previous);
    }

    /** The API error envelope, which the exception handler returns for this exception. */
    public function render(Request $request): JsonResponse
    {
        $request->attributes->set('orbit.error_code', $this->errorCode);
        $request->attributes->set('orbit.command_result', $this->result);

        return ApiErrorResponse::make($request, $this->errorCode, $this->getMessage(), ['step' => $this->step, ...NodeRoleOperationException::detailsIn($this)], GatewayExceptionStatus::for($this));
    }
}
