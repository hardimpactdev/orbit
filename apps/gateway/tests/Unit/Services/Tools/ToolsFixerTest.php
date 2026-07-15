<?php

declare(strict_types=1);

use App\Contracts\RemoteShell;
use App\Contracts\ToolDefinition;
use App\Data\Doctor\DriftEntry;
use App\Data\RemoteShell\RemoteShellResult;
use App\Enums\DriftKind;
use App\Models\Node;
use App\Models\NodeTool;
use App\Models\ProxyRoute;
use App\Services\Proxy\ProxyRouteRenderer;
use App\Services\RemoteShell\RunsInternalCommands;
use App\Services\Runtime\OrbitCaddyContainer;
use App\Services\Tools\ToolCatalog;
use App\Services\Tools\ToolDefinitionRegistry;
use App\Services\Tools\ToolsFixer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Orbit\Core\Enums\InternalCommand;
use Orbit\Core\Http\JsonEnvelope;
use Tests\TestCase;

uses(TestCase::class);
uses()->group('doctor', 'fixer');
uses(RefreshDatabase::class);

afterEach(function (): void {});

function allow_tools_fixer_remote_shell_fallback(): void {}

function use_tools_fixer_agent_push(): void {}

function use_tools_fixer_internal_tool_dispatch(): ToolsFixerRecordingInternalExecutor
{
    $executor = new ToolsFixerRecordingInternalExecutor;
    app()->instance(RunsInternalCommands::class, $executor);

    return $executor;
}

