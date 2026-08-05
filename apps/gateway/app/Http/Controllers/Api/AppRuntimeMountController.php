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
use App\Models\Instance;
use App\Models\InstanceRuntimeMount;
use App\Services\Apps\AppResponsePayload;
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

    #[RequiresPermission('instance:read', servingNode: ServingNode::AppOwning)]
    public function index(string $instance, AppRuntimeMountService $mounts): JsonResponse
    {
        $app = $instance;
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

        if (! $targetInstance instanceof Instance) {
            return $this->instanceRequired();
        }

        return $this->success($this->instanceMountsPayload(
            $targetApp,
            $targetInstance,
            $mounts->listForInstance($targetInstance),
            $mounts,
        ));
    }

    #[RequiresPermission('instance:mount', servingNode: ServingNode::AppOwning)]
    public function store(string $instance, Request $request, AppRuntimeMountService $mounts): JsonResponse
    {
        $app = $instance;
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

        if (! $targetInstance instanceof Instance) {
            return $this->instanceRequired();
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

    #[RequiresPermission('instance:mount', servingNode: ServingNode::AppOwning)]
    public function destroy(string $instance, Request $request, AppRuntimeMountService $mounts): JsonResponse
    {
        $app = $instance;
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

        if (! $targetInstance instanceof Instance) {
            return $this->instanceRequired();
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

        if ($result['mount'] instanceof InstanceRuntimeMount) {
            $payload['mount'] = $mounts->instanceMountPayload($result['mount']);
        }

        return $this->success($payload);
    }

    private function instanceRequired(): JsonResponse
    {
        return $this->validationFailed(
            'Runtime mounts can only be changed on instances. Use a dotted selector such as hauser.nmbp.',
            ['field' => 'instance', 'reason' => 'instance_required'],
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
     * @return array{app: App, instance: Instance|null}|JsonResponse|null
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
     * @param  Collection<int, InstanceRuntimeMount>  $mounts
     * @return array{
     *     app: array<string, mixed>,
     *     target: array{type: string, app: string, instance: string},
     *     mounts: list<array{source: string, target: string, read_only: bool}>,
     *     inherited_by_workspaces: bool
     * }
     */
    private function instanceMountsPayload(
        App $app,
        Instance $instance,
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
                'type' => 'instance',
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
        return app(AppResponsePayload::class)->forApp($app);
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

    private function notFound(string $instance): JsonResponse
    {
        return response()->json([
            'error' => [
                'code' => 'instance.not_found',
                'message' => "Instance '{$instance}' not found.",
                'meta' => ['instance' => $instance],
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
            'add' => 'api:POST /instances/{instance}/mounts',
            'remove' => 'api:DELETE /instances/{instance}/mounts',
            default => 'api:GET /instances/{instance}/mounts',
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
