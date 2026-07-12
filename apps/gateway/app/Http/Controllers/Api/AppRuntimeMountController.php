<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Contracts\Loggable;
use App\Data\Apps\AppSelection;
use App\Enums\ActivityLogType;
use App\Exceptions\AppSelectionResolutionFailed;
use App\Http\Authorization\RequiresPermission;
use App\Http\Authorization\ServingNode;
use App\Models\App;
use App\Models\AppInstance;
use App\Models\AppInstanceRuntimeMount;
use App\Services\Apps\AppRuntimeMountService;
use App\Services\Apps\AppRuntimeMountValidationException;
use App\Services\Apps\AppSelectorResolver;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

/**
 * @mago-expect lint:cyclomatic-complexity
 */
final class AppRuntimeMountController implements Loggable
{
    private ?App $activitySubject = null;

    private string $currentAction = 'list';

    private ?string $currentTarget = null;

    public function __construct(
        private readonly AppSelectorResolver $selectorResolver,
    ) {}

    #[RequiresPermission('app:read', servingNode: ServingNode::AppOwning)]
    public function index(string $app, AppRuntimeMountService $mounts): JsonResponse
    {
        $this->currentAction = 'list';

        $resolved = $this->resolveMountTarget($app);

        if ($resolved instanceof JsonResponse) {
            return $resolved;
        }

        if ($resolved === null) {
            return $this->notFound($app);
        }

        $targetApp = $resolved['app'];
        $this->activitySubject = $targetApp;

        $targetInstance = $resolved['instance'];

        if (! $targetInstance instanceof AppInstance) {
            return $this->appInstanceRequired();
        }

        return $this->success($this->instanceMountsPayload(
            $targetApp,
            $targetInstance,
            $mounts->listForInstance($targetInstance),
            $mounts,
        ));
    }

    #[RequiresPermission('app:mount', servingNode: ServingNode::AppOwning)]
    public function store(string $app, Request $request, AppRuntimeMountService $mounts): JsonResponse
    {
        $this->currentAction = 'add';

        $resolved = $this->resolveMountTarget($app);

        if ($resolved instanceof JsonResponse) {
            return $resolved;
        }

        if ($resolved === null) {
            return $this->notFound($app);
        }

        $targetApp = $resolved['app'];
        $this->activitySubject = $targetApp;

        $source = $this->stringInput($request, 'source');
        $target = $this->stringInput($request, 'target');
        $this->currentTarget = $target;

        if ($source === null) {
            return $this->validationFailed('Source path is required.', ['field' => 'source']);
        }

        if ($target === null) {
            return $this->validationFailed('Target path is required.', ['field' => 'target']);
        }

        $targetInstance = $resolved['instance'];

        if (! $targetInstance instanceof AppInstance) {
            return $this->appInstanceRequired();
        }

        try {
            $result = $mounts->addToInstance(
                instance: $targetInstance,
                source: $source,
                target: $target,
                readOnly: $this->readOnly($request),
            );
        } catch (AppRuntimeMountValidationException $exception) {
            return $this->validationFailed($exception->getMessage(), $exception->meta);
        }

        return $this->success([
            ...$this->instanceMountsPayload($targetApp, $targetInstance, $result['mounts'], $mounts),
            'mount' => $mounts->instanceMountPayload($result['mount']),
            'action' => $result['action'],
        ]);
    }

    #[RequiresPermission('app:mount', servingNode: ServingNode::AppOwning)]
    public function destroy(string $app, Request $request, AppRuntimeMountService $mounts): JsonResponse
    {
        $this->currentAction = 'remove';

        $resolved = $this->resolveMountTarget($app);

        if ($resolved instanceof JsonResponse) {
            return $resolved;
        }

        if ($resolved === null) {
            return $this->notFound($app);
        }

        $targetApp = $resolved['app'];
        $this->activitySubject = $targetApp;

        $target = $this->stringInput($request, 'target');
        $this->currentTarget = $target;

        if ($target === null) {
            return $this->validationFailed('Target path is required.', ['field' => 'target']);
        }

        $targetInstance = $resolved['instance'];

        if (! $targetInstance instanceof AppInstance) {
            return $this->appInstanceRequired();
        }

        try {
            $result = $mounts->removeFromInstance($targetInstance, $target);
        } catch (AppRuntimeMountValidationException $exception) {
            return $this->validationFailed($exception->getMessage(), $exception->meta);
        }

        $payload = [
            ...$this->instanceMountsPayload($targetApp, $targetInstance, $result['mounts'], $mounts),
            'action' => $result['action'],
        ];

        if ($result['mount'] instanceof AppInstanceRuntimeMount) {
            $payload['mount'] = $mounts->instanceMountPayload($result['mount']);
        }

        return $this->success($payload);
    }

