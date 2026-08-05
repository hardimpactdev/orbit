<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Contracts\Loggable;
use App\Enums\ActivityLogType;
use App\Http\Authorization\RequiresPermission;
use App\Http\Authorization\ServingNode;
use App\Services\ApplicationLogs\WorkspaceApplicationLogStreamHttp;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

#[RequiresPermission('workspace:read', servingNode: ServingNode::WorkspaceOwning)]
final class WorkspaceApplicationLogStreamStartController implements Loggable
{
    private ?Model $activitySubject = null;

    /** @var array<string, mixed> */
    private array $activityProperties = [];

    public function __invoke(
        string $workspace,
        Request $request,
        WorkspaceApplicationLogStreamHttp $http,
    ): JsonResponse {
        $result = $http->start($workspace, $request);
        $this->activitySubject = $result['subject'];
        $this->activityProperties = $result['properties'];

        return $result['response'];
    }

    public function effect(): ActivityLogType
    {
        return ActivityLogType::Read;
    }

    public function type(): string
    {
        return 'api:POST /workspaces/{workspace}/log-stream';
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
        return $this->activityProperties;
    }

    public function description(): ?string
    {
        return null;
    }
}
