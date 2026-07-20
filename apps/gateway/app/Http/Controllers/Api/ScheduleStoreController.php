<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Actions\Schedules\AddSchedule;
use App\Contracts\Loggable;
use App\Enums\ActivityLogType;
use App\Http\Controllers\Api\Concerns\LogsScheduleApiActivity;
use App\Models\AppInstance;
use App\Models\Node;
use App\Models\Schedule;
use App\Services\Nodes\Access\NodeAccessAuthorizer;
use App\Services\Nodes\Roles\NodeRoleAssignments;
use App\Services\Schedules\ScheduleAppInstanceResolver;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Orbit\Sdk\Laravel\GatewayApiException;

final readonly class ScheduleStoreController implements Loggable
{
    use LogsScheduleApiActivity;

    public function __construct(
        private NodeAccessAuthorizer $authorizer,
        private ScheduleAppInstanceResolver $appInstances,
    ) {}

    public function __invoke(Request $request, AddSchedule $addSchedule): JsonResponse
    {
        /** @var mixed $caller */
        $caller = $request->user();

        if (! $caller instanceof Node) {
            return $this->error('authorization_failed', 'Peer identity unknown.', [], 403);
        }

        $input = $this->validatedInput($request);

        if ($input instanceof JsonResponse) {
            return $input;
        }

        $target = $this->resolveTarget($caller, $input['app'], $input['node']);

        if ($target instanceof JsonResponse) {
            return $target;
        }

        $authorization = $this->authorizeTarget($caller, $target);

        if ($authorization instanceof JsonResponse) {
            return $authorization;
        }

        try {
            $result = $addSchedule->handle(
                target: $target,
                name: $input['name'],
                interval: $input['interval'],
                timezone: $input['timezone'],
                executionType: $input['execution_type'],
                executionValue: $input['execution_value'],
                timeoutSeconds: $input['timeout_seconds'],
            );
            $targetName = data_get(target: $result, key: 'data.schedule.target.name');
            $schedule = Schedule::query()
                ->where('name', $input['name'])
                ->when(is_string($targetName), static fn ($query) => $query->where('target_name', $targetName))
                ->first();

            if ($schedule instanceof Schedule) {
                $this->setScheduleActivitySubject($request, $schedule);
            }
        } catch (GatewayApiException $e) {
            return $this->error(
                code: $e->errorCode() ?? 'validation_failed',
                message: $e->getMessage(),
                meta: $e->errorMeta(),
                status: $this->status($e),
                data: $e->errorData(),
            );
        }

        return response()->json(['success' => $result], 201);
    }

    /**
     * @return array{name: string, app: string|null, node: string|null, interval: string, timezone: string, execution_type: string, execution_value: string, timeout_seconds: int}|JsonResponse
     */
    private function validatedInput(Request $request): array|JsonResponse
    {
        $name = $this->optionalString($request, 'name');
        $app = $this->optionalString($request, 'app');
        $node = $this->optionalString($request, 'node');
        $interval = $this->optionalString($request, 'interval');
        $timezone = $this->optionalString($request, 'timezone') ?? 'UTC';
        $command = $this->optionalString($request, 'command');
        $script = $this->optionalString($request, 'script');
        $timeout = $request->integer('timeout', 900);

        if ($name === null) {
            return $this->error('validation_failed', 'The schedule name is required.', ['field' => 'name'], 422);
        }

        if (! preg_match('/^[a-z0-9](?:[a-z0-9-]{0,62}[a-z0-9])?$/', $name)) {
            return $this->error(
                'validation_failed',
                'The schedule name must contain only lowercase letters, digits, and hyphens, cannot start or end with a hyphen, and may not exceed 64 characters.',
                ['field' => 'name', 'value' => $name],
                422,
            );
        }

        if (($app === null) === ($node === null)) {
            return $this->error(
                'validation_failed',
                'Exactly one schedule target is required.',
                ['fields' => ['app', 'node']],
                422,
            );
        }

        if (($command === null) === ($script === null)) {
            return $this->error(
                'validation_failed',
                'Exactly one schedule execution source is required.',
                ['fields' => ['command', 'script']],
                422,
            );
        }

        if ($interval === null) {
            return $this->error(
                'schedule.interval_invalid',
                'The schedule interval is required.',
                ['field' => 'interval'],
                422,
            );
        }

        if (! in_array($timezone, timezone_identifiers_list(), true)) {
            return $this->error(
                'validation_failed',
                'The schedule timezone must be a valid IANA timezone.',
                ['field' => 'timezone', 'value' => $timezone],
                422,
            );
        }

        if ($timeout < 1 || $timeout > 86_400) {
            return $this->error(
                'validation_failed',
                'The schedule timeout must be between 1 and 86400 seconds.',
                ['field' => 'timeout'],
                422,
            );
        }

        return [
            'name' => $name,
            'app' => $app,
            'node' => $node,
            'interval' => $interval,
            'timezone' => $timezone,
            'execution_type' => $command === null ? 'script' : 'command',
            'execution_value' => $command ?? (string) $script,
            'timeout_seconds' => $timeout,
        ];
    }

    private function resolveTarget(Node $caller, ?string $app, ?string $node): AppInstance|Node|JsonResponse
    {
        if ($app !== null) {
            try {
                $selection = $this->appInstances->resolve($app, $caller, 'schedule:add');
            } catch (GatewayApiException $exception) {
                return $this->error(
                    $exception->errorCode() ?? 'validation_failed',
                    $exception->getMessage(),
                    $exception->errorMeta(),
                    $this->status($exception),
                );
            }

            return (
                $selection->instance instanceof AppInstance
                    ? $selection->instance
                    : $this->error(
                        'validation_failed',
                        "App '{$app}' requires a concrete app instance selector.",
                        ['field' => 'app', 'reason' => 'app_instance_required'],
                        422,
                    )
            );
        }

        $target = Node::query()
            ->with('schedulerState')
            ->where('name', $node)
            ->whereIn('id', app(NodeRoleAssignments::class)->activeGatewayOrAppHostNodeIds())
            ->first();

        return (
            $target instanceof Node
                ? $target
                : $this->error(
                    'validation_failed',
                    "Node '{$node}' not found.",
                    ['field' => 'node', 'value' => $node],
                    422,
                )
        );
    }

    private function authorizeTarget(Node $caller, AppInstance|Node $target): ?JsonResponse
    {
        $servingNode = $target instanceof AppInstance ? $this->appInstances->targetNode($target) : $target;

        if (! $servingNode instanceof Node) {
            return $this->error(
                'authorization_failed',
                'This node is not authorized to manage schedules for the selected scope.',
                [
                    'reason' => 'serving_node_unresolved',
                    'missing_permission' => 'schedule:add',
                ],
                403,
            );
        }

        $result = $this->authorizer->authorize($caller, $servingNode, 'schedule:add');

        if ($result->allowed) {
            return null;
        }

        return $this->error(
            'authorization_failed',
            'This node is not authorized to manage schedules for the selected scope.',
            [
                'reason' => $result->reason,
                'missing_permission' => $result->missingPermission,
                'serving_node' => $servingNode->name,
            ],
            403,
        );
    }

    private function optionalString(Request $request, string $key): ?string
    {
        $value = $request->input($key);

        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }

    /**
     * @param  array<string, mixed>  $meta
     * @param  array<string, mixed>  $data
     */
    private function error(string $code, string $message, array $meta, int $status, array $data = []): JsonResponse
    {
        $error = [
            'code' => $code,
            'message' => $message,
            'meta' => empty($meta) ? (object) [] : $meta,
        ];

        if ($data !== []) {
            $error['data'] = $data;
        }

        return response()->json(['error' => $error], $status);
    }

    private function status(GatewayApiException $e): int
    {
        if ($e->errorCode() === 'authorization_failed') {
            return 403;
        }

        return $e->errorCode() === 'schedule.name_collision' ? 409 : 422;
    }

    public function effect(): ActivityLogType
    {
        return ActivityLogType::Write;
    }

    public function type(): string
    {
        return 'api:POST /schedules';
    }
}
