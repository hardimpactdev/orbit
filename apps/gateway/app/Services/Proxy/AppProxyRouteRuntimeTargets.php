<?php

declare(strict_types=1);

namespace App\Services\Proxy;

use App\Models\App;
use App\Models\Instance;
use App\Services\Apps\AppDevelopmentInnerTlsPolicy;
use App\Services\Apps\AppRuntimeContainerRenderer;
use App\Services\Workspaces\WorkspacePlacement;

final readonly class AppProxyRouteRuntimeTargets
{
    public function __construct(
        private AppProxyRouteTargetResolver $resolver = new AppProxyRouteTargetResolver,
        private WorkspacePlacement $placement = new WorkspacePlacement,
    ) {}

    public function containerName(App $app, Instance $instance): string
    {
        $slug = "{$app->name}-{$instance->name}";

        return "orbit-app-{$slug}";
    }

    public function httpRuntimeUpstream(App $app, Instance $instance): string
    {
        return 'http://'.$this->containerName($app, $instance).':'.AppRuntimeContainerRenderer::InternalPort;
    }

    public function httpsRuntimeUpstream(App $app, Instance $instance): string
    {
        return 'https://'.$this->containerName($app, $instance).':'.AppDevelopmentInnerTlsPolicy::InternalTlsPort;
    }

    /**
     * @return array{id: int|null, name: string, selector: string, domain: string, node: ?string, node_id: ?int}
     */
    public function instanceConfig(App $app, Instance $instance, string $domain): array
    {
        $node = $this->placement->nodeForInstance($instance);

        return [
            'id' => $instance->id,
            'name' => $instance->name,
            'selector' => $this->resolver->selector($instance),
            'domain' => $domain,
            'node' => $node?->name,
            'node_id' => $node?->id,
        ];
    }
}
