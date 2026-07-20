<?php

declare(strict_types=1);

namespace App\Support\OpenApi;

use Dedoc\Scramble\Scramble;
use Dedoc\Scramble\Support\Generator\OpenApi;
use Dedoc\Scramble\Support\Generator\Parameter;
use Dedoc\Scramble\Support\Generator\Schema;
use Dedoc\Scramble\Support\Generator\SecurityScheme;
use Dedoc\Scramble\Support\Generator\Types\MixedType;
use Dedoc\Scramble\Support\Generator\Types\ObjectType;
use Dedoc\Scramble\Support\Generator\Types\StringType;
use Dedoc\Scramble\Support\Generator\Types\Type;
use UnexpectedValueException;

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
            self::documentRawRequestQueryParameters($openApi);
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
            'Gateway-trusted WireGuard peer identity header injected by the Orbit gateway proxy.',
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

    private static function documentRawRequestQueryParameters(OpenApi $openApi): void
    {
        foreach ($openApi->paths as $path) {
            if ($path->path !== 'processes') {
                continue;
            }

            foreach ($path->operations as $method => $operation) {
                if ($method !== 'get') {
                    continue;
                }

                $operation->addParameters([
                    self::stringQueryParameter('node', 'Filter processes by node name.'),
                    self::stringQueryParameter('instance', 'Filter processes by project.instance selector.'),
                    self::stringQueryParameter('workspace', 'Filter processes by workspace name.'),
                ]);
            }
        }
    }

    private static function stringQueryParameter(string $name, string $description): Parameter
    {
        /** @var Parameter $parameter */
        $parameter = Parameter::make($name, 'query');

        $parameter->setSchema(self::schemaFrom(new StringType));
        $parameter->description($description);

        return $parameter;
    }
}
