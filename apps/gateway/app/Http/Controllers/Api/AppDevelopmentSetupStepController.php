<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Actions\Apps\AddAppDevelopmentSetupStep;
use App\Actions\Apps\RemoveAppDevelopmentSetupStep;
use App\Actions\Apps\UpdateAppDevelopmentSetupStep;
use App\Contracts\Loggable;
use App\Enums\ActivityLogType;
use App\Models\App;
use App\Models\AppDevelopmentSetupStep;
use App\Models\Node;
use App\Services\Nodes\Access\NodeAccessAuthorizer;
use App\Services\Workspaces\WorkspacePlacement;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * @mago-expect lint:cyclomatic-complexity
 * @mago-expect lint:kan-defect
 * @mago-expect lint:too-many-methods
 */
final class AppDevelopmentSetupStepController implements Loggable
{
    private ?AppDevelopmentSetupStep $activitySubject = null;
    private string $activityAction = 'list';

    public function __construct(
        private readonly NodeAccessAuthorizer $authorizer,
        private readonly WorkspacePlacement $placement,
        private readonly AddAppDevelopmentSetupStep $add,
        private readonly UpdateAppDevelopmentSetupStep $update,
        private readonly RemoveAppDevelopmentSetupStep $remove,
    ) {}

    public function index(string $app, Request $request): JsonResponse
    {
        $target = $this->target($app, $request, 'app:read');
        if ($target instanceof JsonResponse) {
            return $target;
        }

        return $this->success($target, 'listed');
    }

    public function store(string $app, Request $request): JsonResponse
    {
        $this->activityAction = 'add';
        $target = $this->target($app, $request, 'app:write');
        if ($target instanceof JsonResponse) {
            return $target;
        }
        $input = $request->json()->all();
        $command = trim((string) ($input['command'] ?? ''));
        if ($command === '') {
            return $this->error('validation_failed', 'Command is required.', 422);
        }
        $timeout = $this->positive($input['timeout'] ?? 600);
        if ($timeout === null) {
            return $this->error('validation_failed', 'Timeout must be a positive integer.', 422);
        }
        $before = $this->anchor($input, 'before');
        $after = $this->anchor($input, 'after');
        if ($before === false || $after === false) {
            return $this->error('app.development_setup_step_invalid_anchor', 'Anchor must be a positive integer.', 422);
        }
        if ($before !== null && $after !== null) {
            return $this->error('validation_failed', 'Both before and after cannot be supplied.', 422);
        }
        try {
            $step = $this->add->handle($target->id, $command, $timeout, $before, $after);
        } catch (\InvalidArgumentException $e) {
            return $this->error('validation_failed', $e->getMessage(), 422);
        }
        $this->activitySubject = $step;

        return response()->json([
            'success' => ['data' => ['action' => 'added', 'step' => $this->payload($step)], 'meta' => []],
        ], 201);
    }

    public function update(string $app, int $step, Request $request): JsonResponse
    {
        $this->activityAction = 'update';
        $target = $this->target($app, $request, 'app:write');
        if ($target instanceof JsonResponse) {
            return $target;
        }
        $model = AppDevelopmentSetupStep::query()->where('app_id', $target->id)->find($step);
        if (! $model instanceof AppDevelopmentSetupStep) {
            return $this->error('app.development_setup_step_not_found', 'Setup step was not found.', 404);
        }
        $input = $request->json()->all();
        if ($input === []) {
            return $this->error('validation_failed', 'At least one setup step value is required.', 422);
        }
        $command = array_key_exists('command', $input) ? trim((string) $input['command']) : null;
        $timeout = array_key_exists('timeout', $input) ? $this->positive($input['timeout']) : null;
        if ($command === '' || array_key_exists('timeout', $input) && $timeout === null) {
            return $this->error('validation_failed', 'Invalid setup step values.', 422);
        }
        $before = $this->anchor($input, 'before');
        $after = $this->anchor($input, 'after');
        if ($before === false || $after === false || $before !== null && $after !== null) {
            return $this->error(
                'app.development_setup_step_invalid_anchor',
                'Provide one valid before or after anchor.',
                422,
            );
        }
        try {
            $model = $this->update->handle(
                $model,
                $command,
                $timeout,
                $before,
                $after,
            );
        } catch (\InvalidArgumentException $e) {
            return $this->error('validation_failed', $e->getMessage(), 422);
        }
        $this->activitySubject = $model;

        return response()->json([
            'success' => ['data' => ['action' => 'updated', 'step' => $this->payload($model)], 'meta' => []],
        ]);
    }