/** @mago-expect lint:cyclomatic-complexity */
describe('ToolsFixer', function (): void {
    it('returns null for tool.lifecycle_state_mismatch since runtime state is process-family owned', function (): void {
        $node = createTestAppHostNode(['name' => 'app-1', 'status' => 'active']);
        $tool = NodeTool::factory()->create([
            'node_id' => $node->id,
            'name' => 'caddy',
            'expected_state' => 'installed',
        ]);
        $shell = new ToolsFixerRemoteShell;

        $action = new ToolsFixer()->fix($tool, new DriftEntry(
            family: 'tool',
            key: 'tool.lifecycle_state_mismatch',
            kind: DriftKind::Divergent,
            summary: 'Tool caddy lifecycle state differs from gateway intent.',
            detail: [
                'tool' => 'caddy',
                'expected_state' => 'installed',
                'observed_state' => 'stopped',
            ],
        ));

        // tool.lifecycle_state_mismatch is not a tool issue code; fixer must return null
        expect($action)->toBeNull()->and($shell->scripts)->toBe([]);
    });

    it('skips issue codes without catalog-declared repair commands', function (): void {
        $node = createTestAppHostNode(['name' => 'app-1', 'status' => 'active']);
        $tool = NodeTool::factory()->create([
            'node_id' => $node->id,
            'name' => 'caddy',
        ]);
        $shell = new ToolsFixerRemoteShell;

        $action = new ToolsFixer()->fix($tool, new DriftEntry(
            family: 'tool',
            key: 'tool.config_mismatch',
            kind: DriftKind::Divergent,
            summary: 'Tool caddy managed configuration differs from gateway intent.',
            detail: ['tool' => 'caddy'],
        ));

        expect($action)->toBeNull()->and($shell->scripts)->toBe([]);
    });

    it('dispatches repair commands through internal tool run without transitional fallback', function (): void {
        $executor = use_tools_fixer_internal_tool_dispatch();
        $node = createTestAppHostNode(['name' => 'app-1', 'status' => 'active']);
        $tool = NodeTool::factory()->create([
            'node_id' => $node->id,
            'name' => 'node-exporter',
            'expected_state' => 'installed',
        ]);
        $shell = new ToolsFixerRemoteShell;

        $action = new ToolsFixer()->fix($tool, new DriftEntry(
            family: 'tool',
            key: 'tool.version_mismatch',
            kind: DriftKind::Divergent,
            summary: 'Tool node-exporter version differs from gateway intent.',
            detail: [
                'tool' => 'node-exporter',
            ],
        ));

        $payload = $executor->payloads()[0];

        expect($action)
            ->toMatchArray([
                'family' => 'tool',
                'node' => 'app-1',
                'key' => 'tool.version_mismatch',
                'status' => 'completed',
            ])
            ->and($executor->commands)
            ->toBe([InternalCommand::ToolRunScript->value])
            ->and($payload['tool'] ?? null)
            ->toBe('node-exporter')
            ->and($payload['action'] ?? null)
            ->toBe('update')
            ->and($shell->scripts)
            ->toBe([]);
    });

    it('rewrites managed config when the row contains complete content intent', function (): void {
        use_tools_fixer_agent_push();

        $content = "address=/test/10.6.0.2\n";
        $node = createTestAppHostNode([
            'name' => 'app-1',
            'status' => 'active',
            'managed' => true,
            'wireguard_address' => '10.47.0.51',
        ]);
        $tool = NodeTool::factory()->create([
            'node_id' => $node->id,
            'name' => 'dns',
            'config' => [
                'managed_config' => [
                    'path' => '/etc/orbit/dns.conf',
                    'hash' => hash('sha256', $content),
                    'content' => $content,
                ],
            ],
        ]);
        $shell = new ToolsFixerRemoteShell;
        Http::preventStrayRequests();
        Http::fake([
            'http://10.47.0.51:9477/v1/commands' => tools_fixer_managed_file_sequence(
                path: '/etc/orbit/dns.conf',
                content: $content,
            ),
        ]);

        $action = new ToolsFixer()->fix($tool, new DriftEntry(
            family: 'tool',
            key: 'tool.config_mismatch',
            kind: DriftKind::Divergent,
            summary: 'Tool dns managed configuration differs from gateway intent.',
            detail: [
                'tool' => 'dns',
                'path' => '/etc/orbit/dns.conf',
            ],
        ));

        expect($action)
            ->toMatchArray([
                'family' => 'tool',
                'node' => 'app-1',
                'key' => 'tool.config_mismatch',
                'status' => 'completed',
            ])
            ->and($shell->scripts)
            ->toBe([])
            ->and(array_slice(tools_fixer_agent_requests('10.47.0.51')[0]['argv'] ?? [], offset: 0, length: 2))
            ->toBe(['internal:managed-file', 'probe'])
            ->and(array_slice(tools_fixer_agent_requests('10.47.0.51')[1]['argv'] ?? [], offset: 0, length: 2))
            ->toBe(['internal:managed-file', 'write'])
            ->and(tools_fixer_agent_payload('10.47.0.51', action: 'write'))
            ->toMatchArray([
                'path' => '/etc/orbit/dns.conf',
                'content' => $content,
                'mode' => '0644',
                'directory_mode' => '0755',
            ]);
    });

    it('honors managed config mode intent when rewriting managed config', function (): void {
        use_tools_fixer_agent_push();

        $content = "address=/test/10.6.0.2\n";
        $node = createTestAppHostNode([
            'name' => 'app-1',
            'status' => 'active',
            'managed' => true,
            'wireguard_address' => '10.47.0.52',
        ]);
        $tool = NodeTool::factory()->create([
            'node_id' => $node->id,
            'name' => 'dns',
            'config' => [
                'managed_config' => [
                    'path' => '/etc/orbit/dns.conf',
                    'hash' => hash('sha256', $content),
                    'content' => $content,
                    'mode' => '0640',
                    'directory_mode' => '0750',
                ],
            ],
        ]);
        $shell = new ToolsFixerRemoteShell;
        Http::preventStrayRequests();
        Http::fake([
            'http://10.47.0.52:9477/v1/commands' => tools_fixer_managed_file_sequence(
                path: '/etc/orbit/dns.conf',
                content: $content,
                mode: '0640',
            ),
        ]);

        $action = new ToolsFixer()->fix($tool, new DriftEntry(
            family: 'tool',
            key: 'tool.config_mismatch',
            kind: DriftKind::Divergent,
            summary: 'Tool dns managed configuration differs from gateway intent.',
            detail: [
                'tool' => 'dns',
                'path' => '/etc/orbit/dns.conf',
            ],
        ));

        expect($action)
            ->not
            ->toBeNull()
            ->and($shell->scripts)
            ->toBe([])
            ->and(tools_fixer_agent_payload('10.47.0.52', action: 'write'))
            ->toMatchArray([
                'path' => '/etc/orbit/dns.conf',
                'content' => $content,
                'mode' => '0640',
                'directory_mode' => '0750',
            ]);
    });

    it('does not repair managed config when content does not match declared hash', function (): void {
        $node = createTestAppHostNode(['name' => 'app-1', 'status' => 'active']);
        $tool = NodeTool::factory()->create([
            'node_id' => $node->id,
            'name' => 'dns',
            'config' => [
                'managed_config' => [
                    'path' => '/etc/orbit/dns.conf',
                    'hash' => str_repeat('a', 64),
                    'content' => "address=/test/10.6.0.2\n",
                ],
            ],
        ]);
        $shell = new ToolsFixerRemoteShell;

        $action = new ToolsFixer()->fix($tool, new DriftEntry(
            family: 'tool',
            key: 'tool.config_missing',
            kind: DriftKind::Missing,
            summary: 'Tool dns managed configuration is missing.',
            detail: ['tool' => 'dns'],
        ));

        expect($action)->toBeNull()->and($shell->scripts)->toBe([]);
    });

    it('rewrites managed secret material when the row contains complete secret intent', function (): void {
        use_tools_fixer_agent_push();

        $secret = 'generated-password';
        $node = createTestAppHostNode([
            'name' => 'app-1',
            'status' => 'active',
            'managed' => true,
            'wireguard_address' => '10.47.0.53',
        ]);
        $tool = NodeTool::factory()->create([
            'node_id' => $node->id,
            'name' => 'opencode-cli',
            'credentials' => [
                'managed_secret' => [
                    'path' => '/home/orbit/.config/opencode-server/password',
                    'hash' => hash('sha256', $secret),
                    'content' => $secret,
                ],
            ],
        ]);
        $shell = new ToolsFixerRemoteShell;
        Http::preventStrayRequests();
        Http::fake([
            'http://10.47.0.53:9477/v1/commands' => tools_fixer_managed_file_sequence(
                path: '/home/orbit/.config/opencode-server/password',
                content: $secret,
                mode: '0600',
            ),
        ]);

        $action = new ToolsFixer()->fix($tool, new DriftEntry(
            family: 'tool',
            key: 'tool.credentials_missing',
            kind: DriftKind::Missing,
            summary: 'Tool opencode-cli managed credential material is missing.',
            detail: [
                'tool' => 'opencode-cli',
                'path' => '/home/orbit/.config/opencode-server/password',
            ],
        ));

        expect($action)
            ->toMatchArray([
                'family' => 'tool',
                'node' => 'app-1',
                'key' => 'tool.credentials_missing',
                'status' => 'completed',
            ])
            ->and($shell->scripts)
            ->toBe([])
            ->and(tools_fixer_agent_payload('10.47.0.53', action: 'write'))
            ->toMatchArray([
                'path' => '/home/orbit/.config/opencode-server/password',
                'content' => $secret,
                'mode' => '0600',
                'directory_mode' => '0700',
            ]);
    });

    it('does not repair managed secret material when content does not match declared hash', function (): void {
        $node = createTestAppHostNode(['name' => 'app-1', 'status' => 'active']);
        $tool = NodeTool::factory()->create([
            'node_id' => $node->id,
            'name' => 'opencode-cli',
            'credentials' => [
                'managed_secret' => [
                    'path' => '/home/orbit/.config/opencode-server/password',
                    'hash' => str_repeat('a', 64),
                    'content' => 'generated-password',
                ],
            ],
        ]);
        $shell = new ToolsFixerRemoteShell;

        $action = new ToolsFixer()->fix($tool, new DriftEntry(
            family: 'tool',
            key: 'tool.credentials_mismatch',
            kind: DriftKind::Divergent,
            summary: 'Tool opencode-cli managed credential material differs from gateway intent.',
            detail: ['tool' => 'opencode-cli'],
        ));

        expect($action)->toBeNull()->and($shell->scripts)->toBe([]);
    });

    it('installs missing host tools through catalog install script', function (): void {
        $executor = use_tools_fixer_internal_tool_dispatch();
        $node = createTestAppHostNode(['name' => 'app-1', 'status' => 'active']);
        $tool = NodeTool::factory()->create([
            'node_id' => $node->id,
            'name' => 'composer',
            'expected_state' => 'installed',
        ]);
        $shell = new ToolsFixerRemoteShell;

        $action = new ToolsFixer()->fix($tool, new DriftEntry(
            family: 'tool',
            key: 'tool.capability_missing',
            kind: DriftKind::Missing,
            summary: 'Tool composer is missing on the target node.',
            detail: ['tool' => 'composer'],
        ));

        expect($action)
            ->toMatchArray([
                'family' => 'tool',
                'node' => 'app-1',
                'key' => 'tool.capability_missing',
                'mode' => 'fix',
                'status' => 'completed',
            ])
            ->and($executor->payloads()[0]['script'] ?? null)
            ->toContain('composer-setup.php')
            ->and($executor->payloads()[0]['script'] ?? null)
            ->toContain('sudo php composer-setup.php --install-dir=/usr/local/bin --filename=composer');
    });

    it('repairs missing git through the catalog apt install script', function (): void {
        $executor = use_tools_fixer_internal_tool_dispatch();
        $node = createTestAppHostNode(['name' => 'app-1', 'status' => 'active']);
        $tool = NodeTool::factory()->create([
            'node_id' => $node->id,
            'name' => 'git',
            'expected_state' => 'installed',
        ]);
        $shell = new ToolsFixerRemoteShell;

        $action = new ToolsFixer()->fix($tool, new DriftEntry(
            family: 'tool',
            key: 'tool.capability_missing',
            kind: DriftKind::Missing,
            summary: 'Tool git is missing on the target node.',
            detail: ['tool' => 'git'],
        ));

        expect($action)
            ->toMatchArray([
                'family' => 'tool',
                'node' => 'app-1',
                'key' => 'tool.capability_missing',
                'mode' => 'fix',
                'status' => 'completed',
            ])
            ->and($executor->payloads()[0]['script'] ?? null)
            ->toContain('# orbit install git')
            ->and($executor->payloads()[0]['script'] ?? null)
            ->toContain('apt-get')
            ->and($executor->payloads()[0]['script'] ?? null)
            ->toContain('ppa:git-core/ppa');
    });

    it('repairs missing gh through the prepared GitHub CLI apt metadata path', function (): void {
        $executor = use_tools_fixer_internal_tool_dispatch();
        $node = createTestAppHostNode(['name' => 'app-1', 'status' => 'active']);
        $tool = NodeTool::factory()->create([
            'node_id' => $node->id,
            'name' => 'gh',
            'expected_state' => 'installed',
        ]);
        $shell = new ToolsFixerRemoteShell;

        $action = new ToolsFixer()->fix($tool, new DriftEntry(
            family: 'tool',
            key: 'tool.capability_missing',
            kind: DriftKind::Missing,
            summary: 'Tool gh is missing on the target node.',
            detail: ['tool' => 'gh'],
        ));

        expect($action)
            ->toMatchArray([
                'family' => 'tool',
                'node' => 'app-1',
                'key' => 'tool.capability_missing',
                'mode' => 'fix',
                'status' => 'completed',
            ])
            ->and($executor->payloads()[0]['script'] ?? null)
            ->toContain('# orbit install gh')
            ->and($executor->payloads()[0]['script'] ?? null)
            ->toContain('gh_package_candidate_available')
            ->and($executor->payloads()[0]['script'] ?? null)
            ->toContain('refresh_github_cli_metadata')
            ->and($executor->payloads()[0]['script'] ?? null)
            ->toContain('github_cli_metadata_needs_refresh=1')
            ->and($executor->payloads()[0]['script'] ?? null)
            ->toContain('Dir::Etc::sourcelist="sources.list.d/github-cli.list"')
            ->and($executor->payloads()[0]['script'] ?? null)
            ->toContain('APT::Get::List-Cleanup="0"')
            ->and($executor->payloads()[0]['script'] ?? null)
            ->toContain('if ! sudo apt-get -o DPkg::Lock::Timeout=300 install -y -qq gh; then')
            ->and($executor->payloads()[0]['script'] ?? null)
            ->toContain('download_github_cli_keyring');
    });

    it('passes the node managed user into host tool install scripts', function (): void {
        $executor = use_tools_fixer_internal_tool_dispatch();
        $node = createTestAppHostNode(['name' => 'app-1', 'status' => 'active', 'user' => 'nckrtl']);
        $tool = NodeTool::factory()->create([
            'node_id' => $node->id,
            'name' => 'laravel-installer',
            'expected_state' => 'installed',
        ]);
        $shell = new ToolsFixerRemoteShell;

        $action = new ToolsFixer()->fix($tool, new DriftEntry(
            family: 'tool',
            key: 'tool.capability_missing',
            kind: DriftKind::Missing,
            summary: 'Tool laravel-installer is missing on the target node.',
            detail: ['tool' => 'laravel-installer'],
        ));

        expect($action)
            ->toMatchArray([
                'family' => 'tool',
                'node' => 'app-1',
                'key' => 'tool.capability_missing',
                'mode' => 'fix',
                'status' => 'completed',
            ])
            ->and($executor->payloads()[0]['script'] ?? null)
            ->toContain("MANAGED_USER='nckrtl'")
            ->and($executor->payloads()[0]['script'] ?? null)
            ->toContain('sudo -u "${MANAGED_USER}"')
            ->and($executor->payloads()[0]['script'] ?? null)
            ->not->toContain("MANAGED_USER='orbit'");
    });

    it('returns null for capability missing when no install script exists', function (): void {
        $node = createTestAppHostNode(['name' => 'app-1', 'status' => 'active']);
        $tool = NodeTool::factory()->create([
            'node_id' => $node->id,
            'name' => 'viteplus',
            'expected_state' => 'installed',
        ]);
        $shell = new ToolsFixerRemoteShell;

        $action = new ToolsFixer()->fix($tool, new DriftEntry(
            family: 'tool',
            key: 'tool.capability_missing',
            kind: DriftKind::Missing,
            summary: 'Tool viteplus is missing on the target node.',
            detail: ['tool' => 'viteplus'],
        ));

        expect($action)->toBeNull()->and($shell->scripts)->toBe([]);
    });

    it('does not repair stale service process names as tool rows', function (string $toolName, string $key): void {
        $node = createTestAppHostNode(['name' => 'database-1', 'status' => 'active']);
        $tool = NodeTool::factory()->create([
            'node_id' => $node->id,
            'name' => $toolName,
            'expected_state' => 'installed',
        ]);
        $shell = new ToolsFixerRemoteShell;

        $action = new ToolsFixer()->fix($tool, new DriftEntry(
            family: 'tool',
            key: $key,
            kind: DriftKind::Missing,
            summary: "Tool {$toolName} drift should not be repaired as a tool.",
            detail: ['tool' => $toolName],
        ));

        expect(app(ToolCatalog::class)->supports($toolName))
            ->toBeFalse()
            ->and($action)
            ->toBeNull()
            ->and($shell->scripts)
            ->toBe([]);
    })->with([
        'redis capability' => ['redis', 'tool.capability_missing'],
        'redis container' => ['redis', 'tool.container_missing'],
        'mysql capability' => ['mysql', 'tool.capability_missing'],
        'mysql container' => ['mysql', 'tool.container_missing'],
    ]);

    it('reconciles missing stopped or drifted orbit-caddy containers through the declared container spec', function (string $key): void {
        allow_tools_fixer_remote_shell_fallback();
        $node = createTestAppHostNode(['name' => 'app-1', 'status' => 'active']);
        $container = OrbitCaddyContainer::forPrivateNode('10.6.0.50');
        $tool = NodeTool::factory()->create([
            'node_id' => $node->id,
            'name' => 'caddy',
            'config' => ['container' => $container->spec()],
        ]);
        $shell = new ToolsFixerRemoteShell;

        $action = new ToolsFixer()->fix($tool, new DriftEntry(
            family: 'tool',
            key: $key,
            kind: in_array($key, ['tool.capability_missing', 'tool.container_missing'], true)
                ? DriftKind::Missing
                : DriftKind::Divergent,
            summary: 'orbit-caddy container drift',
            detail: ['tool' => 'caddy'],
        ));

        expect($action)
            ->toMatchArray([
                'family' => 'tool',
                'node' => 'app-1',
                'key' => $key,
                'status' => 'completed',
            ])
            ->and($shell->scripts[0])
            ->toContain('docker container inspect')
            ->and($shell->scripts[0])
            ->toContain('10.6.0.50:80:80')
            ->and($shell->scripts[0])
            ->toContain('orbit.caddy.spec_hash');
    })->with([
        'missing caddy capability' => ['tool.capability_missing'],
        'missing container' => ['tool.container_missing'],
        'stopped container' => ['tool.container_not_running'],
        'drifted container spec' => ['tool.container_spec_mismatch'],
    ]);
});

