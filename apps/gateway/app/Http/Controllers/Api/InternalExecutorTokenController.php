<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Models\Node;
use App\Services\Operations\OperationTokenIntrospector;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final readonly class InternalExecutorTokenController
{
    public function __invoke(Request $request, OperationTokenIntrospector $introspector): JsonResponse
    {
        $validated = $request->validate([
            'operation_token' => ['required', 'string'],
            'command' => [
                'required',
                'string',
                function (string $attribute, mixed $value, Closure $fail): void {
                    if ($value === 'version' || is_string($value) && str_starts_with($value, 'internal:')) {
                        return;
                    }

                    $fail("The {$attribute} field must be an internal command or the version proof command.");
                },
            ],
        ]);

        /** @var Node $node */
        $node = $request->user();

        return response()->json([
            'success' => [
                'data' => $introspector->introspect(
                    compactToken: $validated['operation_token'],
                    expectedNode: $node->name,
                    expectedCommand: $validated['command'],
                ),
            ],
        ]);
    }
}
