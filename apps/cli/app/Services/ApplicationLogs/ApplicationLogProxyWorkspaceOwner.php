<?php

declare(strict_types=1);

namespace App\Services\ApplicationLogs;

/**
 * Resolves workspace-owned proxy routes into log targets.
 */
final readonly class ApplicationLogProxyWorkspaceOwner
{
    public function __construct(
        private ApplicationLogInstanceSelector $instanceSelector = new ApplicationLogInstanceSelector,
    ) {}

    /**
     * @param  array<string, mixed>  $route
     * @return array{
     *     ok: true,
     *     type: 'workspace',
     *     workspace: string,
     *     instance: string
     * }|array{
     *     ok: false,
     *     field: string,
     *     message: string,
     *     meta: array<string, mixed>
     * }
     */
    public function resolve(string $host, string $workspace, array $route): array
    {
        if (! $this->isCanonicalWorkspaceSlug($workspace)) {
            return [
                'ok' => false,
                'field' => 'target',
                'message' => 'The workspace proxy route did not include a canonical workspace slug.',
                'meta' => ['workspace' => $workspace, 'host' => $host],
            ];
        }

        // Parent app.instance is route-entity authority (ProxyRouteQuery FK enrichment).
        $instance = is_string($route['instance'] ?? null) ? $route['instance'] : null;
        $selector = $instance !== null ? $this->instanceSelector->parse($instance) : ['ok' => false];

        if (! $selector['ok']) {
            return [
                'ok' => false,
                'field' => 'instance',
                'message' => 'The workspace proxy route did not include a parent instance selector.',
                'meta' => ['workspace' => $workspace, 'host' => $host],
            ];
        }

        return [
            'ok' => true,
            'type' => 'workspace',
            'workspace' => $workspace,
            'instance' => $selector['selector'],
        ];
    }

    private function isCanonicalWorkspaceSlug(string $workspace): bool
    {
        return preg_match('/\A(?!main\z)[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\z/', $workspace) === 1;
    }
}
