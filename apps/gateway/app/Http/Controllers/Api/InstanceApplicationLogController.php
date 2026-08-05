<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Contracts\Loggable;
use App\Enums\ActivityLogType;
use App\Http\Authorization\RequiresPermission;
use App\Http\Authorization\ServingNode;
use App\Services\ApplicationLogs\InstanceApplicationLogHttp;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

#[RequiresPermission('instance:read', servingNode: ServingNode::AppOwning)]
final class InstanceApplicationLogController implements Loggable
{
    private ?Model $activitySubject = null;

    /** @var array<string, mixed> */
    private array $activityProperties = [];

    public function __invoke(
        string $instance,
        Request $request,
        InstanceApplicationLogHttp $http,
    ): JsonResponse {
        $result = $http->show($instance, $request);
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
        return 'api:GET /instances/{instance}/log';
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