describe('agent tool fixes', function (): void {
    it('returns completed when canonical proxy route already exists', function (): void {
        [$node, $tool] = createAgentToolForFixer();
        ProxyRoute::factory()->create([
            'node_id' => $node->id,
            'domain' => 'openclaw.agent',
            'owner_type' => 'tool',
            'kind' => 'proxy',
            'source_hash' => toolsFixerAgentRouteSourceHash($node, 'openclaw'),
            'config' => toolsFixerAgentRouteConfig('openclaw'),
        ]);

        $fixer = new ToolsFixer(
            catalog: makeToolsFixerAgentToolCatalog(),
            proxyRouteRenderer: new ProxyRouteRenderer,
        );

        $result = $fixer->fix($tool, agentToolDriftEntry('tool.agent_route_missing'));

        expect($result)
            ->not
            ->toBeNull()
            ->and($result['status'])
            ->toBe('completed');
    });

    it('returns null when proxy route is owned by a different tool', function (): void {
        [$node, $tool] = createAgentToolForFixer();
        ProxyRoute::factory()->create([
            'node_id' => $node->id,
            'domain' => 'openclaw.agent',
            'owner_type' => 'tool',
            'kind' => 'proxy',
            'config' => ['owner_name' => 'hermes'],
        ]);

        $fixer = new ToolsFixer(
            catalog: makeToolsFixerAgentToolCatalog(),
        );

        $result = $fixer->fix($tool, agentToolDriftEntry('tool.agent_route_missing'));

        expect($result)->toBeNull();
    });

    it('returns null when proxy route domain is not tool owned', function (): void {
        [$node, $tool] = createAgentToolForFixer();
        ProxyRoute::factory()->create([
            'node_id' => $node->id,
            'domain' => 'openclaw.agent',
            'owner_type' => 'custom',
            'kind' => 'proxy',
            'config' => toolsFixerAgentRouteConfig('openclaw'),
        ]);

        $fixer = new ToolsFixer(
            catalog: makeToolsFixerAgentToolCatalog(),
        );

        $result = $fixer->fix($tool, agentToolDriftEntry('tool.agent_route_missing'));

        expect($result)->toBeNull();
    });

    it('creates canonical proxy route when missing', function (): void {
        [$node, $tool] = createAgentToolForFixer();
        $fixer = new ToolsFixer(
            catalog: makeToolsFixerAgentToolCatalog(),
            proxyRouteRenderer: new ProxyRouteRenderer,
        );

        $result = $fixer->fix($tool, agentToolDriftEntry('tool.agent_route_missing'));

        $route = ProxyRoute::query()->where('domain', 'openclaw.agent')->first();

        expect($result)
            ->not->toBeNull()->and($result['status'])->toBe('completed')->and($route)
            ->not->toBeNull()->and($route->kind)->toBe('proxy')->and($route->owner_type)->toBe(
                'tool',
            )->and($route->config)->toBe(toolsFixerAgentRouteConfig(
                'openclaw',
            ))->and($route->source_hash)->toBe(toolsFixerAgentRouteSourceHash($node, 'openclaw'));
    });

    it('rewrites malformed same-owner proxy routes to the canonical route shape', function (): void {
        [$node, $tool] = createAgentToolForFixer();
        ProxyRoute::factory()->create([
            'node_id' => $node->id,
            'domain' => 'openclaw.agent',
            'owner_type' => 'tool',
            'kind' => 'upstream',
            'source_hash' => str_repeat('a', 64),
            'config' => ['owner_name' => 'openclaw'],
        ]);
        $fixer = new ToolsFixer(
            catalog: makeToolsFixerAgentToolCatalog(),
            proxyRouteRenderer: new ProxyRouteRenderer,
        );

        $result = $fixer->fix($tool, agentToolDriftEntry('tool.agent_route_missing'));

        $route = ProxyRoute::query()->where('domain', 'openclaw.agent')->first();

        expect($result)
            ->not
            ->toBeNull()
            ->and($result['status'])
            ->toBe('completed')
            ->and($route->kind)
            ->toBe('proxy')
            ->and($route->config)
            ->toBe(toolsFixerAgentRouteConfig('openclaw'))
            ->and($route->source_hash)
            ->toBe(toolsFixerAgentRouteSourceHash($node, 'openclaw'));
    });

    it('updates credentials when shell returns valid JSON array', function (): void {
        $executor = new ToolsFixerRecordingInternalExecutor([
            ToolsFixerRecordingInternalExecutor::result(stdout: '["user","pass"]'),
        ]);
        app()->instance(RunsInternalCommands::class, $executor);
        [, $tool] = createAgentToolForFixer();
        $shell = new ToolsFixerRemoteShell;

        $fixer = new ToolsFixer(
            catalog: makeToolsFixerAgentToolCatalog([
                'credentialsScript' => "echo '[\"user\",\"pass\"]'",
            ]),
        );

        $result = $fixer->fix($tool, agentToolDriftEntry('tool.agent_credentials_missing'));

        expect($result)
            ->not
            ->toBeNull()
            ->and($result['status'])
            ->toBe('completed')
            ->and($tool->fresh()->credentials)
            ->toBe(['fields' => ['user', 'pass']]);
    });

    it('dispatches credential repair scripts through internal tool run without transitional fallback', function (): void {
        $executor = new ToolsFixerRecordingInternalExecutor([
            ToolsFixerRecordingInternalExecutor::result(stdout: '["user","pass"]'),
        ]);
        app()->instance(RunsInternalCommands::class, $executor);
        [, $tool] = createAgentToolForFixer();
        $shell = new ToolsFixerRemoteShell;

        $fixer = new ToolsFixer(
            catalog: makeToolsFixerAgentToolCatalog([
                'credentialsScript' => "echo '[\"user\",\"pass\"]'",
            ]),
        );

        $result = $fixer->fix($tool, agentToolDriftEntry('tool.agent_credentials_missing'));

        expect($result)
            ->toMatchArray([
                'family' => 'tool',
                'key' => 'tool.agent_credentials_missing',
                'status' => 'completed',
            ])
            ->and($executor->commands)
            ->toBe([InternalCommand::ToolRunScript->value])
            ->and($executor->payloads()[0]['action'] ?? null)
            ->toBe('credentials')
            ->and($tool->fresh()->credentials)
            ->toBe(['fields' => ['user', 'pass']])
            ->and($shell->scripts)
            ->toBe([]);
    });

    it('returns null when credential shell output is not a valid non-empty array', function (): void {
        $executor = new ToolsFixerRecordingInternalExecutor([
            ToolsFixerRecordingInternalExecutor::result(stdout: 'not-json'),
        ]);
        app()->instance(RunsInternalCommands::class, $executor);
        [, $tool] = createAgentToolForFixer();
        $shell = new ToolsFixerRemoteShell;

        $fixer = new ToolsFixer(
            catalog: makeToolsFixerAgentToolCatalog([
                'credentialsScript' => 'echo invalid',
            ]),
        );

        $result = $fixer->fix($tool, agentToolDriftEntry('tool.agent_credentials_missing'));

        expect($result)->toBeNull()->and($tool->fresh()->credentials)->toBeNull();
    });

    it('ensures the agent user through agent-push local executor', function (): void {
        use_tools_fixer_agent_push();

        [$node, $tool] = createAgentToolForFixer([
            'managed' => true,
            'wireguard_address' => '10.47.0.61',
        ]);
        $shell = new ToolsFixerRemoteShell;
        Http::preventStrayRequests();
        Http::fake([
            'http://10.47.0.61:9477/v1/commands' => tools_fixer_agent_response('agent-user.ensure', [
                'user' => 'agent',
                'created' => false,
                'locked' => true,
            ]),
        ]);

        $fixer = new ToolsFixer(
            catalog: makeToolsFixerAgentToolCatalog(),
        );

        $result = $fixer->fix($tool, agentToolDriftEntry('tool.agent_user_missing'));

        expect($result)
            ->not
            ->toBeNull()
            ->and($result['status'])
            ->toBe('completed')
            ->and($shell->calls)
            ->toBe([])
            ->and(tools_fixer_agent_requests('10.47.0.61'))
            ->toHaveCount(1)
            ->and(tools_fixer_agent_requests('10.47.0.61')[0]['argv'] ?? [])
            ->toContain('internal:agent-user:ensure')
            ->and($node->fresh()->name)
            ->toBe('agent-node');
    });
});

