<?php

declare(strict_types=1);

namespace App\Librarian;

final readonly class CommandCatalogGatewayPermissionIndex
{
    /**
     * @var list<array{class: string, action: string, permission: string}>
     */
    private const array KNOWN_INDIRECT_PERMISSION_CALLS = [
        [
            'class' => 'AppListController',
            'action' => '->visibleAppNodeIds(',
            'permission' => 'project:read',
        ],
        [
            'class' => 'ScheduleLogsPayload',
            'action' => '->forSchedule(',
            'permission' => 'schedule:read',
        ],
        [
            'class' => 'SchedulePayload',
            'action' => '->show(',
            'permission' => 'schedule:read',
        ],
        [
            'class' => 'ProxyRouteIntent',
            'action' => '->remove(',
            'permission' => 'proxy:remove',
        ],
        [
            'class' => 'FirewallRuleIntent',
            'action' => '->remove(',
            'permission' => 'firewall_rule:write',
        ],
    ];

    public function __construct(
        private CommandCatalogRepositoryPaths $paths,
        private CommandCatalogPhpMethodSourceExtractor $methods,
    ) {}

    /**
     * @return list<string>
     */
    public function authorizationPermissions(string $controllerPath, string $action): array
    {
        $source = file_get_contents($this->paths->absolutePath($controllerPath));

        if ($source === false) {
            return [];
        }

        $classHeaderSource = $this->methods->classHeaderSource($source);
        $actionSource = $this->methods->methodSource($source, $action) ?? '';

        $permissions = [
            ...$this->attributePermissions($classHeaderSource),
            ...$this->permissionStrings($classHeaderSource),
            ...$this->attributePermissions($actionSource),
            ...$this->permissionStrings($actionSource),
            ...$this->knownIndirectPermissionStrings($source, $actionSource),
        ];
        $permissions = array_values(array_unique($permissions));
        sort($permissions);

        return $permissions;
    }

    /**
     * @return list<string>
     */
    private function attributePermissions(string $source): array
    {
        $matches = [];
        preg_match_all('/RequiresPermission\s*\(\s*[\'"]([^\'"]+)[\'"]/', $source, $matches);

        return array_values($matches[1] ?? []);
    }

    /**
     * @return list<string>
     */
    private function permissionStrings(string $source): array
    {
        $permissions = [];

        foreach (explode("\n", $source) as $line) {
            if (! preg_match('/permission|authorize|authorizer|visibleToolNodeIds|allows/i', $line)) {
                continue;
            }

            $matches = [];
            preg_match_all('/[\'"]([a-z][a-z0-9-]*(?::[a-z][a-z0-9_.-]*)+)[\'"]/', $line, $matches);

            foreach ($matches[1] ?? [] as $permission) {
                $permissions[] = $permission;
            }
        }

        return array_values($permissions);
    }

    /**
     * @return list<string>
     */
    private function knownIndirectPermissionStrings(string $classSource, string $actionSource): array
    {
        $toolReadPermissions = preg_match('/visibleToolNodeIds\(\s*\$caller\s*,\s*true\s*\)/', $actionSource) === 1
            ? ['tool:read']
            : [];

        return [
            ...$toolReadPermissions,
            ...array_map(
                static fn (array $candidate): string => $candidate['permission'],
                array_filter(
                    self::KNOWN_INDIRECT_PERMISSION_CALLS,
                    static fn (array $candidate): bool => (
                        str_contains($classSource, (string) $candidate['class'])
                        && str_contains($actionSource, (string) $candidate['action'])
                    ),
                ),
            ),
        ];
    }
}
