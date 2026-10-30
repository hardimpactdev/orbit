<?php

declare(strict_types=1);

namespace App\Http\Responses;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** The API error envelope that the exception handler returns, with the request ID of the request. */
final class ApiErrorResponse
{
    /** @param array<array-key, mixed> $details */
    public static function make(Request $request, string $code, string $message, array $details, int $status): JsonResponse
    {
        $response = response()
            ->json([
                'error' => [
                    'code' => $code,
                    'message' => $message,
                    'details' => $details,
                ],
            ], $status)
            ->header('X-Orbit-Request-Id', self::requestId($request));
        if ($request->routeIs('operation:direct:private-material', 'operation:direct:secret-output')) {
            $response->header('Cache-Control', 'no-store');
        }

        return $response;
    }

    /** The request ID that EnsureRequestId assigned, or the one the client sent when the middleware did not run. */
    private static function requestId(Request $request): string
    {
        $requestId = $request->attributes->get('orbit.request_id');

        if (! is_string($requestId) || $requestId === '') {
            $requestId = $request->header('X-Orbit-Request-Id', '');
        }

        return $requestId;
    }
}
