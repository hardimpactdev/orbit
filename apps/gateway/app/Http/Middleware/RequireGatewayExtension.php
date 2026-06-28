<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Services\Extensions\GatewayExtensionState;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Orbit\Core\Http\JsonEnvelope;
use Symfony\Component\HttpFoundation\Response;

final readonly class RequireGatewayExtension
{
    public function __construct(
        private GatewayExtensionState $extensionState,
    ) {}

    /**
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next, string $slug): Response
    {
        if (! $this->extensionState->isKnownSlug($slug)) {
            return $this->unknownExtension($slug);
        }

        if (! $this->extensionState->enabled($slug)) {
            return $this->disabledExtension($slug);
        }

        return $next($request);
    }

    private function unknownExtension(string $slug): JsonResponse
    {
        return response()->json(
            JsonEnvelope::failure(
                'extension_unknown',
                "Unknown Orbit extension [{$slug}].",
                ['extension' => $slug],
            ),
            422,
        );
    }

    private function disabledExtension(string $slug): JsonResponse
    {
        return response()->json(
            JsonEnvelope::failure(
                'extension_disabled',
                "The {$slug} extension is not enabled on the gateway.",
                [
                    'extension' => $slug,
                    'scope' => 'gateway',
                ],
            ),
            409,
        );
    }
}