    private function appInstanceRequired(): JsonResponse
    {
        return $this->validationFailed(
            'Runtime mounts can only be changed on app instances. Use a dotted selector such as hauser.nmbp.',
            ['field' => 'app', 'reason' => 'app_instance_required'],
        );
    }

    private function readOnly(Request $request): bool
    {
        if ($request->has('read_write')) {
            return ! $request->boolean('read_write');
        }

        return $request->boolean('read_only', true);
    }

    private function stringInput(Request $request, string $key): ?string
    {
        $value = $request->input($key);

        if (! is_string($value)) {
            return null;
        }

        $value = trim($value);

        return $value === '' ? null : $value;
    }

    /**
     * @return array{app: App, instance: AppInstance|null}|JsonResponse|null
     */
    private function resolveMountTarget(string $selector): array|JsonResponse|null
    {
        try {
            $selection = $this->selectorResolver->resolve($selector);
        } catch (AppSelectionResolutionFailed $exception) {
            return $this->validationFailed($exception->getMessage(), $exception->meta);
        }

        if (! $selection instanceof AppSelection) {
            return null;
        }

        return [
            'app' => $selection->app,
            'instance' => $selection->instance,
        ];
    }

    /**
     * @param  Collection<int, AppInstanceRuntimeMount>  $mounts
     * @return array{
     *     app: array<string, mixed>,
     *     target: array{type: string, app: string, instance: string},
     *     mounts: list<array{source: string, target: string, read_only: bool}>,
     *     inherited_by_workspaces: bool
     * }
     */
    private function instanceMountsPayload(
        App $app,
        AppInstance $instance,
        Collection $mounts,
        AppRuntimeMountService $service,
    ): array {
        $mountPayloads = $mounts
            ->map($service->instanceMountPayload(...))
            ->values()
            ->all();

        /** @var list<array{source: string, target: string, read_only: bool}> $mountPayloads */
        return [
            'app' => $this->appPayload($app),
            'target' => [
                'type' => 'app_instance',
                'app' => $app->name,
                'instance' => $instance->name,
            ],
            'mounts' => $mountPayloads,
            'inherited_by_workspaces' => true,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function appPayload(App $app): array
    {
        $app->loadMissing('node');

        return [
            'name' => $app->name,
            'node' => $app->node?->name,
            'url' => $app->url(),
            'path' => $app->path,
            'root' => $app->document_root,
            'repository' => $app->repository,
            'runtime' => $app->runtimeKind()->value,
            'runtime_config' => $app->runtimeConfig()->toArray(),
            'php_version' => $app->php_version,
            'worker_enabled' => $app->worker_enabled,
            'worker_config' => is_array($app->worker_config) ? $app->worker_config : null,
            'adopted' => $app->adopted,
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function success(array $data): JsonResponse
    {
        return response()->json([
            'success' => [
                'data' => $data,
            ],
        ]);
    }

    private function notFound(string $app): JsonResponse
    {
        return response()->json([
            'error' => [
                'code' => 'app.not_found',
                'message' => "App '{$app}' not found.",
                'meta' => ['app' => $app],
            ],
        ], 404);
    }

    /**
     * @param  array<string, mixed>  $meta
     */
    private function validationFailed(string $message, array $meta): JsonResponse
    {
        return response()->json([
            'error' => [
                'code' => 'validation_failed',
                'message' => $message,
                'meta' => $meta,
            ],
        ], 422);
    }

    public function effect(): ActivityLogType
    {
        return $this->currentAction === 'list' ? ActivityLogType::Read : ActivityLogType::Write;
    }

    public function activityLogType(): ActivityLogType
    {
        return $this->effect();
    }

    public function type(): string
    {
        return match ($this->currentAction) {
            'add' => 'api:POST /apps/{app}/mounts',
            'remove' => 'api:DELETE /apps/{app}/mounts',
            default => 'api:GET /apps/{app}/mounts',
        };
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
        return array_filter(
            [
                'action' => $this->currentAction,
                'target' => $this->currentTarget,
            ],
            static fn (mixed $value): bool => $value !== null,
        );
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
