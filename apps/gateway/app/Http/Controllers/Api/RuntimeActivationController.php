<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Models\Node;
use App\Services\Processes\RuntimeHibernation;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class RuntimeActivationController
{
    public function __invoke(
        Request $request,
        string $type,
        int $id,
        RuntimeHibernation $hibernation,
    ): Response {
        /** @var Node|null $caller */
        $caller = $request->user();
        $result = $hibernation->activate($type, $id, $caller instanceof Node ? $caller : null);

        return match ($result) {
            RuntimeHibernation::ACTIVATED => response()->noContent(),
            RuntimeHibernation::FORBIDDEN => $this->error(
                'authorization_failed',
                'Runtime activation is restricted to the exact serving node.',
                403,
            ),
            RuntimeHibernation::NOT_FOUND => $this->error(
                'runtime_scope_not_found',
                'Runtime activation scope was not found.',
                404,
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
}
