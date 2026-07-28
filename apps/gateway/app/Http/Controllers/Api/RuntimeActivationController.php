<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Models\Node;
use App\Services\Processes\RuntimeActivationOutcome;
use App\Services\Processes\RuntimeActivationPage;
use App\Services\Processes\RuntimeActivationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class RuntimeActivationController
{
    public function __invoke(
        Request $request,
        string $type,
        int $id,
        RuntimeActivationService $activation,
        RuntimeActivationPage $page,
    ): Response {
        /** @var Node|null $caller */
        $caller = $request->user();
        $result = $activation->activate(
            $type,
            $id,
            $caller instanceof Node ? $caller : null,
            $this->coldState($request),
            $this->isRetry($request),
        );

        return match ($result->status) {
            RuntimeActivationOutcome::ACTIVATED => response()->noContent(),
            RuntimeActivationOutcome::WAKING => $result->scope !== null && $result->operationRun !== null
                ? $page->response(
                    $result->scope,
                    $result->operationRun,
                    $this->forwardedUri($request),
                )
                : $this->error(
                    'runtime_activation_failed',
                    'Runtime activation did not start.',
                    503,
                ),
            RuntimeActivationOutcome::FORBIDDEN => $this->error(
                'authorization_failed',
                'Runtime activation is restricted to the exact serving node.',
                403,
            ),
            RuntimeActivationOutcome::NOT_FOUND => $this->error(
                'runtime_scope_not_found',
                'Runtime activation scope was not found.',
                404,
            ),
            RuntimeActivationOutcome::FAILED => $result->scope !== null && $result->operationRun !== null
                ? $page->response(
                    $result->scope,
                    $result->operationRun,
                    $this->forwardedUri($request),
                )
                : $this->error(
                    'runtime_activation_failed',
                    'Runtime activation did not complete.',
                    503,
                ),
            default => $this->error(
                'runtime_activation_failed',
                'Runtime activation did not complete.',
                503,
            ),
        };
    }

    private function error(string $code, string $message, int $status): JsonResponse
    {
        return response()->json([
            'error' => [
                'code' => $code,
                'message' => $message,
                'meta' => (object) [],
            ],
        ], $status);
    }

    private function isRetry(Request $request): bool
    {
        $uri = $this->forwardedUri($request);
        $query = parse_url($uri, PHP_URL_QUERY);

        if (! is_string($query)) {
            return false;
        }

        $values = [];
        parse_str($query, $values);

        return ($values['orbit-wake-retry'] ?? null) === '1';
    }

    private function coldState(Request $request): ?bool
    {
        return match ($request->header('X-Orbit-Runtime-Cold')) {
            '1' => true,
            '0' => false,
            default => null,
        };
    }

    private function forwardedUri(Request $request): string
    {
        $uri = $request->header('X-Forwarded-Uri', '/');

        return is_string($uri) ? $uri : '/';
    }
}
