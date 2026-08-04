<?php

declare(strict_types=1);

namespace App\Support\OpenApi;

use App\Enums\Processes\ProcessRuntimeStatus;
use App\Enums\ProcessEventType;
use Dedoc\Scramble\Scramble;
use Dedoc\Scramble\Support\Generator\OpenApi;
use Dedoc\Scramble\Support\Generator\Parameter;
use Dedoc\Scramble\Support\Generator\RequestBodyObject;
use Dedoc\Scramble\Support\Generator\Response;
use Dedoc\Scramble\Support\Generator\Schema;
use Dedoc\Scramble\Support\Generator\SecurityScheme;
use Dedoc\Scramble\Support\Generator\Types\ArrayType;
use Dedoc\Scramble\Support\Generator\Types\IntegerType;
use Dedoc\Scramble\Support\Generator\Types\MixedType;
use Dedoc\Scramble\Support\Generator\Types\ObjectType;
use Dedoc\Scramble\Support\Generator\Types\StringType;
use Dedoc\Scramble\Support\Generator\Types\Type;
use UnexpectedValueException;

/** @mago-expect lint:cyclomatic-complexity */
/** @mago-expect lint:too-many-methods */
/** @mago-expect lint:kan-defect */
final class GatewayOpenApi
{
    /**
     * @var array<string, string>
     */
    private const array OperationIds = [
        'post tools/{tool}/start' => 'toolStart',
        'post tools/{tool}/stop' => 'toolStop',
        'post tools/{tool}/restart' => 'toolRestart',
        'post tools/{tool}/reload' => 'toolReload',
        'get tools/{tool}/logs' => 'toolLogs',
        'get processes/stream' => 'processesStream',
    ];

    public static function register(): void
    {
        if (! class_exists(Scramble::class)) {
            return;
        }

        Scramble::afterOpenApiGenerated(static function (OpenApi $openApi): void {
            self::describeGateway($openApi);
            self::addSecuritySchemes($openApi);
            self::addEnvelopeSchemas($openApi);
            self::documentProcessContracts($openApi);
            self::stabilizeOperationIds($openApi);
        });
    }

    private static function describeGateway(OpenApi $openApi): void
    {
        $openApi->info->title = 'Orbit Gateway API';
        $openApi->info->setVersion((string) config('app.version'));
        $openApi->info->setDescription(
            'HTTP contract exposed by an Orbit gateway for local nodes, agents, apps, workspaces, processes, and control-plane operations.',
        );
    }

    private static function addSecuritySchemes(OpenApi $openApi): void
    {
        /** @var SecurityScheme $securityScheme */
        $securityScheme = SecurityScheme::apiKey('header', 'X-Orbit-WireGuard-Ip');

        $securityScheme->as('orbitWireGuardIdentity');
        $securityScheme->setDescription(
            'Not a client-supplied credential. CLI/SDK/browser callers authenticate via the actual WireGuard peer source IP and never send this header. When the private gateway proxy hop is trusted, the Orbit gateway proxy may inject this header from the observed peer address after stripping any client copy.',
        );

        $openApi->components->addSecurityScheme(
            'orbitWireGuardIdentity',
            $securityScheme,
        );
    }

    private static function addEnvelopeSchemas(OpenApi $openApi): void
    {
        $openApi->components->addSchema(
            'OrbitSuccessEnvelope',
            self::schemaFrom(self::successEnvelope()),
        );

        $openApi->components->addSchema(
            'OrbitErrorEnvelope',
            self::schemaFrom(self::errorEnvelope()),
        );
    }

    private static function schemaFrom(Type $type): Schema
    {
        /** @var Schema $schema */
        $schema = Schema::fromType($type);

        return $schema;
    }

    private static function successEnvelope(): ObjectType
    {
        $meta = new ObjectType;
        $meta->additionalProperties(new MixedType);

        $envelope = new ObjectType;
        $envelope->addProperty('data', new MixedType);
        $envelope->addProperty('meta', $meta);
        $envelope->setRequired(['data']);

        return $envelope;
    }

    private static function errorEnvelope(): ObjectType
    {
        $meta = new ObjectType;
        $meta->additionalProperties(new MixedType);

        $error = new ObjectType;
        $error->addProperty('code', new StringType);
        $error->addProperty('message', new StringType);
        $error->addProperty('meta', $meta);
        $error->setRequired(['code', 'message', 'meta']);

        $envelope = new ObjectType;
        $envelope->addProperty('error', $error);
        $envelope->setRequired(['error']);

        return $envelope;
    }

    private static function stabilizeOperationIds(OpenApi $openApi): void
    {
        foreach ($openApi->paths as $path) {
            foreach ($path->operations as $method => $operation) {
                $operationId = self::OperationIds["{$method} {$path->path}"] ?? null;

                if ($operationId !== null) {
                    $operation->setOperationId($operationId);
                }
            }
        }
    }

