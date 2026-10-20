<?php

declare(strict_types=1);

namespace App\Domain\Nodes;

use App\Http\Responses\ApiErrorResponse;
use App\Infrastructure\Logging\GatewayExceptionStatus;
use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class RoleAssignmentException extends DomainException
{
    /** The API error envelope, which the exception handler returns for this exception. */
    public function render(Request $request): JsonResponse
    {
        return ApiErrorResponse::make($request, 'node.role_conflict', $this->getMessage(), [], GatewayExceptionStatus::for($this));
    }
}
