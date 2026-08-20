<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Contracts\Loggable;
use App\Enums\ActivityLogType;
use App\Models\Node;
use App\Services\Nodes\OperatorNodeManagementException;
use App\Services\Nodes\OperatorNodeManager;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Throwable;

final class NodeManageController implements Loggable
{
    private ?Node $activitySubject = null;

    /** @var array<string, mixed> */
    private array $activityProperties = [];

    public function manage(Request $request, OperatorNodeManager $manager): JsonResponse
    {
        $this->activityProperties = array_filter(
            [
                'user' => $this->stringInput($request, 'user'),
                'platform' => $this->stringInput($request, 'platform'),
                'managed' => true,
            ],
            static fn (mixed $value): bool => $value !== null,
        );

        /** @var mixed $caller */
        $caller = $request->user();

        if (! $caller instanceof Node) {
            return $this->error('authorization_failed', 'Peer identity unknown.', [], 403);
        }

        $this->activitySubject = $caller;

        if (! $caller->isActive() || ! $caller->isOperator()) {
            return $this->error('node.not_operator', 'Only active roleless nodes can manage themselves.', [], 422);
        }

        $user = $this->stringInput($request, 'user');
        $platform = $this->stringInput($request, 'platform');

        if ($user === null) {
            return $this->error('validation_failed', 'User is required.', ['field' => 'user'], 422);
        }

        if ($platform === null) {
            return $this->error('validation_failed', 'Platform is required.', ['field' => 'platform'], 422);
        }

        try {
            $management = $manager->manage($caller, $user, $platform);
        } catch (OperatorNodeManagementException $exception) {
            return $this->error($exception->errorCode, $exception->getMessage(), [], 422);
        } catch (Throwable $exception) {
            return $this->error('node.manage_failed', $exception->getMessage(), [], 422);
        }

        return response()->json([
            'success' => [
                'data' => [
                    'management' => $management,
                ],
            ],
        ]);
    }

    public function effect(): ActivityLogType
    {
        return ActivityLogType::Write;
    }

    public function type(): string
    {
        return 'node.managed';
    }

    public function subject(): ?Model
    {
        return $this->activitySubject;
    }

    public function properties(): array
    {
        return $this->activityProperties;
    }

    public function description(): ?string
    {
        return null;
    }

    private function stringInput(Request $request, string $key): ?string
    {
        $value = $request->input($key);

        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }

    /**
     * @param  array<string, mixed>  $meta
     */
    private function error(string $code, string $message, array $meta, int $status): JsonResponse
    {
        return response()->json([
            'error' => [
                'code' => $code,
                'message' => $message,
                'meta' => $meta === [] ? (object) [] : $meta,
            ],
        ], $status);
    }
}
