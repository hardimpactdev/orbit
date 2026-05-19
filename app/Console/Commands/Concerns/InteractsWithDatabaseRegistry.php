<?php

declare(strict_types=1);

namespace App\Console\Commands\Concerns;

use App\Http\Gateway\GatewayApiException;
use App\Http\Gateway\GatewayConnector;
use App\Models\App;
use App\Models\Node;
use App\Models\Workspace;
use App\Services\DatabaseConnections\DatabaseConnectionRegistryFailure;
use App\Services\DatabaseConnections\DatabaseConnectionTargetResolver;
use Throwable;

trait InteractsWithDatabaseRegistry
{
    private function isGatewayCaller(): bool
    {
        return (bool) config('orbit.is_gateway', false);
    }

    private function wantsJson(): bool
    {
        return (bool) $this->option('json');
    }

    private function stringOption(string $name): ?string
    {
        $value = $this->option($name);

        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }

    private function stringArgument(string $name): ?string
    {
        $value = $this->argument($name);

        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  array<string, mixed>  $meta
     */
    private function respondSuccess(array $data, array $meta = []): int
    {
        if ($this->wantsJson()) {
            $this->line(json_encode([
                'success' => [
                    'data' => $data,
                    'meta' => $meta === [] ? (object) [] : $meta,
                ],
            ], JSON_THROW_ON_ERROR));
        }

        return self::SUCCESS;
    }

    /**
     * @param  array<string, mixed>  $meta
     */
    private function respondFailure(string $code, string $message, array $meta): int
    {
        if ($this->wantsJson()) {
            $this->line(json_encode([
                'error' => [
                    'code' => $code,
                    'message' => $message,
                    'meta' => $meta === [] ? (object) [] : $meta,
                ],
            ], JSON_THROW_ON_ERROR));

            return self::FAILURE;
        }

        $this->error($message);

        return self::FAILURE;
    }

    /**
     * @return array{0: ?App, 1: ?Workspace, 2: ?Node}|DatabaseConnectionRegistryFailure
     */
    private function resolveListFilters(): array|DatabaseConnectionRegistryFailure
    {
        $app = $this->stringOption('app');
        $workspace = $this->stringOption('workspace');
        $node = $this->stringOption('node');

        if ($app !== null && $workspace !== null) {
            return DatabaseConnectionRegistryFailure::validation('scope', null, 'Invalid scope: --app and --workspace cannot be combined.');
        }

        $resolver = app(DatabaseConnectionTargetResolver::class);
        $appModel = $app !== null ? $resolver->resolveApp($app) : null;
        $workspaceModel = $workspace !== null ? $resolver->resolveWorkspace($workspace) : null;
        $nodeModel = $node !== null ? $resolver->resolveNode($node) : null;

        if ($app !== null && $appModel === null) {
            return DatabaseConnectionRegistryFailure::validation('app', $app, "Invalid value for --app: '{$app}'.");
        }

        if ($workspace !== null && $workspaceModel === null) {
            return DatabaseConnectionRegistryFailure::validation('workspace', $workspace, "Invalid value for --workspace: '{$workspace}'.");
        }

        if ($node !== null && $nodeModel === null) {
            return DatabaseConnectionRegistryFailure::validation('node', $node, "Invalid value for --node: '{$node}'.");
        }

        return [$appModel, $workspaceModel, $nodeModel];
    }

    /**
     * @return array{0: string, 1: App|Workspace, 2: string}|DatabaseConnectionRegistryFailure
     */
    private function resolveTargetScope(): array|DatabaseConnectionRegistryFailure
    {
        $app = $this->stringOption('app');
        $workspace = $this->stringOption('workspace');
        $envPrefix = $this->stringOption('env-prefix') ?? 'DB';
        $resolver = app(DatabaseConnectionTargetResolver::class);

        if (($app === null && $workspace === null) || ($app !== null && $workspace !== null)) {
            return DatabaseConnectionRegistryFailure::validation('scope', null, 'Exactly one of --app or --workspace is required.');
        }

        if (! $resolver->validEnvPrefix($envPrefix)) {
            return DatabaseConnectionRegistryFailure::validation('env_prefix', $envPrefix, 'Environment prefix must start with a letter and use only uppercase letters, digits, or underscores.');
        }

        if ($app !== null) {
            $appModel = $resolver->resolveApp($app);

            if (! $appModel instanceof App) {
                return DatabaseConnectionRegistryFailure::validation('app', $app, "Invalid value for --app: '{$app}'.");
            }

            return ['app', $appModel, $envPrefix];
        }

        $workspaceModel = $resolver->resolveWorkspace($workspace);

        if (! $workspaceModel instanceof Workspace) {
            return DatabaseConnectionRegistryFailure::validation('workspace', $workspace, "Invalid value for --workspace: '{$workspace}'.");
        }

        return ['workspace', $workspaceModel, $envPrefix];
    }

    /**
     * @return array<string, mixed>|GatewayApiException
     */
    private function sendGatewayRequest(object $request): array|GatewayApiException
    {
        try {
            $dto = app(GatewayConnector::class)->send($request)->dto();
        } catch (GatewayApiException $exception) {
            return $exception;
        } catch (Throwable) {
            return new GatewayApiException(
                message: 'Gateway connection is required to manage database connections.',
                errorCode: 'gateway_unavailable',
                errorMeta: [],
            );
        }

        return match (true) {
            property_exists($dto, 'connections') => ['connections' => $dto->connections, 'count' => $dto->count],
            property_exists($dto, 'connection') => ['connection' => $dto->connection],
            property_exists($dto, 'result') => ['result' => $dto->result],
            default => [],
        };
    }
}
