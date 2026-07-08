<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Models\Node;
use App\Services\Operations\OperationTokenConsumptionGuard;
use App\Services\Operations\OperationTokenConsumptionResult;
use App\Services\Operations\OperationTokenIntrospector;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final readonly class InternalExecutorTokenController
{
    public function __invoke(
        Request $request,
        OperationTokenIntrospector $introspector,
        OperationTokenConsumptionGuard $consumptionGuard,
    ): JsonResponse {
        $validated = $request->validate([
            'operation_token' => ['required', 'string'],
            'command' => [
                'required',
                'string',
                static function (string $attribute, mixed $value, Closure $fail): void {
                    if ($value === 'version' || is_string($value) && str_starts_with($value, 'internal:')) {
                        return;
                    }

                    $fail("The {$attribute} field must be an internal command or the version proof command.");
                },
            ],
            'argv' => ['required', 'array', 'list'],
            'argv.*' => ['required', 'string'],
            'cwd' => ['nullable', 'string'],
            'environment' => ['sometimes', 'array'],
            'environment.*' => ['string'],
            'input' => ['nullable', 'string'],
        ]);

        /** @var Node $node */
        $node = $request->user();

        /** @var list<string> $argv */
        $argv = $validated['argv'];
        /** @var array<string, string> $environment */
        $environment = $validated['environment'] ?? [];
        $compactToken = $request->string('operation_token')->toString();
        $expectedCommand = $request->string('command')->toString();
        $cwd = $request->filled('cwd') ? $request->string('cwd')->toString() : null;
        $input = $request->filled('input') ? $request->string('input')->toString() : null;

        $result = $introspector->introspect(
            compactToken: $compactToken,
            expectedNode: $node->name,
            expectedCommand: $expectedCommand,
            argv: $argv,
            cwd: $cwd,
            environment: $environment,
            input: $input,
        );

        if ($result['allowed'] && $result['operation_id'] !== null) {
            $consumptionResult = $consumptionGuard->consume($result['operation_id']);

            if ($consumptionResult !== OperationTokenConsumptionResult::Consumed) {
                $result = [
                    'allowed' => false,
                    'reason' => $consumptionResult->value,
                    'operation_id' => $result['operation_id'],
                ];
            }
        }

        return response()->json([
            'success' => [
                'data' => $result,
            ],
        ]);
    }
}
