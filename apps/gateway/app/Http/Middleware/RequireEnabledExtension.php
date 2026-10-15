<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Domain\Extensions\ExtensionStore;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Refuses an extension's operation while its switch is off. An extension's route group adds
 * `RequireEnabledExtension:<name>` ([Extensions](/reference/extensions#the-runtime-switch)).
 */
final readonly class RequireEnabledExtension
{
    public function __construct(private ExtensionStore $extensions) {}

    public function handle(Request $request, Closure $next, string $extension): Response
    {
        if (! $this->extensions->enabled($extension)) {
            return response()->json([
                'status' => 409,
                'error' => [
                    'code' => 'extension.disabled',
                    'message' => "The {$extension} extension is disabled.",
                    'details' => [],
                ],
            ], 409);
        }

        return $next($request);
    }
}
