<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Actions\Processes\EditProcess;
use App\Contracts\Loggable;
use App\Enums\ActivityLogType;
use App\Enums\ProcessCrashNotification;
use App\Enums\Processes\ProcessRuntime;
use App\Enums\ProcessRestartPolicy;
use App\Http\Authorization\RequiresPermission;
use App\Http\Authorization\ServingNode;
use App\Models\Node;
use App\Services\Nodes\Access\NodeAccessAuthorizer;
use App\Services\Processes\ProcessOwnerContextResolver;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Orbit\Sdk\Laravel\GatewayApiException;

/**
 * @mago-expect lint:kan-defect
 */
#[RequiresPermission('process:update', servingNode: ServingNode::AppOwning)]
final class ProcessUpdateController implements Loggable
{
    private ?Model $activitySubject = null;

    public function __construct(
        private readonly NodeAccessAuthorizer $authorizer,
        private readonly ProcessOwnerContextResolver $contexts,
    ) {}

    public function __invoke(string $name, Request $request, EditProcess $editProcess): JsonResponse
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

        try {
            $context = $this->contexts->resolve(
                nodeName: $input['node'],
                appName: $input['instance'],
                workspaceName: $input['workspace'],
            );
        } catch (GatewayApiException $e) {
            return $this->error(
                $e->errorCode() ?? 'validation_failed',
                $e->getMessage(),
                $e->errorMeta(),
                $this->statusFor($e),
            );
        }

        $authorization = $this->authorizeProcessAccess($caller, $context->node, 'process:update');

        if ($authorization instanceof JsonResponse) {
            return $authorization;
        }

        try {
            $result = $editProcess->handle(
                context: $context,
                name: $name,
                changes: $input['changes'],
                restart: $input['restart'],
                consumer: $caller,
            );
        } catch (GatewayApiException $e) {
            return $this->error(
                $e->errorCode() ?? 'validation_failed',
                $e->getMessage(),
                $e->errorMeta(),
                $this->statusFor($e),
            );
        }

        $this->activitySubject = $context->subject();

