<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Actions\Tasks\RecordTaskPullRequestTextAction;
use App\Actions\Tasks\RecordTaskReviewReceiptAction;
use App\Http\Controllers\Controller;
use App\Http\Middleware\RequireTaskKey;
use App\Http\Requests\Tasks\StoreTaskHandoffRequest;
use App\Http\Requests\Tasks\StoreTaskPullRequestTextRequest;
use App\Models\Instance;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The calls a task copy makes to Orbit, through its Node's forwarder and with its own key (docs/reference/ai-router.md#orbit-routes-for-task-copies).
 * The key names the copy; no call names a group, a check or a commit.
 */
final class TaskController extends Controller
{
    public function whoami(Request $request): JsonResponse
    {
        $copy = self::copy($request);

        return $this->respond($request, ['instance_id' => $copy->id, 'project_id' => $copy->project_id, 'group_id' => $copy->task_workspace_owner_id]);
    }

    public function pullRequest(StoreTaskPullRequestTextRequest $request, RecordTaskPullRequestTextAction $action): JsonResponse
    {
        ['check' => $check, 'opens' => $opens] = $action->execute(self::copy($request), $request->string('title')->toString(), $request->string('body')->toString());

        return $this->respond($request, ['state' => 'recorded', 'check_id' => $check->id, 'operation_id' => $check->pr_text_operation_id, 'opens' => $opens], 202);
    }

    public function handoff(StoreTaskHandoffRequest $request, RecordTaskReviewReceiptAction $action): JsonResponse
    {
        $check = $action->execute(self::copy($request), $request->string('verdict')->toString(), $request->findings());

        return $this->respond($request, ['state' => 'recorded', 'check_id' => $check->id, 'operation_id' => $check->review_receipt_operation_id], 202);
    }

    /** @param array<string, mixed> $data */
    private function respond(Request $request, array $data, int $status = 200): JsonResponse
    {
        return response()->json(['data' => $data, 'meta' => ['request_id' => $request->attributes->getString('orbit.request_id')]], $status);
    }

    private static function copy(Request $request): Instance
    {
        $copy = $request->attributes->get(RequireTaskKey::Attribute);

        return $copy instanceof Instance ? $copy : throw new \LogicException('The task copy route runs without its key middleware.');
    }
}