/**
 * @return array{0: Node, 1: NodeTool}
 */
function createAgentToolForFixer(array $nodeOverrides = []): array
{
    $node = Node::factory()->create([
        'name' => 'agent-node',
        'status' => 'active',
        'tld' => 'agent',
        ...$nodeOverrides,
    ]);
    $node->roleAssignments()->create([
        'role' => 'agent',
        'status' => 'active',
        'settings' => ['tld' => 'agent'],
    ]);
    $tool = NodeTool::factory()->create([
        'node_id' => $node->id,
        'name' => 'openclaw',
        'expected_state' => 'installed',
    ]);

    return [$node, $tool];
}

function agentToolDriftEntry(string $key): DriftEntry
{
    return new DriftEntry(
        family: 'tool',
        key: $key,
        kind: DriftKind::Missing,
        summary: 'Agent tool drift',
        detail: ['tool' => 'openclaw', 'domain' => 'openclaw.agent'],
    );
}

/**
 * @return array{target: array{type: string, value: string}, upstream: string, owner_name: string}
 */
function toolsFixerAgentRouteConfig(string $tool): array
{
    $upstream = 'http://host.docker.internal:8080';

    return [
        'target' => ['type' => 'upstream', 'value' => $upstream],
        'upstream' => $upstream,
        'owner_name' => $tool,
    ];
}

