<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Actions\Processes\AddProcess;
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

#[RequiresPermission('process:add', servingNode: ServingNode::AppOwning)]
final class ProcessStoreController implements Loggable
{
    private ?Model $activitySubject = null;

    public function __construct(
        private readonly ProcessOwnerContextResolver $contexts,
        private readonly NodeAccessAuthorizer $authorizer,
    ) {}

    public function __invoke(Request $request, AddProcess $addProcess): JsonResponse
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
            return $this->error($e->errorCode() ?? 'validation_failed', $e->getMessage(), $e->errorMeta(), 422);
        }

        $authorization = $this->authorizeProcessAccess($caller, $context->node, 'process:add');

        if ($authorization instanceof JsonResponse) {
            return $authorization;
        }

        try {
            $result = $addProcess->handle(
                context: $context,
                name: $input['name'],
                command: $input['command'],
                restartPolicy: $input['restart_policy'],
                crashNotification: $input['crash_notification'],
                start: $input['start'],
                runtime: $input['runtime'],
                tool: $input['tool'],
                service: $input['service'],
                version: $input['version'],
                image: $input['image'],
                serviceOptions: $input['service_options'],
                replaceContainers: $input['replace_containers'],
                consumer: $caller,
                label: $input['label'],
            );
        } catch (GatewayApiException $e) {
            return $this->error(
                $e->errorCode() ?? 'validation_failed',
                $e->getMessage(),
                $e->errorMeta(),
                $e->errorCode() === 'process.name_collision' ? 409 : 422,
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
     * @return array{node: string|null, instance: string|null, workspace: string|null, name: string, label: string|null, command: string|null, restart_policy: ProcessRestartPolicy, crash_notification: ProcessCrashNotification, runtime: ?ProcessRuntime, tool: string|null, service: string|null, version: string|null, image: string|null, service_options: array<string, mixed>, replace_containers: list<string>, start: bool}|JsonResponse
     */
    private function validatedInput(Request $request): array|JsonResponse
    {
        $node = $this->optionalString($request, 'node');
        $app = $this->optionalString($request, 'instance');
        $workspace = $this->optionalString($request, 'workspace');
        $name = $this->optionalString($request, 'name');
        $label = $this->validatedLabel($request);
        $command = $this->optionalString($request, 'command');

        if ($label instanceof JsonResponse) {
            return $label;
        }
        $restartPolicyInput = $this->optionalString($request, 'restart_policy') ?? ProcessRestartPolicy::Never->value;
        $crashNotificationInput =
            $this->optionalString($request, 'crash_notification') ?? ProcessCrashNotification::None->value;
        $runtimeInput = $this->optionalString($request, 'runtime');
        $tool = $this->optionalString($request, 'tool');
        $service = $this->optionalString($request, 'service');
        $version = $this->optionalString($request, 'version');
        $image = $this->optionalString($request, 'image');
        $serviceOptions = $request->input('service_options');
        $replaceContainers = $this->stringList($request, 'replace_containers');
        $noStart = $request->boolean('no_start');
        $startExplicit = $request->has('start') ? $request->boolean('start') : null;

        if ($replaceContainers instanceof JsonResponse) {
            return $replaceContainers;
        }

        if ($serviceOptions === null) {
            $serviceOptions = [];
        }

        if (! is_array($serviceOptions)) {
            return $this->error(
                'validation_failed',
                'Service options must be an object.',
                [
                    'field' => 'service_options',
                    'reason' => 'invalid_type',
                ],
                422,
            );
        }

        foreach (array_keys($serviceOptions) as $key) {
            if (! is_string($key)) {
                return $this->error(
                    'validation_failed',
                    'Service options must be an object with named fields.',
                    [
                        'field' => 'service_options',
                        'reason' => 'invalid_field_name',
                    ],
                    422,
                );
            }
        }

        /** @var array<string, mixed> $serviceOptions */

        if ($noStart && $startExplicit === true) {
            return $this->error(
                'validation_failed',
                'The start and no-start flags cannot be used together.',
                [
                    'field' => 'start',
                    'reason' => 'start_and_no_start_conflict',
                ],
                422,
            );
        }

        $start = $noStart ? false : $startExplicit ?? true;

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

        if ($name === null) {
            return $this->error('validation_failed', 'The process name is required.', ['field' => 'name'], 422);
        }

        if (! preg_match('/^[a-z0-9](?:[a-z0-9-]{0,62}[a-z0-9])?$/', $name)) {
            return $this->error(
                'validation_failed',
                'The process name must contain only lowercase letters, digits, and hyphens, cannot start or end with a hyphen, and may not exceed 64 characters.',
                ['field' => 'name', 'value' => $name],
                422,
            );
        }

        if ($command === null && $service === null) {
            return $this->error('validation_failed', 'The process command is required.', ['field' => 'command'], 422);
        }

        if ($service !== null && ! preg_match('/^[a-z0-9](?:[a-z0-9-]{0,62}[a-z0-9])?$/', $service)) {
            return $this->error(
                'validation_failed',
                'The managed service must contain only lowercase letters, digits, and hyphens, cannot start or end with a hyphen, and may not exceed 64 characters.',
                ['field' => 'service', 'value' => $service],
                422,
            );
        }

        if ($service === null && $version !== null) {
            return $this->error(
                'validation_failed',
                'Process service version requires a managed service.',
                [
                    'field' => 'version',
                    'value' => $version,
                    'reason' => 'process_service_version_requires_service',
                ],
                422,
            );
        }

        if ($service === null && $image !== null) {
            return $this->error(
                'validation_failed',
                'Process service image requires a managed service.',
                [
                    'field' => 'image',
                    'value' => $image,
                    'reason' => 'process_service_image_requires_service',
                ],
                422,
            );
        }

        if ($service !== null && $node === null) {
            return $this->error(
                'validation_failed',
                'Managed services are only valid for node-owned service processes.',
                [
                    'field' => 'service',
                    'value' => $service,
                    'reason' => 'process_service_requires_node_owned_process',
                ],
                422,
            );
        }

        if ($service !== null && $tool !== null) {
            return $this->error(
                'validation_failed',
                'Managed services do not use tool dependencies.',
                [
                    'field' => 'tool',
                    'value' => $tool,
                    'reason' => 'process_service_cannot_reference_tool',
                ],
                422,
            );
        }

        if ($tool !== null && ! preg_match('/^[a-z0-9](?:[a-z0-9-]{0,62}[a-z0-9])?$/', $tool)) {
            return $this->error(
                'validation_failed',
                'The process tool must contain only lowercase letters, digits, and hyphens, cannot start or end with a hyphen, and may not exceed 64 characters.',
                ['field' => 'tool', 'value' => $tool],
                422,
            );
        }

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

        $runtime = null;

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

            if ($node === null && $service === null && $runtime->appWorkspaceCommandViolationReason() !== null) {
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

            if ($image !== null && $runtime === ProcessRuntime::Systemd) {
                return $this->error(
                    'validation_failed',
                    'Process service image overrides require a Docker runtime.',
                    [
                        'field' => 'image',
                        'value' => $image,
                        'reason' => 'process_service_image_requires_docker_runtime',
                    ],
                    422,
                );
            }
        }

        if ($replaceContainers !== []) {
            if ($request->boolean('destructive_consent') !== true) {
                return $this->error(
                    'validation_failed',
                    'Use --force to remove replacement containers.',
                    [
                        'field' => 'force',
                        'reason' => 'destructive_consent_required',
                    ],
                    422,
                );
            }

            if ($node === null || $service === null || $runtime !== null && $runtime !== ProcessRuntime::Docker) {
                return $this->error(
                    'validation_failed',
                    'Replacement containers are only supported for node-owned Docker managed services.',
                    [
                        'field' => 'replace_containers',
                        'reason' => 'replace_container_requires_node_docker_service',
                    ],
                    422,
                );
            }
        }

        return [
            'node' => $node,
            'instance' => $app,
            'workspace' => $workspace,
            'name' => $name,
            'label' => $label,
            'command' => $command,
            'restart_policy' => $restartPolicy,
            'crash_notification' => $crashNotification,
            'runtime' => $runtime,
            'tool' => $tool,
            'service' => $service,
            'version' => $version,
            'image' => $image,
            'service_options' => $serviceOptions,
            'replace_containers' => $replaceContainers,
            'start' => $start,
        ];
    }

    /**
     * Optional process display label: omitted returns null (default to key on create).
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
     * @return list<string>|JsonResponse
     */
    private function stringList(Request $request, string $key): array|JsonResponse
    {
        $value = $request->input($key);

        if ($value === null) {
            return [];
        }

        if (! is_array($value)) {
            return $this->error(
                'validation_failed',
                'Replacement containers must be a list of Docker container names.',
                [
                    'field' => $key,
                ],
                422,
            );
        }

        $strings = [];

        foreach ($value as $item) {
            $container = is_string($item) ? trim($item) : '';

            if (! $this->isValidDockerContainerName($container)) {
                return $this->error(
                    'validation_failed',
                    'Replacement container names must be valid Docker container names.',
                    [
                        'field' => $key,
                        'value' => $container,
                    ],
                    422,
                );
            }

            $strings[] = $container;
        }

        return array_values(array_unique($strings));
    }

    private function isValidDockerContainerName(string $container): bool
    {
        return preg_match('/^[A-Za-z0-9][A-Za-z0-9_.-]{0,127}$/', $container) === 1;
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

    public function effect(): ActivityLogType
    {
        return ActivityLogType::Write;
    }

    public function type(): string
    {
        return 'api:POST /processes';
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
            'name' => $this->optionalString(request(), 'name'),
            'tool' => $this->optionalString(request(), 'tool'),
            'service' => $this->optionalString(request(), 'service'),
        ];
    }

    public function description(): ?string
    {
        return null;
    }
}
