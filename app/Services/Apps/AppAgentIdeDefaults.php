<?php

declare(strict_types=1);

namespace App\Services\Apps;

use App\Models\App;
use App\Services\Nodes\NodeAgentIdeDefaults;

final readonly class AppAgentIdeDefaults
{
    /**
     * @var list<string>
     */
    public const array SUPPORTED_ADAPTERS = ['opencode', 'polyscope'];

    /**
     * @return array{
     *     app: array<string, mixed>,
     *     agent_ide: array{adapter: string|null, source: string, effective_adapter: string|null},
     *     cleanup: array{workspaces_removed: list<string>},
     *     action: string
     * }
     */
    public function set(App $app, string $adapter): array
    {
        $app->loadMissing('node');

        $currentAdapter = $this->explicitAdapter($app);
        $normalizedAdapter = $adapter === 'inherit' ? null : $adapter;
        $action = $currentAdapter === $normalizedAdapter ? 'converged' : 'set';

        if ($action === 'set') {
            $app->agent_ide_config = $normalizedAdapter === null
                ? null
                : ['adapter' => $normalizedAdapter];
            $app->save();
            $app->refresh();
            $app->loadMissing('node');
        }

        return [
            'app' => $this->appPayload($app),
            'agent_ide' => $this->payloadFor($app),
            'cleanup' => ['workspaces_removed' => []],
            'action' => $action,
        ];
    }

    public function isSupported(string $adapter): bool
    {
        return in_array($adapter, ['inherit', 'none', ...self::SUPPORTED_ADAPTERS], true);
    }

    /**
     * @return list<string>
     */
    public function supportedAdapters(): array
    {
        return ['inherit', 'none', ...self::SUPPORTED_ADAPTERS];
    }

    /**
     * @return array{adapter: string|null, source: string, effective_adapter: string|null}
     */
    public function payloadFor(App $app): array
    {
        $explicitAdapter = $this->explicitAdapter($app);

        if ($explicitAdapter === 'none') {
            return [
                'adapter' => 'none',
                'source' => 'app',
                'effective_adapter' => null,
            ];
        }

        if ($explicitAdapter !== null) {
            return [
                'adapter' => $explicitAdapter,
                'source' => 'app',
                'effective_adapter' => $explicitAdapter,
            ];
        }

        $app->loadMissing('node');

        if ($app->node !== null) {
            $nodeDefault = NodeAgentIdeDefaults::payloadFor($app->node);
            $nodeAdapter = $nodeDefault['adapter'];

            if ($nodeAdapter !== null) {
                return [
                    'adapter' => null,
                    'source' => 'node',
                    'effective_adapter' => $nodeAdapter,
                ];
            }
        }

        return [
            'adapter' => null,
            'source' => 'default',
            'effective_adapter' => null,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function appPayload(App $app): array
    {
        return [
            'name' => $app->name,
            'node' => $app->node?->name,
            'environment' => $app->environment,
            'url' => $app->url(),
            'path' => $app->path,
            'root' => $app->document_root,
            'repository' => $app->repository,
            'php_version' => $app->php_version,
            'adopted' => $app->adopted,
        ];
    }

    private function explicitAdapter(App $app): ?string
    {
        $adapter = $app->agent_ide_config['adapter'] ?? null;

        return is_string($adapter) && $adapter !== '' ? $adapter : null;
    }
}