function toolsFixerAgentRouteSourceHash(Node $node, string $tool): string
{
    return app(ProxyRouteRenderer::class)->sourceHash(new ProxyRoute([
        'node_id' => $node->id,
        'domain' => "{$tool}.agent",
        'kind' => 'proxy',
        'owner_type' => 'tool',
        'config' => toolsFixerAgentRouteConfig($tool),
    ]));
}

function makeToolsFixerAgentToolCatalog(array $overrides = []): ToolCatalog
{
    $definition = new ToolsFixerAgentToolDefinition(
        categoryName: $overrides['category'] ?? 'agent',
        hasCredentialsCapability: $overrides['hasCredentials'] ?? true,
        credentialsScript: $overrides['credentialsScript'] ?? "echo '[\"user\",\"pass\"]'",
    );

    return new ToolCatalog(new ToolDefinitionRegistry([$definition]));
}

function tools_fixer_managed_file_sequence(
    string $path,
    string $content,
    string $mode = '0644',
): \Illuminate\Http\Client\ResponseSequence {
    return Http::sequence()
        ->push(tools_fixer_agent_response('managed-file.probe', [
            'exists' => false,
            'hash' => null,
            'mode' => null,
        ]))
        ->push(tools_fixer_agent_response('managed-file.write', [
            'path' => $path,
            'hash' => hash(algo: 'sha256', data: $content),
            'mode' => $mode,
        ]));
}

