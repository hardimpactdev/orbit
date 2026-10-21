<?php

declare(strict_types=1);

namespace App\Http\Responses;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Throwable;

/**
 * A domain exception that renders its own API error response with ApiErrorResponse. The status source,
 * the error log filter, and the activity record read this interface, so they need no list of exceptions.
 */
interface RendersApiError extends Throwable
{
    /** The `error.code` of the response, which the activity record stores as well. */
    public function apiErrorCode(): string;

    public function render(Request $request): JsonResponse;
}