    private static function documentProcessContracts(OpenApi $openApi): void
    {
        foreach ($openApi->paths as $path) {
            if ($path->path === 'processes') {
                foreach ($path->operations as $method => $operation) {
                    if ($method === 'get') {
                        $operation->parameters = [];
                        $operation->addParameters([
                            self::stringQueryParameter(
                                'app',
                                'Strict app-instance or workspace hostname (no scheme/path/port) resolved via exact proxy_routes.domain. Mutually exclusive with node, instance, and workspace.',
                            ),
                            self::stringQueryParameter('node', 'Filter processes by node name.'),
                            self::stringQueryParameter('instance', 'Filter processes by project.instance selector.'),
                            self::stringQueryParameter('workspace', 'Filter processes by workspace name.'),
                        ]);
                        $operation->addResponse(self::processListSuccessResponse());
                    }

                    if ($method === 'options') {
                        unset($path->operations[$method]);
                    }
                }

                continue;
            }

            if ($path->path === 'processes/stream') {
                foreach ($path->operations as $method => $operation) {
                    if ($method === 'options') {
                        unset($path->operations[$method]);

                        continue;
                    }

                    if ($method !== 'get') {
                        continue;
                    }

                    $operation->parameters = [];
                    $operation->addParameters([
                        self::stringQueryParameter(
                            name: 'app',
                            description: 'Required strict app-instance or workspace hostname. Only browser/stream selector; url and other process selectors are rejected.',
                            required: true,
                        ),
                    ]);
                    $operation->description(
                        'Server-sent process lifecycle stream for browser toolbars. Every connect emits a fresh authoritative snapshot at a durable high-water mark (SSE id, 0 when no events), then ordered process_events after that mark. Last-Event-ID is accepted for native EventSource reconnect and never replays history after the snapshot. Auth matches process list (WireGuard peer + process:read). X-Orbit-Client is never required.',
                    );
                    $operation->addResponse(self::processStreamSuccessResponse());
                }

                continue;
            }

            if (! in_array($path->path, ['processes/start', 'processes/stop', 'processes/restart'], true)) {
                continue;
            }

            foreach ($path->operations as $method => $operation) {
                if ($method === 'options') {
                    unset($path->operations[$method]);

                    continue;
                }

                if ($method !== 'post') {
                    continue;
                }

                $requestBody = new RequestBodyObject;
                $requestBody->description('Process lifecycle target selectors and optional process name.');
                $requestBody->setContent(
                    'application/json',
                    self::schemaFrom(self::processLifecycleRequestBody()),
                );
                $operation->addRequestBodyObject($requestBody);
                $operation->addResponse(
                    $path->path === 'processes/restart'
                        ? self::processRestartSuccessResponse()
                        : self::processLifecycleSuccessResponse(),
                );
            }
        }
    }

    private static function processLifecycleRequestBody(): ObjectType
    {
        $body = new ObjectType;
        $app = new StringType;
        $app->setDescription(
            'Strict hostname only (exact registered proxy-route domain; no scheme, path, or port). Mutually exclusive with node, instance, and workspace.',
        );
        $node = new StringType;
        $node->setDescription('Owning node name.');
        $instance = new StringType;
        $instance->setDescription('Project.instance selector.');
        $workspace = new StringType;
        $workspace->setDescription('Workspace name.');
        $name = new StringType;
        $name->setDescription('Optional process name. Omit to act on all processes in the context.');

        $body->addProperty('app', $app);
        $body->addProperty('node', $node);
        $body->addProperty('instance', $instance);
        $body->addProperty('workspace', $workspace);
        $body->addProperty('name', $name);

        return $body;
    }

    private static function processListSuccessResponse(): Response
    {
        $status = new StringType;
        $status->enum(ProcessRuntimeStatus::values());
        $status->setDescription(
            'Runtime status from the latest durable process lifecycle event: transitional starting/stopping/restarting, terminal running/stopped/crashed, or unknown (including failed lifecycle actions and no event yet).',
        );

        $lastEvent = new ObjectType;
        $lastEvent->addProperty('id', new IntegerType);
        $lastEvent->addProperty('type', self::processEventTypeString());
        $lastEvent->setRequired(['id', 'type']);
        $lastEvent->nullable(true);

        $process = new ObjectType;
        $process->addProperty('node', new StringType);
        $process->addProperty('project', new StringType()->nullable(true));
        $process->addProperty('instance', new StringType()->nullable(true));
        $process->addProperty('workspace', new StringType()->nullable(true));
        $process->addProperty('name', new StringType);
        $process->addProperty('command', new StringType()->nullable(true));
        $process->addProperty('restart_policy', new StringType);
        $process->addProperty('crash_notification', new StringType);
        $process->addProperty('runtime', new StringType);
        $process->addProperty('tool', new StringType()->nullable(true));
        $service = new ObjectType;
        $service->additionalProperties(new MixedType);
        $service->nullable(true);
        $process->addProperty('service', $service);
        $process->addProperty('runtime_unit', new StringType);
        $process->addProperty('status', $status);
        $process->addProperty('last_event', $lastEvent);
        $process->setRequired([
            'node',
            'project',
            'instance',
            'workspace',
            'name',
            'command',
            'restart_policy',
            'crash_notification',
            'runtime',
            'tool',
            'service',
            'runtime_unit',
            'status',
            'last_event',
        ]);

        $context = new ObjectType;
        $context->addProperty('node', new StringType);
        $context->addProperty('project', new StringType()->nullable(true));
        $context->addProperty('instance', new StringType()->nullable(true));
        $context->addProperty('workspace', new StringType()->nullable(true));
        $context->setRequired(['node', 'project', 'instance', 'workspace']);

        $data = new ObjectType;
        $data->addProperty('context', $context);
        $processes = new ArrayType;
        $processes->setItems($process);
        $data->addProperty('processes', $processes);
        $data->setRequired(['context', 'processes']);

        $meta = new ObjectType;
        $meta->additionalProperties(new MixedType);

        $success = new ObjectType;
        $success->addProperty('data', $data);
        $success->addProperty('meta', $meta);
        $success->setRequired(['data', 'meta']);

        $envelope = new ObjectType;
        $envelope->addProperty('success', $success);
        $envelope->setRequired(['success']);

        /** @var Response $response */
        $response = Response::make(200);
        $response->setDescription(
            'Process definitions with concrete status for a node, instance, workspace, or app hostname context.',
        );
        $response->setContent('application/json', self::schemaFrom($envelope));

        return $response;
    }