/**
 * @param  array<string, mixed>  $data
 * @return array<string, mixed>
 */
function tools_fixer_agent_response(string $operationId, array $data, int $exitCode = 0): array
{
    return [
        'transport' => 'agent-push',
        'operation_id' => $operationId,
        'binary' => 'orbit',
        'status' => $exitCode === 0 ? 'succeeded' : 'failed',
        'exit_code' => $exitCode,
        'frames' => [
            [
                'type' => 'stdout',
                'message' => json_encode([
                    'success' => [
                        'data' => $data,
                    ],
                ], JSON_THROW_ON_ERROR),
            ],
            [
                'type' => 'exit',
                'message' => (string) $exitCode,
            ],
        ],
    ];
}

/**
 * @return list<Request>
 */
function tools_fixer_agent_requests(string $wireguardAddress): array
{
    return Http::recorded(
        fn (Request $request): bool => $request->url() === "http://{$wireguardAddress}:9477/v1/commands",
    )
        ->map(fn (array $record): Request => $record[0])
        ->values()
        ->all();
}

/**
 * @return array<string, mixed>
 */
function tools_fixer_agent_payload(string $wireguardAddress, string $action): array
{
    foreach (tools_fixer_agent_requests($wireguardAddress) as $request) {
        if (($request['argv'][1] ?? null) !== $action) {
            continue;
        }

        /** @var array<string, mixed> */
        return json_decode((string) ($request['input'] ?? ''), associative: true, flags: JSON_THROW_ON_ERROR);
    }

    return [];
}

