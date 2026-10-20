<?php

declare(strict_types=1);

namespace App\Domain\Nodes;

use App\Http\Responses\ApiErrorResponse;
use App\Infrastructure\Logging\GatewayExceptionStatus;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;

final class NodeRoleValidationException extends RuntimeException
{
    /** @param array<string, mixed> $details */
    public function __construct(
        string $message,
        public readonly array $details = [],
    ) {
        parent::__construct($message);
    }

    /** The API error envelope, which the exception handler returns for this exception. */
    public function render(Request $request): JsonResponse
    {
        $request->attributes->set('orbit.error_code', 'validation.failed');

        return ApiErrorResponse::make($request, 'validation.failed', $this->getMessage(), $this->details, GatewayExceptionStatus::for($this));
    }
}
