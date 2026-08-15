<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Contracts\Loggable;
use App\Data\Apps\AppSelection;
use App\Enums\ActivityLogType;
use App\Exceptions\AnalyticsOperationFailed;
use App\Exceptions\AppSelectionResolutionFailed;
use App\Http\Authorization\RequiresPermission;
use App\Http\Authorization\ServingNode;
use App\Models\App;
use App\Models\Instance;
use App\Services\Analytics\AppAnalyticsBindingService;
use App\Services\Analytics\AppAnalyticsPayloadFactory;
use App\Services\Apps\AppSelectorResolver;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use InvalidArgumentException;
use RuntimeException;

/** @mago-expect lint:cyclomatic-complexity */
final class AppAnalyticsController implements Loggable
{
    public function __construct(
        private readonly AppSelectorResolver $appSelectorResolver,
        private readonly AppAnalyticsPayloadFactory $payloads,
    ) {}

    private ?App $activitySubject = null;

    private ?string $activityTargetName = null;

    private ActivityLogType $activityEffect = ActivityLogType::Read;

    private string $activityType = 'api:GET /instances/{instance}/analytics';

    private string $activityAction = 'show';

    /**
     * @var list<string>
     */
    private array $activityPublicHosts = [];

    #[RequiresPermission('instance:write', servingNode: ServingNode::AppOwning)]
    public function enable(Request $request, string $instance, AppAnalyticsBindingService $service): JsonResponse
    {
        $app = $instance;
        $this->activityTargetName = $app;
        $this->activityEffect = ActivityLogType::Write;
        $this->activityType = 'api:POST /instances/{instance}/analytics/enable';
        $this->activityAction = 'enable';

        $selection = $this->resolveInstance($app);

        if ($selection instanceof JsonResponse) {
            return $selection;
        }

        $targetApp = $selection->app;
        $targetInstance = $selection->instance;
        assert($targetInstance instanceof Instance);

        $publicHosts = $request->input('public_hosts', []);

        if (! is_array($publicHosts)) {
            return $this->error(
                code: 'validation_failed',
                message: 'Public hosts must be an array.',
                meta: ['field' => 'public_hosts'],
                status: 422,
            );
        }

        try {
            $binding = $service->enable($targetInstance, $publicHosts);
        } catch (AnalyticsOperationFailed $exception) {
            return $this->error(
                code: $exception->errorCode(),
                message: $exception->getMessage(),
                meta: ['app' => $targetApp->name, 'instance' => $targetInstance->name],
                status: 422,
            );
        } catch (InvalidArgumentException $exception) {
            return $this->error(
                code: 'validation_failed',
                message: $exception->getMessage(),
                meta: ['field' => 'public_hosts'],
                status: 422,
            );
        } catch (RuntimeException $exception) {
            return $this->error(
                code: 'analytics.prerequisite_failed',
                message: $exception->getMessage(),
                meta: ['app' => $targetApp->name, 'instance' => $targetInstance->name],
                status: 422,
            );
        }

        $this->activitySubject = $targetApp->refresh();
        $this->activityPublicHosts = $this->stringList($binding->public_hosts);

        return response()->json([
            'success' => [
                'data' => [
                    ...$this->payloads->enableResult($binding),
                ],
            ],
        ]);
    }

    #[RequiresPermission('instance:write', servingNode: ServingNode::AppOwning)]
    public function disable(string $instance, AppAnalyticsBindingService $service): JsonResponse
    {
        $app = $instance;
        $this->activityTargetName = $app;
        $this->activityEffect = ActivityLogType::Write;
        $this->activityType = 'api:POST /instances/{instance}/analytics/disable';
        $this->activityAction = 'disable';

        $selection = $this->resolveInstance($app);

        if ($selection instanceof JsonResponse) {
            return $selection;
        }

        $targetApp = $selection->app;
        $targetInstance = $selection->instance;
        assert($targetInstance instanceof Instance);

        try {
            $binding = $service->disable($targetInstance);
        } catch (AnalyticsOperationFailed $exception) {
            return $this->error(
                code: $exception->errorCode(),
                message: $exception->getMessage(),
                meta: ['app' => $targetApp->name, 'instance' => $targetInstance->name],
                status: 422,
            );
        } catch (RuntimeException $exception) {
            return $this->error(
                code: 'analytics.binding_missing',
                message: $exception->getMessage(),
                meta: ['app' => $targetApp->name, 'instance' => $targetInstance->name],
                status: 422,
            );
        }

        $this->activitySubject = $targetApp->refresh();
        $this->activityPublicHosts = $this->stringList($binding->public_hosts);

        return response()->json([
            'success' => [
                'data' => [
                    'binding' => $this->payloads->binding($binding),
                ],
            ],
        ]);
    }

