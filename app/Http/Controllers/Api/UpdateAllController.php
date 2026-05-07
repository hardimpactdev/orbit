<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Contracts\Loggable;
use App\Enums\ActivityLogType;
use App\Models\Node;
use App\Services\OrbitUpdater;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class UpdateAllController implements Loggable
{
    private ?Node $activitySubject = null;

    public function __invoke(Request $request, OrbitUpdater $updater): JsonResponse
    {
        /** @var mixed $caller */
        $caller = $request->user();

        if (! $caller instanceof Node) {
            return $this->authorizationFailed('Peer identity unknown.');
        }

        $this->activitySubject = $caller;

        $nodes = Node::query()
            ->where('status', 'active')
            ->where('is_local', false)
            ->where('role', '!=', 'control')
            ->orderBy('name')
            ->get();

        $updates = [];

        $localResult = $updater->updateLocal();

        $updates[] = [
            'target' => 'local',
            'node' => null,
            'role' => null,
            'status' => $localResult->successful() ? 'completed' : 'failed',
        ];

        if (! $localResult->successful()) {
            return response()->json([
                'error' => [
                    'code' => 'local_update_failed',
                    'message' => 'Failed to update local Orbit checkout.',
                    'data' => [
                        'output' => trim($localResult->errorOutput() ?: $localResult->output()),
                    ],
                    'meta' => ['failed_step' => 'local_checkout'],
                ],
            ], 422);
        }

        foreach ($nodes as $node) {
            $result = $updater->updateRemote($node);
            $updates[] = [
                'target' => $node->name,
                'node' => $node->name,
                'role' => $node->role,
                'status' => $result->successful() ? 'completed' : 'failed',
                ...($result->successful() ? [] : ['output' => trim($result->errorOutput() ?: $result->output())]),
            ];
        }

        $completed = count(array_filter($updates, fn (array $u): bool => $u['status'] === 'completed'));
        $failed = count(array_filter($updates, fn (array $u): bool => $u['status'] === 'failed'));

        return response()->json([
            'success' => [
                'data' => [
                    'updates' => $updates,
                ],
                'meta' => [
                    'summary' => [
                        'total' => count($updates),
                        'completed' => $completed,
                        'failed' => $failed,
                    ],
                ],
            ],
        ]);
    }

    private function authorizationFailed(string $message): JsonResponse
    {
        return response()->json([
            'error' => [
                'code' => 'authorization_failed',
                'message' => $message,
                'meta' => [],
            ],
        ], 403);
    }

    public function effect(): ActivityLogType
    {
        return ActivityLogType::Write;
    }

    public function type(): string
    {
        return 'api:POST /update/all';
    }

    public function subject(): ?Model
    {
        return $this->activitySubject;
    }

    /**
     * @return array<string, mixed>
     */
    public function properties(): array
    {
        return [];
    }

    public function description(): ?string
    {
        return null;
    }
}