final class ToolsFixerRecordingInternalExecutor implements RunsInternalCommands
{
    /**
     * @var list<string>
     */
    public array $commands = [];

    /**
     * @var list<array<string, mixed>>
     */
    public array $transportOptions = [];

    /**
     * @param  list<RemoteShellResult>  $responses
     */
    public function __construct(
        private array $responses = [],
    ) {}

    /**
     * @param  array<int|string, mixed>  $arguments
     * @param  array<int|string, mixed>  $commandOptions
     * @param  array<string, mixed>  $transportOptions
     */
    public function runInternal(
        Node $node,
        string $commandName,
        array $arguments = [],
        array $commandOptions = [],
        array $transportOptions = [],
    ): RemoteShellResult {
        $this->commands[] = $commandName;
        $this->transportOptions[] = $transportOptions;

        return array_shift($this->responses) ?? self::result();
    }

    public static function result(
        int $exitCode = 0,
        string $stdout = '',
        string $stderr = '',
        int $durationMs = 1,
    ): RemoteShellResult {
        return new RemoteShellResult(
            exitCode: 0,
            stdout: json_encode(JsonEnvelope::success([
                'exit_code' => $exitCode,
                'stdout' => $stdout,
                'stderr' => $stderr,
                'duration_ms' => $durationMs,
            ]), JSON_THROW_ON_ERROR),
            stderr: '',
            durationMs: $durationMs,
        );
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function payloads(): array
    {
        return array_map(
            static function (array $options): array {
                /** @var mixed $payload */
                $payload = json_decode(
                    (string) ($options['input'] ?? ''),
                    associative: true,
                    flags: JSON_THROW_ON_ERROR,
                );

                if (! is_array($payload)) {
                    return [];
                }

                /** @var array<string, mixed> $payload */
                return $payload;
            },
            $this->transportOptions,
        );
    }
}

final class ToolsFixerRemoteShell implements RemoteShell
{
    /**
     * @var list<string>
     */
    public array $scripts = [];