        return response()->json([
            'success' => [
                'data' => $result['data'],
                'meta' => [
                    'warnings' => $result['warnings'],
                ],
            ],
        ]);
    }

    /**
     * @return array{node: string|null, instance: string|null, workspace: string|null, changes: array{name?: string, label?: string, command?: string, restart_policy?: ProcessRestartPolicy, crash_notification?: ProcessCrashNotification, runtime?: ProcessRuntime, binds?: list<string>}, restart: bool}|JsonResponse
     */
    private function validatedInput(Request $request): array|JsonResponse
    {
        $node = $this->optionalString($request, 'node');
        $app = $this->optionalString($request, 'instance');
        $workspace = $this->optionalString($request, 'workspace');
        $newName = $this->optionalString($request, 'name');
        $label = $this->validatedLabel($request);
        $command = $this->optionalString($request, 'command');
        $restartPolicyInput = $this->optionalString($request, 'restart_policy');
        $crashNotificationInput = $this->optionalString($request, 'crash_notification');
        $runtimeInput = $this->optionalString($request, 'runtime');
        $binds = $this->optionalBinds($request);

        if ($binds instanceof JsonResponse) {
            return $binds;
        }

        if ($label instanceof JsonResponse) {
            return $label;
        }

        if ($node !== null && ($app !== null || $workspace !== null)) {
            return $this->error(
                'validation_failed',
                'A node context cannot be combined with instance or workspace context.',
                [
                    'field' => 'context',
                    'node' => $node,
                    'instance' => $app,
                    'workspace' => $workspace,
                ],
                422,
            );
        }

        if ($node === null && $app === null && $workspace === null) {
            return $this->error(
                'validation_failed',
                'A node, instance, or workspace context is required.',
                ['field' => 'instance'],
                422,
            );
        }

        if (
            $newName === null
            && $label === null
            && $command === null
            && $restartPolicyInput === null
            && $crashNotificationInput === null
            && $runtimeInput === null
            && $binds === null
        ) {
            return $this->error(
                'validation_failed',
                'At least one editable field is required.',
                ['field' => 'editable_fields'],
                422,
            );
        }

        $changes = [];

        if ($newName !== null) {
            if (! preg_match('/^[a-z0-9](?:[a-z0-9-]{0,62}[a-z0-9])?$/', $newName)) {
                return $this->error(
                    'validation_failed',
                    'The process name must contain only lowercase letters, digits, and hyphens, cannot start or end with a hyphen, and may not exceed 64 characters.',
                    [
                        'field' => 'name',
                        'value' => $newName,
                    ],
                    422,
                );
            }

            $changes['name'] = $newName;
        }

        if ($label !== null) {
            $changes['label'] = $label;
        }

        if ($command !== null) {
            $changes['command'] = $command;
        }

        if ($restartPolicyInput !== null) {
            $restartPolicy = ProcessRestartPolicy::tryFrom($restartPolicyInput);

            if (! $restartPolicy instanceof ProcessRestartPolicy) {
                return $this->error(
                    'validation_failed',
                    'Invalid restart policy.',
                    [
                        'field' => 'restart_policy',
                        'value' => $restartPolicyInput,
                        'allowed' => array_column(ProcessRestartPolicy::cases(), 'value'),
                    ],
                    422,
                );
            }

            $changes['restart_policy'] = $restartPolicy;
        }

        if ($crashNotificationInput !== null) {
            $crashNotification = ProcessCrashNotification::tryFrom($crashNotificationInput);

            if (! $crashNotification instanceof ProcessCrashNotification) {
                return $this->error(
                    'validation_failed',
                    'Invalid crash notification policy.',
                    [
                        'field' => 'crash_notification',
                        'value' => $crashNotificationInput,
                        'allowed' => array_column(ProcessCrashNotification::cases(), 'value'),
                    ],
                    422,
                );
            }

            $changes['crash_notification'] = $crashNotification;
        }

        if ($runtimeInput !== null) {
            $runtime = ProcessRuntime::tryFrom($runtimeInput);

            if (! $runtime instanceof ProcessRuntime) {
                return $this->error(
                    'validation_failed',
                    'Invalid process runtime.',
                    [
                        'field' => 'runtime',
                        'value' => $runtimeInput,
                        'allowed' => array_column(ProcessRuntime::cases(), 'value'),
                    ],
                    422,
                );
            }

            if ($node === null && $runtime->appWorkspaceCommandViolationReason() !== null) {
                return $this->error(
                    'validation_failed',
                    $runtime->appWorkspaceCommandViolationMessage()
                    ?? 'The selected runtime is not valid for this process owner.',
                    [
                        'field' => 'runtime',
                        'value' => $runtimeInput,
                        'reason' => $runtime->appWorkspaceCommandViolationReason(),
                    ],
                    422,
                );
            }

            $changes['runtime'] = $runtime;
        }

        if ($binds !== null) {
            $changes['binds'] = $binds;
        }

        return [
            'node' => $node,
            'instance' => $app,
            'workspace' => $workspace,
            'changes' => $changes,
            'restart' => $request->boolean('restart'),
        ];
    }

    /**
     * @return list<string>|null|JsonResponse
     */
    private function optionalBinds(Request $request): array|JsonResponse|null
    {
        if (! $request->exists('binds')) {
            return null;
        }

        $value = $request->input('binds');

        if (! is_array($value)) {
            return $this->error(
                'validation_failed',
                'Publish binds must be a list of wireguard or loopback selectors.',
                [
                    'field' => 'bind',
                    'reason' => 'invalid_type',
                    'allowed' => ['wireguard', 'loopback'],
                ],
                422,
            );
        }

        $binds = [];

        foreach ($value as $item) {
            $binds[] = is_string($item) ? trim($item) : '';
        }

        return $binds;
    }

    private function authorizeProcessAccess(Node $caller, Node $node, string $permission): ?JsonResponse
    {
        $result = $this->authorizer->authorize($caller, $node, $permission);

        if ($result->allowed) {
            return null;
        }

        return $this->error(
            'authorization_failed',
            "This node is not authorized for '{$permission}' on '{$node->name}'.",
            [
                'reason' => $result->reason,
                'missing_permission' => $result->missingPermission,
                'serving_node' => $node->name,
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
     * Optional process display label for updates. Omitted returns null.
     * Present but empty/whitespace or over 255 fails validation.
     */
    private function validatedLabel(Request $request): string|JsonResponse|null
    {
        if (! $request->exists('label')) {
            return null;
        }

        $value = $request->input('label');

        if (! is_string($value)) {
            return $this->error(
                'validation_failed',
                'The process label must be a non-empty string.',
                ['field' => 'label'],
                422,
            );
        }

        $label = trim($value);

        if ($label === '') {
            return $this->error(
                'validation_failed',
                'The process label must be a non-empty string.',
                ['field' => 'label'],
                422,
            );
        }

        if (mb_strlen($label) > 255) {
            return $this->error(
                'validation_failed',
                'The process label may not be greater than 255 characters.',
                ['field' => 'label', 'max' => 255],
                422,
            );
        }

        return $label;
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
                'meta' => empty($meta) ? (object) [] : $meta,
            ],
        ], $status);
    }

    private function statusFor(GatewayApiException $exception): int
    {
        return match ($exception->errorCode()) {
            'process.not_found' => 404,
            'process.name_conflict' => 409,
            'authorization_failed' => 403,
            default => 422,
        };
    }

    public function effect(): ActivityLogType
    {
        return ActivityLogType::Write;
    }

    public function type(): string
    {
        return 'api:PATCH /processes/{name}';
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
            'node' => $this->optionalString(request(), 'node'),
            'instance' => $this->optionalString(request(), 'instance'),
            'workspace' => $this->optionalString(request(), 'workspace'),
            'new_name' => $this->optionalString(request(), 'name'),
        ];
    }

    public function description(): ?string
    {
        return null;
    }
}
