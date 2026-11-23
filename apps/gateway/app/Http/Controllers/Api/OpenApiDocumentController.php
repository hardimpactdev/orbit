<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiErrorResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Serves the OpenAPI document of this release.
 *
 * `bin/build-api` writes docs/openapi.json and the MCP tool manifest in one run when the release installs its
 * dependencies, so the bytes served here are exactly what the running Gateway speaks. The header carries their
 * SHA-256 so a client can pin the document it generated from.
 */
final class OpenApiDocumentController extends Controller
{
    public function show(Request $request): Response
    {
        $contents = @file_get_contents(base_path('../../docs/openapi.json'));

        if (! is_string($contents)) {
            return ApiErrorResponse::make($request, 'openapi.unavailable', 'This release has no generated OpenAPI document.', [], 503);
        }

        return response($contents, 200, [
            'Content-Type' => 'application/json',
            'X-Orbit-OpenAPI-SHA256' => hash('sha256', $contents),
        ]);
    }
}