    /**
     * @var list<array<string, mixed>>
     */
    public array $payloads = [];

    /**
     * @var list<array{command: string, result: RemoteShellResult}>
     */
    public array $calls = [];

    /**
     * @param  array<string, RemoteShellResult>  $responses
     */
    public function __construct(
        private readonly array $responses = [],
    ) {
        app()->instance(RemoteShell::class, $this);
    }

    /**
     * @param  array<string, mixed>  $options
     */
    public function run(Node $node, string $script, array $options = []): RemoteShellResult
    {
        $payload = tools_fixer_decode_input($options['input'] ?? null);
        $result = $this->responses[$script] ?? new RemoteShellResult(
            exitCode: 0,
            stdout: '',
            stderr: '',
            durationMs: 1,
        );

        $this->scripts[] = tools_fixer_synthetic_script($script, $payload);
        $this->payloads[] = $payload;
        $this->calls[] = ['command' => $script, 'result' => $result];

        return $result;
    }
}

/**
 * @return array<string, mixed>
 */
function tools_fixer_decode_input(mixed $input): array
{
    if (! is_string($input) || $input === '') {
        return [];
    }

    /** @var mixed $payload */
    $payload = json_decode($input, associative: true);

    return is_array($payload) ? $payload : [];
}

/**
 * @param  array<string, mixed>  $payload
 */
function tools_fixer_synthetic_script(string $script, array $payload): string
{
    if ($payload === []) {
        return $script;
    }

    $synthetic =
        $script
        ."\n# ORBIT_TEST_PAYLOAD "
        .base64_encode(json_encode($payload, JSON_THROW_ON_ERROR))
        ."\n# ORBIT_TEST_JSON "
        .json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);

    if (str_contains($script, "internal:caddy-config 'apply-container'")) {
        $synthetic .= "\ndocker container inspect\ndocker run\ndocker start\nexpected_hash=\norbit.caddy.spec_hash";
    }

    return $synthetic;
}

final class ToolsFixerAgentToolDefinition implements ToolDefinition
{
    public function __construct(
        private readonly string $slugName = 'openclaw',
        private readonly string $categoryName = 'agent',
        private readonly bool $hasCredentialsCapability = true,
        private readonly ?string $credentialsScript = "echo '[\"user\",\"pass\"]'",
    ) {}

    public function slug(): string
    {
        return $this->slugName;
    }

    public function category(): string
    {
        return $this->categoryName;
    }

    public function capabilities(): array
    {
        if (! $this->hasCredentialsCapability) {
            return [];
        }

        return ['credentials'];
    }

    public function hasCapability(string $capability): bool
    {
        return in_array($capability, $this->capabilities(), true);
    }

    public function credentialsScript(array $context = []): ?string
    {
        return $this->credentialsScript;
    }

    public function reconfigureScript(array $config = []): ?string
    {
        return null;
    }

    public function bootstrapRole(): ?string
    {
        return null;
    }

    public function supportedOperatingSystems(): array
    {
        return ['linux'];
    }

    public function requiredContainerProvider(): ?string
    {
        return null;
    }

    public function runtimeUser(): ?string
    {
        return null;
    }

    public function requiresRouteTld(): bool
    {
        return false;
    }

    public function isolation(): ?string
    {
        return null;
    }

    public function gatewayLocal(): bool
    {
        return false;
    }

    public function installScript(array $config = []): ?string
    {
        return null;
    }

    public function removeScript(array $config = []): ?string
    {
        return null;
    }

    public function updateScript(array $config = []): ?string
    {
        return null;
    }

    public function startScript(array $config = []): ?string
    {
        return null;
    }

    public function stopScript(array $config = []): ?string
    {
        return null;
    }

    public function restartScript(array $config = []): ?string
    {
        return null;
    }

    public function reloadScript(array $config = []): ?string
    {
        return null;
    }

    public function latestSupportedVersion(): ?string
    {
        return null;
    }

    public function relatedProcess(): ?array
    {
        return null;
    }

    public function probeMetadata(): array
    {
        return [];
    }
}
