<?php

declare(strict_types=1);

namespace App\Services\ApplicationLogs;

/**
 * Resolves an exact proxy-route match into an instance or workspace log target.
 */
final readonly class ApplicationLogProxyRouteOwner
{
    public function __construct(
        private ApplicationLogProxyWorkspaceOwner $workspace = new ApplicationLogProxyWorkspaceOwner,
        private ApplicationLogInstanceSelector $instanceSelector = new ApplicationLogInstanceSelector,
    ) {}

    /**
     * @param  array<string, mixed>  $route
     * @return array{
     *     ok: true,
     *     type: 'instance',
     *     selector: string
     * }|array{
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
    public function resolve(string $host, array $route): array
    {
        [$ownerType, $ownerName] = $this->ownerIdentity($route);

        if ($ownerType === 'workspace' && is_string($ownerName) && $ownerName !== '') {
            return $this->workspace->resolve($host, $ownerName, $route);
        }

        if ($ownerType === 'instance' && is_string($ownerName)) {
            $selector = $this->instanceSelector->parse($ownerName);

            if ($selector['ok']) {
                return $this->instanceTarget($selector['selector']);
            }
        }

        return [
            'ok' => false,
            'field' => 'target',
            'message' => "Host '{$host}' is not an instance or workspace proxy route.",
            'meta' => ['host' => $host],
        ];
    }

    /**
     * @param  array<string, mixed>  $route
     * @return array{0: mixed, 1: mixed}
     */
    private function ownerIdentity(array $route): array
    {
        if (array_key_exists('owner', $route)) {
            $owner = is_array($route['owner']) ? $route['owner'] : [];

            return [
                $owner['type'] ?? null,
                $owner['name'] ?? null,
            ];
        }

        $target = is_array($route['target'] ?? null) ? $route['target'] : [];

        return [
            $target['type'] ?? null,
            $target['value'] ?? null,
        ];
    }

    /**
     * @return array{ok: true, type: 'instance', selector: string}
     */
    private function instanceTarget(string $selector): array
    {
        return [
            'ok' => true,
            'type' => 'instance',
            'selector' => $selector,
        ];
    }
}
