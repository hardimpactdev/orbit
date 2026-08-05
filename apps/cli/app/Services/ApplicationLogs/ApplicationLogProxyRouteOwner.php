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

        if (
            is_string($ownerName)
            && $ownerName !== ''
            && ($ownerType === 'instance'
            || str_contains($ownerName, '.'))
        ) {
            return $this->instanceTarget($ownerName);
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
        $owner = is_array($route['owner'] ?? null) ? $route['owner'] : [];
        $target = is_array($route['target'] ?? null) ? $route['target'] : [];

        return [
            $owner['type'] ?? $target['type'] ?? null,
            $owner['name'] ?? $target['value'] ?? null,
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
