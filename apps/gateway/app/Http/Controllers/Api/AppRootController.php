<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Contracts\Loggable;
use App\Enums\ActivityLogType;
use App\Exceptions\AppSelectionResolutionFailed;
use App\Http\Authorization\RequiresPermission;
use App\Http\Authorization\ServingNode;
use App\Models\App;
use App\Services\Apps\AppRootUpdater;
use App\Services\Apps\AppSelectorResolver;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

#[RequiresPermission('instance:root', servingNode: ServingNode::AppOwning)]
final class AppRootController implements Loggable
{
    private ?App $activitySubject = null;

    public function __invoke(string $instance, Request $request): JsonResponse
    {
        try {
            $selection = app(AppSelectorResolver::class)->requireInstance(
                app(AppSelectorResolver::class)->resolveRequired($instance),
            );
        } catch (AppSelectionResolutionFailed) {
            return $this->error(
                'instance.not_found',
                "Instance '{$instance}' not found.",
                ['instance' => $instance],
                404,
            );
        }

        $targetApp = $selection->app;

        $root = $this->optionalString($request, 'root');

        if ($root === null) {
            return $this->error('validation_failed', 'Root is required.', ['field' => 'root'], 422);
        }

        $result = app(AppRootUpdater::class)->update([
            'instance' => $instance,
            'root' => $root,
            '--json' => true,
        ]);

        $this->activitySubject = App::query()->where('name', $targetApp->name)->first();

        return response()->json($result->payload, $result->successful() ? 200 : 422);
    }

    private function optionalString(Request $request, string $key): ?string
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
                'meta' => $meta,
            ],
        ], $status);
    }

    public function effect(): ActivityLogType
    {
        return ActivityLogType::Write;
    }

    public function activityLogType(): ActivityLogType
    {
        return $this->effect();
    }

    public function type(): string
    {
        return 'api:POST /instances/{instance}/root';
    }

    public function activityLogAction(): string
    {
        return $this->type();
    }

    public function subject(): ?Model
    {
        return $this->activitySubject;
    }

    public function activityLogSubject(): ?Model
    {
        return $this->subject();
    }

    /**
     * @return array<string, mixed>
     */
    public function properties(): array
    {
        return [
            'root' => $this->optionalString(request(), 'root'),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function activityLogProperties(): array
    {
        return $this->properties();
    }

    public function description(): ?string
    {
        return null;
    }

    public function activityLogDescription(): ?string
    {
        return $this->description();
    }
}
