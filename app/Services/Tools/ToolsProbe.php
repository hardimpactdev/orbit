<?php

declare(strict_types=1);

namespace App\Services\Tools;

use App\Contracts\RemoteShell;
use App\Data\Doctor\DriftEntry;
use App\Data\Doctor\ProbeSnapshot;
use App\Enums\DriftKind;
use App\Enums\Nodes\NodeRoleName;
use App\Models\Node;
use App\Models\NodeRoleAssignment;
use App\Models\NodeTool;
use App\Models\ProxyRoute;
use App\Services\Nodes\Roles\NodeRoleAssignments;
use App\Services\Proxy\ProxyRouteRenderer;
use Throwable;

final readonly class ToolsProbe
{
    private const array ExpectedStates = ['installed', 'running', 'stopped', 'absent'];

    public function __construct(
        private ?RemoteShell $remoteShell = null,
        private ?ToolCatalog $catalog = null,
    ) {}

    public function key(): string
    {
        return 'tool';
    }

    public function label(): string
    {
        return 'Tools';
    }

    public function introspect(NodeTool $tool): ProbeSnapshot
    {
        $tool->loadMissing('node');

        if (! $tool->node instanceof Node || $tool->name === '') {
            return new ProbeSnapshot([]);
        }

        $metadata = ($this->catalog ?? app(ToolCatalog::class))->probeMetadata($tool->name);
        $binary = $metadata['binary'] ?? $tool->name;
        $versionCommand = $metadata['version_command'] ?? null;
        $service = $metadata['service'] ?? null;
        $configPath = $this->managedConfigPath($tool);
        $secretPath = $this->managedSecretPath($tool);
        $php = <<<'PHP'
$payload = json_decode(stream_get_contents(STDIN), true);
$binary = (string) ($payload['binary'] ?? '');
$versionCommand = (string) ($payload['version_command'] ?? '');
$service = (string) ($payload['service'] ?? '');
$configPath = (string) ($payload['config_path'] ?? '');
$secretPath = (string) ($payload['secret_path'] ?? '');
$path = trim((string) shell_exec('command -v '.escapeshellarg($binary).' 2>/dev/null'));

if ($path === '') {
    exit(1);
}

$version = '';
$state = 'unknown';
$configExists = '';
$configHash = '';
$secretExists = '';
$secretHash = '';

if ($versionCommand !== '') {
    $version = trim((string) shell_exec($versionCommand.' 2>/dev/null | head -n 1'));
}

if ($service !== '') {
    exec('systemctl is-active --quiet '.escapeshellarg($service).' 2>/dev/null', $output, $exitCode);
    $state = $exitCode === 0 ? 'running' : 'stopped';
}

if ($configPath !== '') {
    $configExists = is_file($configPath) ? '1' : '0';
    $configHash = $configExists === '1' ? hash_file('sha256', $configPath) : '';
}

if ($secretPath !== '') {
    $secretExists = is_file($secretPath) ? '1' : '0';
    $secretHash = $secretExists === '1' ? hash_file('sha256', $secretPath) : '';
}

printf("%s\t%s\t%s\t%s\t%s\t%s\t%s\n", $path, $version, $state, $configExists, $configHash, $secretExists, $secretHash);
PHP;

        $script = 'php -r '.escapeshellarg($php);

        $result = ($this->remoteShell ?? app(RemoteShell::class))->run($tool->node, $script, [
            'throw' => false,
            'input' => (string) json_encode([
                'binary' => $binary,
                'version_command' => is_string($versionCommand) ? $versionCommand : '',
                'service' => is_string($service) ? $service : '',
                'config_path' => $configPath ?? '',
                'secret_path' => $secretPath ?? '',
            ], JSON_THROW_ON_ERROR),
        ]);
        $parts = explode("\t", trim($result->stdout), 7);

        return new ProbeSnapshot([
            $tool->name => [
                'installed' => $result->successful(),
                'path' => ($parts[0] ?? '') !== '' ? $parts[0] : null,
                'version' => ($parts[1] ?? '') !== '' ? $parts[1] : null,
                'state' => ($parts[2] ?? '') !== '' ? $parts[2] : null,
                'config_exists' => ($parts[3] ?? '') !== '' ? $parts[3] === '1' : null,
                'config_hash' => ($parts[4] ?? '') !== '' ? $parts[4] : null,
                'secret_exists' => ($parts[5] ?? '') !== '' ? $parts[5] === '1' : null,
                'secret_hash' => ($parts[6] ?? '') !== '' ? $parts[6] : null,
            ],
        ]);
    }

    /**
     * @return list<DriftEntry>
     */
    public function diff(NodeTool $tool, ProbeSnapshot $snapshot): array
    {
        return [
            ...$this->checkRecordCompleteness($tool),
            ...$this->checkNodeEligibility($tool),
            ...$this->checkDefinition($tool),
            ...$this->checkCapabilityPresence($tool, $snapshot),
            ...$this->checkVersionState($tool, $snapshot),
            ...$this->checkLifecycleState($tool, $snapshot),
            ...$this->checkConfigState($tool, $snapshot),
            ...$this->checkCredentialState($tool, $snapshot),
            ...$this->checkAgentRoute($tool),
            ...$this->checkAgentCredentials($tool),
            ...$this->checkAgentUser($tool),
        ];
    }

    /**
     * @return list<DriftEntry>
     */
    private function checkRecordCompleteness(NodeTool $tool): array
    {
        if (
            ! is_int($tool->node_id)
            || $tool->name === ''
            || ! in_array($tool->expected_state, self::ExpectedStates, true)
        ) {
            return [
                new DriftEntry(
                    family: $this->key(),
                    key: 'tool.record_incomplete',
                    kind: DriftKind::Missing,
                    summary: "Tool record {$tool->name} is missing required fields.",
                ),
            ];
        }

        return [];
    }

    /**
     * @return list<DriftEntry>
     */
    private function checkNodeEligibility(NodeTool $tool): array
    {
        $tool->loadMissing('node');

        if (! $tool->node instanceof Node) {
            return [
                new DriftEntry(
                    family: $this->key(),
                    key: 'tool.node_invalid',
                    kind: DriftKind::Divergent,
                    summary: "Tool {$tool->name} points at a missing node.",
                ),
            ];
        }

        if ($tool->node->status !== 'active' || ! $this->isToolNode($tool->node)) {
            return [
                new DriftEntry(
                    family: $this->key(),
                    key: 'tool.node_invalid',
                    kind: DriftKind::Divergent,
                    summary: "Tool {$tool->name} targets node {$tool->node->name}, which is not an active gateway or app node.",
                    detail: [
                        'node' => $tool->node->name,
                        'role' => $tool->node->role,
                        'status' => $tool->node->status,
                    ],
                ),
            ];
        }

        return [];
    }

    private function isToolNode(Node $node): bool
    {
        return app(NodeRoleAssignments::class)->nodeCanHostManagedTools($node);
    }

    /**
     * @return list<DriftEntry>
     */
    private function checkDefinition(NodeTool $tool): array
    {
        $catalog = $this->catalog ?? app(ToolCatalog::class);

        if ($tool->name !== '' && $catalog->supports($tool->name)) {
            return [];
        }

        return [
            new DriftEntry(
                family: $this->key(),
                key: 'tool.definition_missing',
                kind: DriftKind::Missing,
                summary: "Tool {$tool->name} is not present in the Orbit tool catalog.",
            ),
        ];
    }

    /**
     * @return list<DriftEntry>
     */
    private function checkCapabilityPresence(NodeTool $tool, ProbeSnapshot $snapshot): array
    {
        if ($tool->expected_state === 'absent') {
            return [];
        }

        $observed = $snapshot->get($tool->name);

        if (($observed['installed'] ?? null) === true) {
            return [];
        }

        return [
            new DriftEntry(
                family: $this->key(),
                key: 'tool.capability_missing',
                kind: DriftKind::Missing,
                summary: "Tool {$tool->name} is missing on the target node.",
                detail: [
                    'tool' => $tool->name,
                ],
            ),
        ];
    }

    /**
     * @return list<DriftEntry>
     */
    private function checkVersionState(NodeTool $tool, ProbeSnapshot $snapshot): array
    {
        if ($tool->expected_version === null || $tool->expected_version === '') {
            return [];
        }

        $observed = $snapshot->get($tool->name);

        if (($observed['installed'] ?? null) !== true) {
            return [];
        }

        $version = is_string($observed['version'] ?? null) ? $observed['version'] : null;

        if ($version === null || str_starts_with($version, (string) $tool->expected_version)) {
            return [];
        }

        return [
            new DriftEntry(
                family: $this->key(),
                key: 'tool.version_mismatch',
                kind: DriftKind::Divergent,
                summary: "Tool {$tool->name} version differs from gateway intent.",
                detail: [
                    'tool' => $tool->name,
                    'expected_version' => $tool->expected_version,
                    'observed_version' => $version,
                ],
            ),
        ];
    }

    /**
     * @return list<DriftEntry>
     */
    private function checkLifecycleState(NodeTool $tool, ProbeSnapshot $snapshot): array
    {
        if (! in_array($tool->expected_state, ['running', 'stopped'], true)) {
            return [];
        }

        $observed = $snapshot->get($tool->name);

        if (($observed['installed'] ?? null) !== true) {
            return [];
        }

        $state = is_string($observed['state'] ?? null) ? $observed['state'] : null;

        if ($state === null || $state === 'unknown' || $state === $tool->expected_state) {
            return [];
        }

        return [
            new DriftEntry(
                family: $this->key(),
                key: 'tool.lifecycle_state_mismatch',
                kind: DriftKind::Divergent,
                summary: "Tool {$tool->name} lifecycle state differs from gateway intent.",
                detail: [
                    'tool' => $tool->name,
                    'expected_state' => $tool->expected_state,
                    'observed_state' => $state,
                ],
            ),
        ];
    }

    /**
     * @return list<DriftEntry>
     */
    private function checkConfigState(NodeTool $tool, ProbeSnapshot $snapshot): array
    {
        $path = $this->managedConfigPath($tool);
        $expectedHash = $this->managedConfigHash($tool);

        if ($path === null || $expectedHash === null) {
            return [];
        }

        $observed = $snapshot->get($tool->name);

        if (($observed['installed'] ?? null) !== true) {
            return [];
        }

        if (($observed['config_exists'] ?? null) === false) {
            return [
                new DriftEntry(
                    family: $this->key(),
                    key: 'tool.config_missing',
                    kind: DriftKind::Missing,
                    summary: "Tool {$tool->name} managed configuration is missing.",
                    detail: [
                        'tool' => $tool->name,
                        'path' => $path,
                    ],
                ),
            ];
        }

        $observedHash = is_string($observed['config_hash'] ?? null) ? $observed['config_hash'] : null;

        if ($observedHash === null || hash_equals($expectedHash, $observedHash)) {
            return [];
        }

        return [
            new DriftEntry(
                family: $this->key(),
                key: 'tool.config_mismatch',
                kind: DriftKind::Divergent,
                summary: "Tool {$tool->name} managed configuration differs from gateway intent.",
                detail: [
                    'tool' => $tool->name,
                    'path' => $path,
                    'expected_hash' => $expectedHash,
                    'observed_hash' => $observedHash,
                ],
            ),
        ];
    }

    private function managedConfigPath(NodeTool $tool): ?string
    {
        $config = is_array($tool->config) ? $tool->config : [];
        $managedConfig = is_array($config['managed_config'] ?? null) ? $config['managed_config'] : [];
        $path = $managedConfig['path'] ?? null;

        return is_string($path) && $path !== '' ? $path : null;
    }

    private function managedConfigHash(NodeTool $tool): ?string
    {
        $config = is_array($tool->config) ? $tool->config : [];
        $managedConfig = is_array($config['managed_config'] ?? null) ? $config['managed_config'] : [];
        $hash = $managedConfig['hash'] ?? null;

        return is_string($hash) && $hash !== '' ? $hash : null;
    }

    /**
     * @return list<DriftEntry>
     */
    private function checkCredentialState(NodeTool $tool, ProbeSnapshot $snapshot): array
    {
        $path = $this->managedSecretPath($tool);
        $expectedHash = $this->managedSecretHash($tool);

        if ($path === null || $expectedHash === null) {
            return [];
        }

        $observed = $snapshot->get($tool->name);

        if (($observed['installed'] ?? null) !== true) {
            return [];
        }

        if (($observed['secret_exists'] ?? null) === false) {
            return [
                new DriftEntry(
                    family: $this->key(),
                    key: 'tool.credentials_missing',
                    kind: DriftKind::Missing,
                    summary: "Tool {$tool->name} managed credential material is missing.",
                    detail: [
                        'tool' => $tool->name,
                        'path' => $path,
                    ],
                ),
            ];
        }

        $observedHash = is_string($observed['secret_hash'] ?? null) ? $observed['secret_hash'] : null;

        if ($observedHash === null || hash_equals($expectedHash, $observedHash)) {
            return [];
        }

        return [
            new DriftEntry(
                family: $this->key(),
                key: 'tool.credentials_mismatch',
                kind: DriftKind::Divergent,
                summary: "Tool {$tool->name} managed credential material differs from gateway intent.",
                detail: [
                    'tool' => $tool->name,
                    'path' => $path,
                    'expected_hash' => $expectedHash,
                    'observed_hash' => $observedHash,
                ],
            ),
        ];
    }

    private function managedSecretPath(NodeTool $tool): ?string
    {
        $credentials = is_array($tool->credentials) ? $tool->credentials : [];
        $managedSecret = is_array($credentials['managed_secret'] ?? null) ? $credentials['managed_secret'] : [];
        $path = $managedSecret['path'] ?? null;

        return is_string($path) && $path !== '' ? $path : null;
    }

    private function managedSecretHash(NodeTool $tool): ?string
    {
        $credentials = is_array($tool->credentials) ? $tool->credentials : [];
        $managedSecret = is_array($credentials['managed_secret'] ?? null) ? $credentials['managed_secret'] : [];
        $hash = $managedSecret['hash'] ?? null;

        return is_string($hash) && $hash !== '' ? $hash : null;
    }

    /**
     * @return list<DriftEntry>
     */
    private function checkAgentRoute(NodeTool $tool): array
    {
        $catalog = $this->catalog ?? app(ToolCatalog::class);

        if ($catalog->category($tool->name) !== 'agent') {
            return [];
        }

        $tool->loadMissing('node');

        if (! $tool->node instanceof Node) {
            return [];
        }

        $tld = $this->agentTldForNode($tool->node);

        if ($tld === null) {
            return [
                new DriftEntry(
                    family: $this->key(),
                    key: 'tool.agent_route_missing',
                    kind: DriftKind::Missing,
                    summary: "Tool {$tool->name} cannot verify proxy route because the node has no active agent role TLD.",
                    detail: [
                        'tool' => $tool->name,
                        'node' => $tool->node->name,
                    ],
                ),
            ];
        }

        $domain = "{$tool->name}.{$tld}";
        $route = ProxyRoute::query()
            ->where('node_id', $tool->node->id)
            ->where('domain', $domain)
            ->where('owner_type', 'tool')
            ->first();

        if (! $route instanceof ProxyRoute) {
            return [
                new DriftEntry(
                    family: $this->key(),
                    key: 'tool.agent_route_missing',
                    kind: DriftKind::Missing,
                    summary: "Tool {$tool->name} is missing the internal proxy route under the agent role TLD.",
                    detail: [
                        'tool' => $tool->name,
                        'node' => $tool->node->name,
                        'domain' => $domain,
                    ],
                ),
            ];
        }

        $routeOwner = is_array($route->config) ? ($route->config['owner_name'] ?? null) : null;

        if ($routeOwner !== $tool->name) {
            return [
                new DriftEntry(
                    family: $this->key(),
                    key: 'tool.agent_route_missing',
                    kind: DriftKind::Divergent,
                    summary: "Tool {$tool->name} proxy route is owned by a different tool.",
                    detail: [
                        'tool' => $tool->name,
                        'node' => $tool->node->name,
                        'domain' => $domain,
                        'route_owner' => $routeOwner,
                    ],
                ),
            ];
        }

        $expectedConfig = $this->agentProxyRouteConfig($tool->name);
        $expectedHash = $this->agentProxyRouteSourceHash($tool->node, $domain, $expectedConfig);
        $routeConfig = is_array($route->config) ? $route->config : [];

        if (
            $route->kind !== 'proxy'
            || ($routeConfig['target']['type'] ?? null) !== ($expectedConfig['target']['type'] ?? null)
            || ($routeConfig['target']['value'] ?? null) !== ($expectedConfig['target']['value'] ?? null)
            || ($routeConfig['upstream'] ?? null) !== ($expectedConfig['upstream'] ?? null)
            || $route->source_hash !== $expectedHash
        ) {
            return [
                new DriftEntry(
                    family: $this->key(),
                    key: 'tool.agent_route_missing',
                    kind: DriftKind::Divergent,
                    summary: "Tool {$tool->name} proxy route does not match Orbit's managed agent route shape.",
                    detail: [
                        'tool' => $tool->name,
                        'node' => $tool->node->name,
                        'domain' => $domain,
                        'expected_kind' => 'proxy',
                        'observed_kind' => $route->kind,
                        'expected_upstream' => $expectedConfig['upstream'],
                        'observed_upstream' => $routeConfig['target']['value'] ?? $routeConfig['upstream'] ?? null,
                    ],
                ),
            ];
        }

        return [];
    }

    /**
     * @return array{target: array{type: string, value: string}, upstream: string, owner_name: string}
     */
    private function agentProxyRouteConfig(string $tool): array
    {
        $upstream = 'http://127.0.0.1:8080';

        return [
            'target' => ['type' => 'upstream', 'value' => $upstream],
            'upstream' => $upstream,
            'owner_name' => $tool,
        ];
    }

    /**
     * @param  array{target: array{type: string, value: string}, upstream: string, owner_name: string}  $config
     */
    private function agentProxyRouteSourceHash(Node $node, string $domain, array $config): string
    {
        return app(ProxyRouteRenderer::class)->sourceHash(new ProxyRoute([
            'node_id' => $node->id,
            'domain' => $domain,
            'kind' => 'proxy',
            'owner_type' => 'tool',
            'config' => $config,
        ]));
    }

    /**
     * @return list<DriftEntry>
     */
    private function checkAgentCredentials(NodeTool $tool): array
    {
        $catalog = $this->catalog ?? app(ToolCatalog::class);

        if (! $catalog->hasCapability($tool->name, 'credentials')) {
            return [];
        }

        if ($catalog->category($tool->name) !== 'agent') {
            return [];
        }

        $credentials = is_array($tool->credentials) ? $tool->credentials : [];

        if ($credentials === []) {
            return [
                new DriftEntry(
                    family: $this->key(),
                    key: 'tool.agent_credentials_missing',
                    kind: DriftKind::Missing,
                    summary: "Tool {$tool->name} declares credentials but no managed credential metadata is present.",
                    detail: [
                        'tool' => $tool->name,
                    ],
                ),
            ];
        }

        return [];
    }

    /**
     * @return list<DriftEntry>
     */
    private function checkAgentUser(NodeTool $tool): array
    {
        $catalog = $this->catalog ?? app(ToolCatalog::class);

        if ($catalog->category($tool->name) !== 'agent') {
            return [];
        }

        $tool->loadMissing('node');

        if (! $tool->node instanceof Node) {
            return [];
        }

        if (! ($this->remoteShell instanceof RemoteShell)) {
            return [];
        }

        try {
            $result = $this->remoteShell->run($tool->node, 'id -u agent >/dev/null 2>&1', [
                'timeout' => 10,
                'throw' => false,
            ]);

            if (! $result->successful()) {
                return [
                    new DriftEntry(
                        family: $this->key(),
                        key: 'tool.agent_user_missing',
                        kind: DriftKind::Missing,
                        summary: "Tool {$tool->name} is installed on a node whose agent runtime user is absent.",
                        detail: [
                            'tool' => $tool->name,
                            'node' => $tool->node->name,
                        ],
                    ),
                ];
            }
        } catch (Throwable) {
            return [];
        }

        return [];
    }

    private function agentTldForNode(Node $node): ?string
    {
        $assignment = app(NodeRoleAssignments::class)->activeAssignment($node, NodeRoleName::Agent->value);

        if ($assignment instanceof NodeRoleAssignment) {
            $settings = $assignment->settings ?? [];
            $tld = is_array($settings) ? ($settings['tld'] ?? null) : null;

            if (is_string($tld) && trim($tld) !== '') {
                return trim($tld);
            }
        }

        if (is_string($node->tld) && trim($node->tld) !== '') {
            return trim($node->tld);
        }

        return null;
    }
}