    #[RequiresPermission('instance:read', servingNode: ServingNode::AppOwning)]
    public function show(string $instance, AppAnalyticsBindingService $service): JsonResponse
    {
        $app = $instance;
        $this->activityTargetName = $app;
        $this->activityEffect = ActivityLogType::Read;
        $this->activityType = 'api:GET /instances/{instance}/analytics';
        $this->activityAction = 'show';

        $selection = $this->resolveInstance($app);

        if ($selection instanceof JsonResponse) {
            return $selection;
        }

        $targetApp = $selection->app;
        $targetInstance = $selection->instance;
        assert($targetInstance instanceof Instance);

        try {
            $binding = $service->show($targetInstance);
        } catch (RuntimeException $exception) {
            return $this->error(
                code: 'analytics.binding_missing',
                message: $exception->getMessage(),
                meta: ['app' => $targetApp->name, 'instance' => $targetInstance->name],
                status: 422,
            );
        }

        $this->activitySubject = $targetApp->refresh();
        $this->activityPublicHosts = $this->stringList($binding->public_hosts);

        return response()->json([
            'success' => [
                'data' => [
                    'binding' => $this->payloads->binding($binding),
                ],
            ],
        ]);
    }

    #[RequiresPermission('instance:read', servingNode: ServingNode::AppOwning)]
    public function verify(string $instance, AppAnalyticsBindingService $service): JsonResponse
    {
        $app = $instance;
        $this->activityTargetName = $app;
        $this->activityEffect = ActivityLogType::Read;
        $this->activityType = 'api:GET /instances/{instance}/analytics/verify';
        $this->activityAction = 'verify';

        $selection = $this->resolveInstance($app);

        if ($selection instanceof JsonResponse) {
            return $selection;
        }

        $targetApp = $selection->app;
        $targetInstance = $selection->instance;
        assert($targetInstance instanceof Instance);

        try {
            $binding = $service->show($targetInstance);
        } catch (RuntimeException $exception) {
            return $this->error(
                code: 'analytics.binding_missing',
                message: $exception->getMessage(),
                meta: ['app' => $targetApp->name, 'instance' => $targetInstance->name],
                status: 422,
            );
        }

        $this->activitySubject = $targetApp->refresh();
        $this->activityPublicHosts = $this->stringList($binding->public_hosts);

        return response()->json([
            'success' => [
                'data' => [
                    'verification_context' => $this->payloads->verificationContext($binding),
                ],
            ],
        ]);
    }

    private function resolveInstance(string $selector): AppSelection|JsonResponse
    {
        $selection = $this->appSelectorResolver->resolve($selector);

        if (! $selection instanceof AppSelection) {
            return $this->error(
                code: 'instance.not_found',
                message: "Instance '{$selector}' not found.",
                meta: ['instance' => $selector],
                status: 404,
            );
        }

        try {
            $selection = $this->appSelectorResolver->requireInstance($selection);
        } catch (AppSelectionResolutionFailed $exception) {
            return $this->error(
                code: $exception->errorCode,
                message: $exception->getMessage(),
                meta: $exception->meta,
                status: 422,
            );
        }

        return $selection;
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

    /**
     * @return list<string>
     */
    private function stringList(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        return array_values(array_filter($value, is_string(...)));
    }

    public function effect(): ActivityLogType
    {
        return $this->activityEffect;
    }

    public function type(): string
    {
        return $this->activityType;
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
        return [
            'action' => $this->activityAction,
            'target_instance' => $this->activityTargetName ?? (string) request()->route('instance'),
            'public_hosts' => $this->activityPublicHosts,
        ];
    }

    public function description(): ?string
    {
        $target = $this->activityTargetName ?? (string) request()->route('instance');

        if ($target === '') {
            return null;
        }

        return "Analytics {$this->activityAction} for {$target}";
    }
}
