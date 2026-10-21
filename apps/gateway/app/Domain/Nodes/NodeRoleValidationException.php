<?php

declare(strict_types=1);

namespace App\Domain\Nodes;

use App\Http\Responses\ApiErrorResponse;
use App\Http\Responses\RendersApiError;
use App\Infrastructure\Logging\GatewayExceptionStatus;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;

final class NodeRoleValidationException extends RuntimeException implements RendersApiError
{
    /** @param array<string, mixed> $details */
    public function __construct(
        string $message,
        public readonly array $details = [],
    ) {
        parent::__construct($message);
    }

    #[\Override]
    public function apiErrorCode(): string
    {
        return 'validation.failed';
    }

    /** The API error envelope, which the exception handler returns for this exception. */
    #[\Override]
    public function render(Request $request): JsonResponse
    {
        $request->attributes->set('orbit.error_code', $this->apiErrorCode());

        return ApiErrorResponse::make($request, $this->apiErrorCode(), $this->getMessage(), $this->details, GatewayExceptionStatus::for($this));
    }
}
