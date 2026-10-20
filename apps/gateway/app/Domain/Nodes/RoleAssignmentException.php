<?php

declare(strict_types=1);

namespace App\Domain\Nodes;

use App\Http\Responses\ApiErrorResponse;
use App\Http\Responses\RendersApiError;
use App\Infrastructure\Logging\GatewayExceptionStatus;
use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class RoleAssignmentException extends DomainException implements RendersApiError
{
    #[\Override]
    public function apiErrorCode(): string
    {
        return 'node.role_conflict';
    }

    /** The API error envelope, which the exception handler returns for this exception. */
    #[\Override]
    public function render(Request $request): JsonResponse
    {
        return ApiErrorResponse::make($request, $this->apiErrorCode(), $this->getMessage(), [], GatewayExceptionStatus::for($this));
    }
}