    public function destroy(string $app, int $step, Request $request): JsonResponse
    {
        $this->activityAction = 'remove';
        $target = $this->target($app, $request, 'app:write');
        if ($target instanceof JsonResponse) {
            return $target;
        }
        if (! $request->boolean('destructive_consent')) {
            return $this->error(
                'validation_failed',
                'Use --force to remove this setup step.',
                422,
                ['reason' => 'destructive_consent_required'],
            );
        }
        $model = AppDevelopmentSetupStep::query()->where('app_id', $target->id)->find($step);
        if (! $model instanceof AppDevelopmentSetupStep) {
            return $this->error('app.development_setup_step_not_found', 'Setup step was not found.', 404);
        }
        $removed = $this->payload($model);
        $this->activitySubject = $model;
        $this->remove->handle($model);

        return response()->json(['success' => ['data' => ['action' => 'removed', 'step' => $removed], 'meta' => []]]);
    }

    private function target(string $selector, Request $request, string $permission): App|JsonResponse
    {
        $app = App::query()->with(['instances', 'developmentSetupSteps'])->where('name', $selector)->first();
        if (! $app instanceof App) {
            return $this->error('app.not_found', "App '{$selector}' was not found.", 404); /** @var mixed $caller */
        }
        $caller = $request->user();
        if (! $caller instanceof Node) {
            return $this->error('authorization_failed', 'Peer identity unknown.', 403, [
                'reason' => 'unknown_caller',
                'target' => $selector,
            ]);
        }
        $authorized = $app->instances->contains(function (Model $instance) use ($caller, $permission): bool {
            $node = $this->placement->nodeForInstance($instance);

            return $node instanceof Node && $this->authorizer->authorize($caller, $node, $permission)->allowed;
        });
        if (! $authorized) {
            return $this->error('authorization_failed', 'This node is not authorized for this app.', 403, [
                'reason' => 'missing_permission',
                'missing_permission' => $permission,
                'target' => $selector,
            ]);
        }

        return $app;
    }

    private function success(App $app, string $action): JsonResponse
    {
        return response()->json([
            'success' => [
                'data' => [
                    'action' => $action,
                    'steps' => $app
                        ->developmentSetupSteps
                        ->map($this->payload(...))
                        ->values()
                        ->all(),
                ],
                'meta' => [],
            ],
        ]);
    }

    private function payload(AppDevelopmentSetupStep $s): array
    {
        return [
            'id' => $s->id,
            'app' => $s->app?->name,
            'order' => $s->sort_order,
            'command' => $s->command,
            'timeout_seconds' => $s->timeout_seconds,
        ];
    }

    private function positive(mixed $v): ?int
    {
        if (is_int($v) && $v > 0) {
            return $v;
        }

        if (is_string($v) && ctype_digit($v) && (int) $v > 0) {
            return (int) $v;
        }

        return null;
    }

    private function anchor(array $input, string $key): int|false|null
    {
        if (! array_key_exists($key, $input) || $input[$key] === null || $input[$key] === '') {
            return null;
        }

        return $this->positive($input[$key]) ?? false;
    }

    private function error(string $code, string $message, int $status, array $meta = []): JsonResponse
    {
        return response()->json(['error' => ['code' => $code, 'message' => $message, 'meta' => $meta]], $status);
    }

    public function effect(): ActivityLogType
    {
        return match ($this->activityAction) {
            'list' => ActivityLogType::Read,
            'remove' => ActivityLogType::Destructive,
            default => ActivityLogType::Write,
        };
    }

    public function type(): string
    {
        $suffix = in_array(request()->method(), ['PATCH', 'DELETE'], strict: true) ? '/{step}' : '';

        return 'api:'.strtoupper((string) request()->method()).' /apps/{app}/development-setup-steps'.$suffix;
    }

    public function subject(): ?Model
    {
        return $this->activitySubject;
    }

    public function properties(): array
    {
        return ['app' => request()->route('app'), 'step' => request()->route('step')];
    }

    public function description(): ?string
    {
        return null;
    }
}
