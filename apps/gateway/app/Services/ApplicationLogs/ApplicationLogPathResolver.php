<?php

declare(strict_types=1);

namespace App\Services\ApplicationLogs;

use App\Models\App;
use App\Models\Instance;
use App\Models\Workspace;
use App\Services\Workspaces\WorkspacePlacement;
use Orbit\Sdk\Laravel\GatewayApiException;

final readonly class ApplicationLogPathResolver
{
    public const string LogicalPath = 'storage/logs/laravel.log';

    public function __construct(
        private WorkspacePlacement $placement,
    ) {}

    /**
     * @return array{authorized_root: string, absolute_path: string, logical_path: string}
     */
    public function forInstance(App $app, Instance $instance): array
    {
        $node = $this->placement->nodeForInstance($instance);

        if ($node === null) {
            throw new GatewayApiException(
                'The instance serving node could not be resolved.',
                'validation_failed',
                ['field' => 'instance', 'instance' => "{$app->name}.{$instance->name}"],
            );
        }

        $root = $this->hostApplicationRoot(
            $this->placement->runtimePath($app, $instance),
            $this->placement->runtimeDocumentRoot($app, $instance),
        );

        return $this->paths($root);
    }

    /**
     * @return array{authorized_root: string, absolute_path: string, logical_path: string}
     */
    public function forWorkspace(Workspace $workspace): array
    {
        $root = rtrim($workspace->path, characters: '/');

        if ($root === '') {
            throw new GatewayApiException(
                'The workspace path could not be resolved.',
                'validation_failed',
                ['field' => 'workspace', 'workspace' => $workspace->name],
            );
        }

        return $this->paths($root);
    }

    /**
     * Host-side mirror of AppRuntimeContainerRenderer::applicationRootInContainer().
     */
    public function hostApplicationRoot(string $path, string $documentRoot): string
    {
        $base = rtrim($path, characters: '/');
        $documentRoot = trim($documentRoot, characters: '/');

        if ($documentRoot === 'live' || str_starts_with($documentRoot, 'live/')) {
            return $base.'/live';
        }

        return $base;
    }

    /**
     * @return array{authorized_root: string, absolute_path: string, logical_path: string}
     */
    private function paths(string $authorizedRoot): array
    {
        $root = rtrim($authorizedRoot, characters: '/');

        if ($root === '' || ! str_starts_with($root, '/')) {
            throw new GatewayApiException(
                'The authorized application root is invalid.',
                'application_log.unsafe_path',
                ['path' => self::LogicalPath],
            );
        }

        return [
            'authorized_root' => $root,
            'absolute_path' => $root.'/'.self::LogicalPath,
            'logical_path' => self::LogicalPath,
        ];
    }
}