    private static function processLifecycleSuccessResponse(): Response
    {
        $event = self::processEventObject();
        $events = new ArrayType;
        $events->setItems(self::processEventObject());

        $runtime = self::processLifecycleRuntimeObject();
        $runtime->addProperty('event', $event);
        $runtime->addProperty('events', $events);
        $runtime->setRequired([
            'process',
            'node',
            'project',
            'instance',
            'workspace',
            'runtime_unit',
            'state',
            'event',
            'events',
        ]);

        return self::processLifecycleEnvelopeResponse(
            runtime: $runtime,
            description: 'Process start/stop result with ordered durable events (transitional then terminal, including failed→unknown).',
        );
    }

    private static function processRestartSuccessResponse(): Response
    {
        $events = new ArrayType;
        $events->setItems(self::processEventObject());

        $runtime = self::processLifecycleRuntimeObject();
        $runtime->addProperty('events', $events);
        // Restart always returns ordered durable events (restarting then started/failed).
        $runtime->setRequired([
            'process',
            'node',
            'project',
            'instance',
            'workspace',
            'runtime_unit',
            'state',
            'events',
        ]);

        return self::processLifecycleEnvelopeResponse(
            runtime: $runtime,
            description: 'Process restart result with ordered durable events (restarting then started/failed).',
        );
    }

    private static function processStreamSuccessResponse(): Response
    {
        /** @var Response $response */
        $response = Response::make(200);
        $response->setDescription(
            'text/event-stream of process lifecycle frames: snapshot (SSE id = high-water mark, 0 when none), ordered update events, optional SSE comment heartbeats, and terminal error frames.',
        );
        $response->setContent('text/event-stream', self::schemaFrom(new StringType));

        return $response;
    }

    private static function processEventObject(): ObjectType
    {
        $event = new ObjectType;
        $event->addProperty('id', new IntegerType);
        $event->addProperty('type', self::processEventTypeString());
        $event->setRequired(['id', 'type']);

        return $event;
    }

    private static function processEventTypeString(): StringType
    {
        $type = new StringType;
        $type->enum(ProcessEventType::values());
        $type->setDescription(
            'Durable process lifecycle event type: starting, started, stopping, stopped, restarting, crashed, or failed.',
        );

        return $type;
    }

    private static function processLifecycleRuntimeObject(): ObjectType
    {
        $runtime = new ObjectType;
        $runtime->addProperty('process', new StringType);
        $runtime->addProperty('node', new StringType);
        $runtime->addProperty('project', new StringType()->nullable(true));
        $runtime->addProperty('instance', new StringType()->nullable(true));
        $runtime->addProperty('workspace', new StringType()->nullable(true));
        $runtime->addProperty('runtime_unit', new StringType);
        $runtime->addProperty('state', new StringType);

        return $runtime;
    }

    private static function processLifecycleEnvelopeResponse(ObjectType $runtime, string $description): Response
    {
        $data = new ObjectType;
        $runtimes = new ArrayType;
        $runtimes->setItems($runtime);
        $data->addProperty('runtimes', $runtimes);
        $data->setRequired(['runtimes']);

        $meta = new ObjectType;
        $meta->additionalProperties(new MixedType);

        $success = new ObjectType;
        $success->addProperty('data', $data);
        $success->addProperty('meta', $meta);
        $success->setRequired(['data', 'meta']);

        $envelope = new ObjectType;
        $envelope->addProperty('success', $success);
        $envelope->setRequired(['success']);

        /** @var Response $response */
        $response = Response::make(200);
        $response->setDescription($description);
        $response->setContent('application/json', self::schemaFrom($envelope));

        return $response;
    }

    private static function stringQueryParameter(
        string $name,
        string $description,
        bool $required = false,
    ): Parameter {
        /** @var Parameter $parameter */
        $parameter = Parameter::make($name, 'query');

        $parameter->setSchema(self::schemaFrom(new StringType));
        $parameter->description($description);
        $parameter->required($required);

        return $parameter;
    }
}
