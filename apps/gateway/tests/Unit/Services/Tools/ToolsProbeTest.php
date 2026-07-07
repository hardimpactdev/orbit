<?php

declare(strict_types=1);

use App\Contracts\RemoteShell;
use App\Data\Doctor\ProbeSnapshot;
use App\Data\RemoteShell\RemoteShellResult;
use App\Enums\DriftKind;
use App\Enums\Nodes\NodeStatus;
use App\Models\Node;
use App\Models\NodeTool;
use App\Models\ProxyRoute;
use App\Services\ActivityLogger;
use App\Services\NodeCommandTransport\NodeTransportPreference;
use App\Services\Operations\OperationRunRecorder;
use App\Services\Operations\OperationTokenFactory;
use App\Services\Proxy\ProxyRouteRenderer;
use App\Services\RemoteShell\LocalExecutorCommandBuilder;
use App\Services\RemoteShell\RemoteExecutor;
use App\Services\RemoteShell\RemoteLocalExecutor;
use App\Services\Runtime\OrbitCaddyContainer;
use App\Services\Runtime\OrbitContainerNames;
use App\Services\Tools\ToolCatalog;
use App\Services\Tools\ToolDefinitionRegistry;
use App\Services\Tools\ToolsProbe;
use App\Tools\BaseTool;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;
use Tests\TestCase;

uses(TestCase::class);
uses(RefreshDatabase::class);

function toolProbeIssue(array $drift, string $key): mixed
{
    return collect($drift)->first(fn ($entry): bool => $entry->key === $key);
}

function createToolsProbeAppHostNode(array $attributes = []): Node
{
    return createTestAppHostNode([
        'status' => 'active',
        ...$attributes,
    ]);
}

function createToolsProbeAgentNode(): Node
{
    $node = Node::factory()->create([
        'status' => 'active',
        'tld' => 'agent',
    ]);
    $node->roleAssignments()->create([
        'role' => 'agent',
        'status' => 'active',
        'settings' => ['tld' => 'agent'],
    ]);

    return $node;
}

function toolsProbeWithRemoteShell(RemoteShell $remoteShell, ?ToolCatalog $catalog = null): ToolsProbe
{
    app()->instance(RemoteShell::class, $remoteShell);

    return new ToolsProbe($remoteShell, $catalog);
}

function toolsProbeWithAgentPush(RemoteShell $remoteShell): ToolsProbe
{
    return new ToolsProbe(
        remoteShell: $remoteShell,
        localExecutor: toolsProbeLocalExecutor(NodeTransportPreference::Auto),
    );
}

function toolsProbeLocalExecutor(NodeTransportPreference $defaultTransportPreference): RemoteLocalExecutor
{
    $secret = config('app.key');

    if (! is_string($secret) || trim($secret) === '') {
        throw new RuntimeException('Application key is not configured for operation token signing.');
    }

    return new RemoteLocalExecutor(
        transport: app(RemoteExecutor::class),
        commands: app(LocalExecutorCommandBuilder::class),
        operationTokens: app(OperationTokenFactory::class),
        activityLogger: app(ActivityLogger::class),
        operationRuns: app(OperationRunRecorder::class),
        operationTokenSecret: $secret,
        defaultTransportPreference: $defaultTransportPreference,
    );
}

/**
 * @return array{target: array{type: string, value: string}, upstream: string, owner_name: string}
 */
function toolsProbeAgentRouteConfig(string $tool): array
{
    $upstream = 'http://host.docker.internal:8080';

    return [
        'target' => ['type' => 'upstream', 'value' => $upstream],
        'upstream' => $upstream,
        'owner_name' => $tool,
    ];
}

function toolsProbeAgentRouteSourceHash(Node $node, string $tool): string
{
    return app(ProxyRouteRenderer::class)->sourceHash(new ProxyRoute([
        'node_id' => $node->id,
        'domain' => "{$tool}.agent",
        'kind' => 'proxy',
        'owner_type' => 'tool',
        'config' => toolsProbeAgentRouteConfig($tool),
    ]));
}

function toolsProbeCapabilityStdout(string $path, string $version = '', string $state = 'running'): string
{
    return implode("\t", [$path, $version, $state, '', '', '', '', '', '', ''])."\n";
}

function toolsProbeDockerProviderStdout(
    string $path,
    string $version = 'Docker version 27.0.0',
    bool $providerReachable = true,
    string $providerError = '',
): string {
    return implode("\t", [
        $path,
        $version,
        'unknown',
        '',
        '',
        '',
        '',
        '',
        '',
        '',
        $providerReachable ? '1' : '0',
        $providerError,
    ])."\n";
}

function toolsProbeManagedFileStdout(bool $exists, ?string $hash, ?string $mode): string
{
    return json_encode([
        'success' => [
            'data' => [
                'exists' => $exists,
                'hash' => $hash,
                'mode' => $mode,
            ],
        ],
    ], JSON_THROW_ON_ERROR)."\n";
}

function tools_probe_agent_runtime_response(array $data): mixed
{
    return Http::response([
        'transport' => 'agent-push',
        'operation_id' => 'tool-agent-runtime.probe',
        'binary' => 'orbit',
        'status' => 'succeeded',
        'exit_code' => 0,
        'frames' => [
            [
                'type' => 'stdout',
                'message' => json_encode([
                    'success' => [
                        'data' => $data,
                        'meta' => [],
                    ],
                ], JSON_THROW_ON_ERROR),
            ],
            [
                'type' => 'exit',
                'message' => '0',
            ],
        ],
    ]);
}

function tools_probe_agent_runtime_request_matches(Request $request, string $url): bool
{
    return (
        $request->url() === $url
        && $request['binary'] === 'orbit'
        && $request['operation_id'] === 'tool-agent-runtime.probe'
        && $request['timeout_seconds'] === 10
        && $request['argv'][0] === 'internal:agent-runtime:probe'
        && str_starts_with((string) $request['argv'][1], '--operation-token=')
        && $request['argv'][2] === '--json'
    );
}

describe('ToolsProbe', function (): void {
    it('has key and label', function (): void {
        $probe = new ToolsProbe;

        expect($probe->key())->toBe('tool')->and($probe->label())->toBe('Tools');
    });

    it('detects incomplete tool records', function (): void {
        $node = createToolsProbeAppHostNode();
        $tool = NodeTool::factory()->create([
            'node_id' => $node->id,
            'name' => '',
            'expected_state' => '',
        ]);

        $drift = new ToolsProbe()->diff($tool, new ProbeSnapshot([]));

        expect(toolProbeIssue($drift, 'tool.record_incomplete')?->kind)->toBe(DriftKind::Missing);
    });

    it('requires active app or gateway nodes', function (): void {
        $node = Node::factory()->create(['status' => 'active']);
        $tool = NodeTool::factory()->create(['node_id' => $node->id, 'name' => 'composer']);

        $drift = new ToolsProbe()->diff($tool, new ProbeSnapshot([]));

        expect(toolProbeIssue($drift, 'tool.node_invalid')?->kind)->toBe(DriftKind::Divergent);
    });

    it('allows managed caddy on ingress nodes', function (): void {
        $node = Node::factory()->ingress()->create(['status' => 'active']);
        $tool = NodeTool::factory()->create(['node_id' => $node->id, 'name' => 'caddy']);

        $drift = new ToolsProbe()->diff($tool, new ProbeSnapshot([]));

        expect(toolProbeIssue($drift, 'tool.node_invalid'))
            ->toBeNull()
            ->and(toolProbeIssue($drift, 'tool.capability_missing')?->kind)
            ->toBe(DriftKind::Missing);
    });

    it('allows managed caddy on active agent nodes', function (): void {
        $node = createToolsProbeAgentNode();
        $container = OrbitCaddyContainer::forPrivateNode('10.6.0.50');
        $tool = NodeTool::factory()->create([
            'node_id' => $node->id,
            'name' => 'caddy',
            'expected_state' => 'installed',
            'config' => ['container' => $container->spec()],
        ]);
        $probe = new ToolsProbe(new ToolsProbeRemoteShell(
            exitCode: 0,
            stdout: "/usr/bin/docker\tDocker version 27.0.0\tunknown\t\t\t\t\t0\tmissing\t\n",
        ));

        $drift = $probe->diff($tool, $probe->introspect($tool));

        expect(toolProbeIssue($drift, 'tool.node_invalid'))
            ->toBeNull()
            ->and(toolProbeIssue($drift, 'tool.container_missing')?->kind)
            ->toBe(DriftKind::Missing);
    });

    it('allows metrics node exporter on ingress nodes', function (): void {
        $node = Node::factory()->ingress()->create(['status' => 'active']);
        $tool = NodeTool::factory()->create(['node_id' => $node->id, 'name' => 'node-exporter']);

        $drift = new ToolsProbe()->diff($tool, new ProbeSnapshot([]));

        expect(toolProbeIssue($drift, 'tool.node_invalid'))
            ->toBeNull()
            ->and(toolProbeIssue($drift, 'tool.capability_missing')?->kind)
            ->toBe(DriftKind::Missing);
    });

    it('does not allow non-caddy managed tools on ingress-only nodes', function (): void {
        $node = Node::factory()->ingress()->create(['status' => 'active']);
        $tool = NodeTool::factory()->create(['node_id' => $node->id, 'name' => 'composer']);

        $drift = new ToolsProbe()->diff($tool, new ProbeSnapshot([]));

        expect(toolProbeIssue($drift, 'tool.node_invalid')?->kind)->toBe(DriftKind::Divergent);
    });

    it('allows provisioning app nodes during managed setup', function (): void {
        $node = createToolsProbeAppHostNode(['status' => NodeStatus::Provisioning]);
        $tool = NodeTool::factory()->create(['node_id' => $node->id, 'name' => 'composer']);

        $drift = new ToolsProbe()->diff($tool, new ProbeSnapshot([]), allowProvisioning: true);

        expect(toolProbeIssue($drift, 'tool.node_invalid'))
            ->toBeNull()
            ->and(toolProbeIssue($drift, 'tool.capability_missing')?->kind)
            ->toBe(DriftKind::Missing);
    });

    it('requires known tool catalog definitions', function (): void {
        $node = createToolsProbeAppHostNode();
        $tool = NodeTool::factory()->create(['node_id' => $node->id, 'name' => 'not-a-tool']);

        $drift = new ToolsProbe()->diff($tool, new ProbeSnapshot([]));

        expect(toolProbeIssue($drift, 'tool.definition_missing')?->kind)->toBe(DriftKind::Missing);
    });

    it('detects missing live capabilities', function (): void {
        $node = createToolsProbeAppHostNode();
        $tool = NodeTool::factory()->create(['node_id' => $node->id, 'name' => 'composer']);
        $probe = new ToolsProbe(new ToolsProbeRemoteShell(exitCode: 1));

        $snapshot = $probe->introspect($tool);
        $drift = $probe->diff($tool, $snapshot);

        expect(toolProbeIssue($drift, 'tool.capability_missing')?->kind)->toBe(DriftKind::Missing);
    });

    it('passes when live capability exists', function (): void {
        $node = createToolsProbeAppHostNode();
        $tool = NodeTool::factory()->create(['node_id' => $node->id, 'name' => 'composer']);
        $probe = new ToolsProbe(new ToolsProbeRemoteShell(exitCode: 0, stdout: "/usr/local/bin/composer\n"));

        $snapshot = $probe->introspect($tool);

        expect($probe->diff($tool, $snapshot))->toBe([]);
    });

    it('checks absolute binary metadata as an executable path instead of a PATH lookup', function (): void {
        $node = createToolsProbeAppHostNode();
        $tool = NodeTool::factory()->create(['node_id' => $node->id, 'name' => 'php-cli']);
        $shell = new RecordingToolsProbeRemoteShell(
            exitCode: 1,
            stdout: '',
        );
        $probe = toolsProbeWithRemoteShell($shell);

        $probe->introspect($tool);

        $input = json_decode($shell->input, associative: true, flags: JSON_THROW_ON_ERROR);

        expect($input['binary'])
            ->toBe('/opt/orbit/php/8.5/bin/php')
            ->and($shell->script)
            ->toStartWith('set -eu')
            ->and($shell->script)
            ->toContain('case "$binary" in')
            ->and($shell->script)
            ->toContain('[ -x "$binary" ]')
            ->and($shell->script)
            ->toContain('command -v');
    });

    it('probes Claude Code through the persisted default install user', function (): void {
        $node = createToolsProbeAppHostNode(['user' => 'deploy']);
        $tool = NodeTool::factory()->create([
            'node_id' => $node->id,
            'name' => 'claude-code',
            'config' => [
                'default_user' => 'deploy',
                'install_users' => ['agent'],
            ],
        ]);
        $shell = new RecordingToolsProbeRemoteShell(
            exitCode: 0,
            stdout: toolsProbeCapabilityStdout('/home/deploy/.local/bin/claude', version: '2.1.89'),
        );
        $probe = toolsProbeWithRemoteShell($shell);

        $snapshot = $probe->introspect($tool);
        $input = json_decode($shell->input, associative: true, flags: JSON_THROW_ON_ERROR);

        expect($input)
            ->toMatchArray([
                'binary' => '/home/deploy/.local/bin/claude',
                'version_command' => "sudo -u 'deploy' -H bash -lc '/home/deploy/.local/bin/claude --version'",
            ])
            ->and($snapshot->get('claude-code'))
            ->toMatchArray([
                'installed' => true,
                'path' => '/home/deploy/.local/bin/claude',
                'version' => '2.1.89',
            ]);
    });

    it('does not contain host-lane php eval probe snippets', function (): void {
        expect(file_get_contents(app_path('Services/Tools/ToolsProbe.php')))->not->toContain('php -r');
    });

    it('does not carry inert PHP payload markers in shell probe scripts', function (): void {
        expect(file_get_contents(app_path('Services/Tools/ToolsProbe.php')))
            ->not
            ->toContain('json_decode(stream_get_contents(STDIN), true);');
    });

    it('runs metadata extra probe commands after binary resolution and treats probe failure as not installed', function (): void {
        $node = createToolsProbeAppHostNode();
        $tool = NodeTool::factory()->create(['node_id' => $node->id, 'name' => 'orbstack-probe']);
        $orbstackCatalog = new ToolCatalog(new ToolDefinitionRegistry([
            new class extends BaseTool {
                public function slug(): string
                {
                    return 'orbstack-probe';
                }

                public function probeMetadata(): array
                {
                    return [
                        'binary' => '/bin/sh',
                        'version_command' => '/bin/sh -c "echo OrbStack 1.0.0"',
                        'probe' => 'test -x /usr/local/bin/orbctl && test -d /Applications/OrbStack.app',
                    ];
                }
            },
        ]));
        $recordingShell = new RecordingToolsProbeRemoteShell(
            exitCode: 0,
            stdout: toolsProbeCapabilityStdout('/bin/sh', version: 'OrbStack 1.0.0'),
        );
        $probe = new ToolsProbe($recordingShell, $orbstackCatalog);

        $probe->introspect($tool);

        expect($recordingShell->script)
            ->toContain('extra_probe=')
            ->and($recordingShell->script)
            ->toContain('test -x /usr/local/bin/orbctl')
            ->and($recordingShell->script)
            ->toContain('/Applications/OrbStack.app')
            ->and($recordingShell->script)
            ->toContain('sh -c "$extra_probe"');

        $failingCatalog = new ToolCatalog(new ToolDefinitionRegistry([
            new class extends BaseTool {
                public function slug(): string
                {
                    return 'orbstack-probe';
                }

                public function probeMetadata(): array
                {
                    return [
                        'binary' => '/bin/sh',
                        'version_command' => '/bin/sh -c "echo OrbStack 1.0.0"',
                        'probe' => 'test -e /orbit-orbstack-extra-probe-sentinel-does-not-exist',
                    ];
                }
            },
        ]));
        $snapshot = new ToolsProbe(new ExecutingToolsProbeRemoteShell, $failingCatalog)->introspect($tool);

        expect($snapshot->get('orbstack-probe'))
            ->toMatchArray([
                'installed' => false,
            ]);
    });

    it('runs metadata extra probe commands in batched capability probes and treats probe failure as not installed', function (): void {
        $node = createToolsProbeAppHostNode();
        $tool = NodeTool::factory()->create(['node_id' => $node->id, 'name' => 'orbstack-probe']);
        $orbstackCatalog = new ToolCatalog(new ToolDefinitionRegistry([
            new class extends BaseTool {
                public function slug(): string
                {
                    return 'orbstack-probe';
                }

                public function probeMetadata(): array
                {
                    return [
                        'binary' => '/bin/sh',
                        'version_command' => '/bin/sh -c "echo OrbStack 1.0.0"',
                        'probe' => 'test -x /usr/local/bin/orbctl && test -d /Applications/OrbStack.app',
                    ];
                }
            },
        ]));
        $recordingShell = new RecordingToolsProbeRemoteShell;
        $probe = new ToolsProbe($recordingShell, $orbstackCatalog);

        $probe->introspectMany([$tool]);

        expect($recordingShell->script)
            ->toContain('# orbit-tool-probe:capability-batch')
            ->and($recordingShell->script)
            ->toContain('extra_probe=')
            ->and($recordingShell->script)
            ->toContain('test -x /usr/local/bin/orbctl')
            ->and($recordingShell->script)
            ->toContain('/Applications/OrbStack.app')
            ->and($recordingShell->script)
            ->toContain('sh -c "$extra_probe"');

        $failingCatalog = new ToolCatalog(new ToolDefinitionRegistry([
            new class extends BaseTool {
                public function slug(): string
                {
                    return 'orbstack-probe';
                }

                public function probeMetadata(): array
                {
                    return [
                        'binary' => '/bin/sh',
                        'version_command' => '/bin/sh -c "echo OrbStack 1.0.0"',
                        'probe' => 'test -e /orbit-orbstack-extra-probe-sentinel-does-not-exist',
                    ];
                }
            },
        ]));
        $snapshot = new ToolsProbe(new ExecutingToolsProbeRemoteShell, $failingCatalog)
            ->introspectMany([$tool])['orbstack-probe'];

        expect($snapshot->get('orbstack-probe'))
            ->toMatchArray([
                'installed' => false,
            ]);
    });

    it('uses POSIX shell for single tool capability probes while preserving tab output parsing', function (): void {
        $node = createToolsProbeAppHostNode();
        $tool = NodeTool::factory()->create(['node_id' => $node->id, 'name' => 'composer']);
        $shell = new RecordingToolsProbeRemoteShell(
            exitCode: 0,
            stdout: "/usr/local/bin/composer\tComposer version 2.8.0\tstopped\t\t\t\t\t\t\t\n",
        );
        $probe = toolsProbeWithRemoteShell($shell);

        $snapshot = $probe->introspect($tool);

        expect($shell->script)
            ->not
            ->toContain('php -r')
            ->and($shell->script)
            ->toContain('# orbit-tool-probe:capability')
            ->and($shell->script)
            ->toContain('printf \'%s\\t%s\\t%s\\t%s\\t%s\\t%s\\t%s\\t%s\\t%s\\t%s\\t%s\\t%s\\n\'')
            ->and($snapshot->get('composer'))
            ->toMatchArray([
                'installed' => true,
                'path' => '/usr/local/bin/composer',
                'version' => 'Composer version 2.8.0',
                'state' => 'stopped',
            ]);
    });

    it('probes Docker provider reachability on macOS without a systemd service intent', function (): void {
        $node = createToolsProbeAppHostNode(['platform' => 'macos_14']);
        $tool = NodeTool::factory()->create(['node_id' => $node->id, 'name' => 'docker']);
        $shell = new RecordingToolsProbeRemoteShell(
            exitCode: 0,
            stdout: toolsProbeDockerProviderStdout('/opt/homebrew/bin/docker'),
        );
        $probe = toolsProbeWithRemoteShell($shell);

        $snapshot = $probe->introspect($tool);
        $input = json_decode($shell->input, associative: true, flags: JSON_THROW_ON_ERROR);

        expect($input)
            ->toMatchArray([
                'binary' => 'docker',
                'version_command' => 'docker --version',
                'service' => '',
                'provider_command' => 'docker info',
            ])
            ->and($snapshot->get('docker'))
            ->toMatchArray([
                'installed' => true,
                'path' => '/opt/homebrew/bin/docker',
                'version' => 'Docker version 27.0.0',
                'provider_reachable' => true,
            ]);
    });

    it('reports Colima remediation when Docker is present but no provider is reachable on macOS', function (): void {
        $node = createToolsProbeAppHostNode(['platform' => 'macos_14']);
        $tool = NodeTool::factory()->create(['node_id' => $node->id, 'name' => 'docker']);
        $probe = new ToolsProbe;

        $drift = $probe->diff($tool, new ProbeSnapshot([
            'docker' => [
                'installed' => true,
                'path' => '/opt/homebrew/bin/docker',
                'version' => 'Docker version 27.0.0',
                'provider_reachable' => false,
                'provider_error' => 'Cannot connect to the Docker daemon',
            ],
        ]));

        expect(toolProbeIssue($drift, 'tool.docker_provider_unreachable')?->kind)
            ->toBe(DriftKind::Missing)
            ->and(toolProbeIssue($drift, 'tool.docker_provider_unreachable')?->summary)
            ->toContain('Docker-compatible provider')
            ->and(toolProbeIssue($drift, 'tool.docker_provider_unreachable')?->detail)
            ->toMatchArray([
                'tool' => 'docker',
                'provider' => 'docker-compatible',
                'recommended_provider' => 'colima',
                'remediation_commands' => [
                    'brew install docker colima',
                    'colima start --runtime docker',
                ],
            ]);
    });

    it('frankenphp probes approved Docker image inventory for the PHP tool instead of host PHP', function (): void {
        $node = createToolsProbeAppHostNode();
        $tool = NodeTool::factory()->create(['node_id' => $node->id, 'name' => 'php']);
        $shell = new RecordingToolsProbeRemoteShell(
            exitCode: 0,
            stdout: "ghcr.io/hardimpactdev/orbit-frankenphp:1-php8.5-bookworm\n",
        );
        $probe = new ToolsProbe($shell);

        $snapshot = $probe->introspect($tool);

        expect($shell->script)
            ->toContain('docker image inspect')
            ->not
            ->toContain('command -v php')
            ->and($shell->input)
            ->toContain('ghcr.io/hardimpactdev/orbit-frankenphp:1-php8.5-bookworm')
            ->and($snapshot->get('php'))
            ->toMatchArray([
                'installed' => true,
                'version' => '8.5',
                'images' => ['ghcr.io/hardimpactdev/orbit-frankenphp:1-php8.5-bookworm'],
            ])
            ->and($probe->diff($tool, $snapshot))
            ->toBe([]);
    });

    it('frankenphp does not accept host PHP output as PHP tool capability', function (): void {
        $node = createToolsProbeAppHostNode();
        $tool = NodeTool::factory()->create(['node_id' => $node->id, 'name' => 'php']);
        $probe = new ToolsProbe(new ToolsProbeRemoteShell(
            exitCode: 0,
            stdout: "/usr/bin/php\t8.5.0\n",
        ));

        $snapshot = $probe->introspect($tool);
        $drift = $probe->diff($tool, $snapshot);

        expect($snapshot->get('php'))
            ->toMatchArray([
                'installed' => false,
                'images' => [],
            ])
            ->and(toolProbeIssue($drift, 'tool.capability_missing')?->kind)
            ->toBe(DriftKind::Missing);
    });

    it('detects version drift when the catalog tracks versions', function (): void {
        $node = createToolsProbeAppHostNode();
        $tool = NodeTool::factory()->create([
            'node_id' => $node->id,
            'name' => 'composer',
            'expected_version' => '2.8',
        ]);
        $probe = new ToolsProbe(new ToolsProbeRemoteShell(
            exitCode: 0,
            stdout: "/usr/local/bin/composer\tComposer version 2.7.0\n",
        ));

        $snapshot = $probe->introspect($tool);
        $drift = $probe->diff($tool, $snapshot);

        expect(toolProbeIssue($drift, 'tool.version_mismatch')?->kind)
            ->toBe(DriftKind::Divergent)
            ->and(toolProbeIssue($drift, 'tool.version_mismatch')?->detail)
            ->toMatchArray([
                'expected_version' => '2.8',
                'observed_version' => 'Composer version 2.7.0',
            ]);
    });

    it('treats Claude Code channel targets as installer aliases instead of exact live versions', function (): void {
        $node = createToolsProbeAgentNode();
        $tool = NodeTool::factory()->create([
            'node_id' => $node->id,
            'name' => 'claude-code',
            'expected_version' => 'stable',
            'config' => [
                'default_user' => 'orbit',
                'install_users' => ['agent'],
            ],
        ]);
        $probe = new ToolsProbe(new ToolsProbeRemoteShell(
            exitCode: 0,
            stdout: toolsProbeCapabilityStdout('/home/orbit/.local/bin/claude', version: '2.1.181 (Claude Code)'),
        ));

        $snapshot = $probe->introspect($tool);
        $drift = $probe->diff($tool, $snapshot);

        expect($drift)->toBe([]);
    });

    it('does not emit tool.lifecycle_state_mismatch when a service-backed tool is stopped', function (): void {
        $node = createToolsProbeAppHostNode();
        $tool = NodeTool::factory()->create([
            'node_id' => $node->id,
            'name' => 'composer',
            'expected_state' => 'installed',
        ]);
        // Probe reports binary present but runtime state stopped — must produce no tool issue code
        $probe = new ToolsProbe(new ToolsProbeRemoteShell(
            exitCode: 0,
            stdout: "/usr/local/bin/composer\tComposer version 2.8.0\tstopped\n",
        ));

        $snapshot = $probe->introspect($tool);
        $drift = $probe->diff($tool, $snapshot);

        $codes = array_column($drift, null);
        $issueKeys = array_map(fn ($entry) => $entry->key, $drift);

        expect(in_array('tool.lifecycle_state_mismatch', $issueKeys, true))
            ->toBeFalse()
            ->and($probe->diff($tool, $snapshot))
            ->toBe([]);
    });

    it('does not produce any tool issue code when a tool is installed but its backing service is not running', function (): void {
        $node = createToolsProbeAppHostNode();
        $tool = NodeTool::factory()->create([
            'node_id' => $node->id,
            'name' => 'composer',
            'expected_state' => 'installed',
        ]);
        // Service down: binary exists, state is stopped — runtime state is process-family fact
        $probe = new ToolsProbe(new ToolsProbeRemoteShell(
            exitCode: 0,
            stdout: "/usr/local/bin/composer\tComposer version 2.8.0\tstopped\n",
        ));

        $snapshot = $probe->introspect($tool);
        $drift = $probe->diff($tool, $snapshot);

        expect($drift)->toBe([]);
    });

    it('only accepts installed and absent as valid expected_state values', function (): void {
        $node = createToolsProbeAppHostNode();
        // Deliberately write an illegal expected_state value that the old contract allowed
        $tool = NodeTool::factory()->make([
            'node_id' => $node->id,
            'name' => 'composer',
        ]);
        $tool->expected_state = 'running'; // bypasses factory default to test validation
        $tool->save();

        $drift = new ToolsProbe()->diff($tool, new ProbeSnapshot([]));

        expect(collect($drift)->first(fn ($entry) => $entry->key === 'tool.record_incomplete'))->not->toBeNull();
    });

    it('inspects agent IDE server capability without probing process lifecycle', function (): void {
        $node = createToolsProbeAppHostNode();
        $tool = NodeTool::factory()->create([
            'node_id' => $node->id,
            'name' => 'opencode-cli',
            'expected_state' => 'installed',
        ]);
        $shell = new RecordingToolsProbeRemoteShell(
            exitCode: 0,
            stdout: "/home/orbit/.opencode/bin/opencode\t\tunknown\t\t\t\t\t\t\t\n",
        );
        $probe = new ToolsProbe($shell);

        $snapshot = $probe->introspect($tool);
        $input = json_decode($shell->input, associative: true, flags: JSON_THROW_ON_ERROR);

        expect($input)
            ->not
            ->toHaveKey('supervisor_program')
            ->and($snapshot->get('opencode-cli'))
            ->toMatchArray([
                'installed' => true,
                'path' => '/home/orbit/.opencode/bin/opencode',
            ]);
    });

    it('detects stopped orbit-caddy containers instead of only checking the docker binary', function (): void {
        withE2EEnvironment(
            ['ORBIT_E2E_DOCKER_NETWORK'],
            [
                'ORBIT_E2E_DOCKER_NETWORK' => 'orbit-e2e-dev-abc123',
            ],
            function (): void {
                $node = createToolsProbeAppHostNode();
                $container = OrbitCaddyContainer::forPrivateNode('10.6.0.50', OrbitContainerNames::forNodeScope('dev'));
                $tool = NodeTool::factory()->create([
                    'node_id' => $node->id,
                    'name' => 'caddy',
                    'expected_state' => 'installed',
                    'config' => ['container' => $container->spec()],
                ]);
                $shell = new RecordingToolsProbeRemoteShell(
                    exitCode: 0,
                    stdout: "/usr/bin/docker\tDocker version 27.0.0\tunknown\t\t\t\t\t1\tstopped\t{$container->specHash()}\n",
                );
                $probe = new ToolsProbe($shell);

                $snapshot = $probe->introspect($tool);
                $drift = $probe->diff($tool, $snapshot);
                $input = json_decode($shell->input, associative: true, flags: JSON_THROW_ON_ERROR);

                $issueKeys = array_map(fn ($entry) => $entry->key, $drift);

                expect($shell->script)
                    ->toContain('docker container inspect')
                    ->toContain('.State.Restarting')
                    ->and($input['container'])
                    ->toBe($container->name())
                    ->and($input['container'])
                    ->toBe('orbit-e2e-dev-abc123-dev-orbit-caddy')
                    ->and($issueKeys)
                    ->toContain('tool.container_not_running')
                    ->and(toolProbeIssue($drift, 'tool.container_not_running')?->detail)
                    ->toMatchArray([
                        'container' => 'orbit-e2e-dev-abc123-dev-orbit-caddy',
                        'observed_state' => 'stopped',
                    ]);
            },
        );
    });

    it('detects restarting orbit-caddy containers as not running', function (): void {
        $node = createToolsProbeAppHostNode();
        $container = OrbitCaddyContainer::forPrivateNode('10.6.0.50');
        $tool = NodeTool::factory()->create([
            'node_id' => $node->id,
            'name' => 'caddy',
            'expected_state' => 'installed',
            'config' => ['container' => $container->spec()],
        ]);
        $shell = new RecordingToolsProbeRemoteShell(
            exitCode: 0,
            stdout: "/usr/bin/docker\tDocker version 27.0.0\tunknown\t\t\t\t\t1\trestarting\t{$container->specHash()}\n",
        );
        $probe = new ToolsProbe($shell);

        $drift = $probe->diff($tool, $probe->introspect($tool));

        expect(toolProbeIssue($drift, key: 'tool.container_not_running')?->detail)
            ->toMatchArray([
                'container' => 'orbit-caddy',
                'observed_state' => 'restarting',
            ]);
    });

    it('detects missing orbit-caddy containers separately from missing docker capability', function (): void {
        $node = createToolsProbeAppHostNode();
        $container = OrbitCaddyContainer::forPrivateNode('10.6.0.50');
        $tool = NodeTool::factory()->create([
            'node_id' => $node->id,
            'name' => 'caddy',
            'expected_state' => 'installed',
            'config' => ['container' => $container->spec()],
        ]);
        $probe = new ToolsProbe(new ToolsProbeRemoteShell(
            exitCode: 0,
            stdout: "/usr/bin/docker\tDocker version 27.0.0\tunknown\t\t\t\t\t0\tmissing\t\n",
        ));

        $drift = $probe->diff($tool, $probe->introspect($tool));

        expect(toolProbeIssue($drift, 'tool.container_missing')?->kind)
            ->toBe(DriftKind::Missing)
            ->and(toolProbeIssue($drift, 'tool.capability_missing'))
            ->toBeNull();
    });

    it('detects orbit-caddy container spec hash drift', function (): void {
        $node = createToolsProbeAppHostNode();
        $container = OrbitCaddyContainer::forPrivateNode('10.6.0.50');
        $tool = NodeTool::factory()->create([
            'node_id' => $node->id,
            'name' => 'caddy',
            'expected_state' => 'installed',
            'config' => ['container' => $container->spec()],
        ]);
        $probe = new ToolsProbe(new ToolsProbeRemoteShell(
            exitCode: 0,
            stdout: "/usr/bin/docker\tDocker version 27.0.0\tunknown\t\t\t\t\t1\trunning\t".str_repeat('b', 64)."\n",
        ));

        $drift = $probe->diff($tool, $probe->introspect($tool));

        expect(toolProbeIssue($drift, 'tool.container_spec_mismatch')?->kind)
            ->toBe(DriftKind::Divergent)
            ->and(toolProbeIssue($drift, 'tool.container_spec_mismatch')?->detail)
            ->toMatchArray([
                'expected_hash' => $container->specHash(),
                'observed_hash' => str_repeat('b', 64),
            ]);
    });

    it('passes managed config files when the managed file resource probe plans ok', function (): void {
        $content = "address=/test/10.6.0.2\n";
        $hash = hash('sha256', $content);
        $node = createToolsProbeAppHostNode();
        $tool = NodeTool::factory()->create([
            'node_id' => $node->id,
            'name' => 'dns',
            'config' => [
                'managed_config' => [
                    'path' => '/etc/orbit/dns.conf',
                    'hash' => $hash,
                    'content' => $content,
                ],
            ],
        ]);
        $shell = new QueuedToolsProbeRemoteShell(
            new RemoteShellResult(0, toolsProbeCapabilityStdout('/usr/bin/dns'), '', 1),
            new RemoteShellResult(0, toolsProbeManagedFileStdout(true, $hash, '0644'), '', 1),
        );
        $probe = toolsProbeWithRemoteShell($shell);

        $snapshot = $probe->introspect($tool);
        $drift = $probe->diff($tool, $snapshot);

        expect($drift)
            ->toBe([])
            ->and($snapshot->get('dns'))
            ->toMatchArray([
                'config_exists' => true,
                'config_hash' => $hash,
                'config_mode' => '0644',
            ])
            ->and($shell->scripts)
            ->toHaveCount(2)
            ->and($shell->scripts[1])
            ->toContain('internal:managed-file')
            ->and($shell->options[1])
            ->toMatchArray(['throw' => false]);
    });

    it('uses managed file resource probes when batch introspecting managed config', function (): void {
        $content = "address=/test/10.6.0.2\n";
        $hash = hash('sha256', $content);
        $node = createToolsProbeAppHostNode();
        $tool = NodeTool::factory()->create([
            'node_id' => $node->id,
            'name' => 'dns',
            'config' => [
                'managed_config' => [
                    'path' => '/etc/orbit/dns.conf',
                    'hash' => $hash,
                    'content' => $content,
                ],
            ],
        ]);
        $shell = new QueuedToolsProbeRemoteShell(
            new RemoteShellResult(
                0,
                json_encode([
                    'name' => 'dns',
                    'installed' => true,
                    'path' => '/usr/bin/dns',
                    'version' => null,
                    'state' => 'running',
                    'container_exists' => null,
                    'container_state' => null,
                    'container_spec_hash' => null,
                ], JSON_THROW_ON_ERROR)
                    ."\n",
                '',
                1,
            ),
            new RemoteShellResult(0, toolsProbeManagedFileStdout(true, $hash, '0644'), '', 1),
        );
        $probe = toolsProbeWithRemoteShell($shell);

        $snapshots = $probe->introspectMany([$tool]);

        expect($snapshots['dns']->get('dns'))
            ->toMatchArray([
                'config_exists' => true,
                'config_hash' => $hash,
                'config_mode' => '0644',
            ])
            ->and($shell->scripts)
            ->toHaveCount(2)
            ->and($shell->scripts[1])
            ->toContain('internal:managed-file');
    });

    it('uses POSIX shell for batched tool probes while preserving line-delimited JSON parsing', function (): void {
        $node = createToolsProbeAppHostNode();
        $composer = NodeTool::factory()->create(['node_id' => $node->id, 'name' => 'composer']);
        $docker = NodeTool::factory()->create(['node_id' => $node->id, 'name' => 'docker']);
        $shell = new QueuedToolsProbeRemoteShell(
            new RemoteShellResult(
                0,
                json_encode([
                    'name' => 'composer',
                    'installed' => true,
                    'path' => '/usr/local/bin/composer',
                    'version' => 'Composer version 2.8.0',
                    'state' => 'unknown',
                    'container_exists' => null,
                    'container_state' => null,
                    'container_spec_hash' => null,
                ], JSON_THROW_ON_ERROR)
                ."\n"
                .json_encode([
                    'name' => 'docker',
                    'installed' => true,
                    'path' => '/usr/bin/docker',
                    'version' => 'Docker version 27.0.0',
                    'state' => 'unknown',
                    'container_exists' => null,
                    'container_state' => null,
                    'container_spec_hash' => null,
                ], JSON_THROW_ON_ERROR)
                ."\n",
                '',
                1,
            ),
        );
        $probe = new ToolsProbe($shell);

        $snapshots = $probe->introspectMany([$composer, $docker]);

        expect($shell->scripts[0])
            ->not
            ->toContain('php -r')
            ->and($shell->scripts[0])
            ->toContain('# orbit-tool-probe:capability-batch')
            ->and($shell->scripts[0])
            ->toContain('printf \'{"name":')
            ->and($snapshots['composer']->get('composer'))
            ->toMatchArray([
                'installed' => true,
                'path' => '/usr/local/bin/composer',
                'version' => 'Composer version 2.8.0',
            ])
            ->and($snapshots['docker']->get('docker'))
            ->toMatchArray([
                'installed' => true,
                'path' => '/usr/bin/docker',
                'version' => 'Docker version 27.0.0',
            ]);
    });

    it('preserves empty service fields when batch probing container-backed tools', function (): void {
        $node = createToolsProbeAppHostNode();
        $container = OrbitCaddyContainer::forPrivateNode('10.6.0.50');
        $tool = NodeTool::factory()->create([
            'node_id' => $node->id,
            'name' => 'caddy',
            'expected_state' => 'installed',
            'config' => ['container' => $container->spec()],
        ]);
        $catalog = new ToolCatalog(new ToolDefinitionRegistry([
            new class extends BaseTool {
                public function slug(): string
                {
                    return 'caddy';
                }

                public function probeMetadata(): array
                {
                    return [
                        'binary' => 'sh',
                        'container' => 'orbit-caddy',
                    ];
                }
            },
        ]));
        $shell = new QueuedToolsProbeRemoteShell(
            new RemoteShellResult(
                0,
                json_encode([
                    'name' => 'caddy',
                    'installed' => true,
                    'path' => '/bin/sh',
                    'version' => null,
                    'state' => 'missing',
                    'container_exists' => false,
                    'container_state' => 'missing',
                    'container_spec_hash' => null,
                    'provider_reachable' => null,
                    'provider_error' => null,
                ], JSON_THROW_ON_ERROR)
                    ."\n",
                '',
                1,
            ),
        );
        $probe = new ToolsProbe($shell, $catalog);

        $snapshot = $probe->introspectMany([$tool])['caddy'];
        $drift = $probe->diff($tool, $snapshot);

        expect($shell->scripts[0])
            ->toContain('# orbit-tool-probe:capability-batch')
            ->toContain("container='orbit-caddy'")
            ->toContain('docker container inspect "$container"')
            ->and($snapshot->get('caddy'))
            ->toMatchArray([
                'installed' => true,
                'container_exists' => false,
                'container_state' => 'missing',
            ])
            ->and(toolProbeIssue($drift, 'tool.container_missing')?->kind)
            ->toBe(DriftKind::Missing)
            ->and(toolProbeIssue($drift, 'tool.capability_missing'))
            ->toBeNull();
    });

    it('detects missing managed config files through the managed file resource plan', function (): void {
        $content = "address=/test/10.6.0.2\n";
        $hash = hash('sha256', $content);
        $node = createToolsProbeAppHostNode();
        $tool = NodeTool::factory()->create([
            'node_id' => $node->id,
            'name' => 'dns',
            'config' => [
                'managed_config' => [
                    'path' => '/etc/orbit/dns.conf',
                    'hash' => $hash,
                    'content' => $content,
                ],
            ],
        ]);
        $probe = toolsProbeWithRemoteShell(new QueuedToolsProbeRemoteShell(
            new RemoteShellResult(0, toolsProbeCapabilityStdout('/usr/bin/dns'), '', 1),
            new RemoteShellResult(0, toolsProbeManagedFileStdout(false, null, null), '', 1),
        ));

        $snapshot = $probe->introspect($tool);
        $drift = $probe->diff($tool, $snapshot);

        expect(toolProbeIssue($drift, 'tool.config_missing')?->kind)
            ->toBe(DriftKind::Missing)
            ->and(toolProbeIssue($drift, 'tool.config_missing')?->detail)
            ->toMatchArray([
                'path' => '/etc/orbit/dns.conf',
                'expected_hash' => $hash,
            ]);
    });

    it('detects managed config hash mismatches through the managed file resource plan', function (): void {
        $content = "address=/test/10.6.0.2\n";
        $hash = hash('sha256', $content);
        $node = createToolsProbeAppHostNode();
        $tool = NodeTool::factory()->create([
            'node_id' => $node->id,
            'name' => 'dns',
            'config' => [
                'managed_config' => [
                    'path' => '/etc/orbit/dns.conf',
                    'hash' => $hash,
                    'content' => $content,
                ],
            ],
        ]);
        $probe = toolsProbeWithRemoteShell(new QueuedToolsProbeRemoteShell(
            new RemoteShellResult(0, toolsProbeCapabilityStdout('/usr/bin/dns'), '', 1),
            new RemoteShellResult(0, toolsProbeManagedFileStdout(true, str_repeat('b', 64), '0644'), '', 1),
        ));

        $snapshot = $probe->introspect($tool);
        $drift = $probe->diff($tool, $snapshot);

        expect(toolProbeIssue($drift, 'tool.config_mismatch')?->kind)
            ->toBe(DriftKind::Divergent)
            ->and(toolProbeIssue($drift, 'tool.config_mismatch')?->detail)
            ->toMatchArray([
                'path' => '/etc/orbit/dns.conf',
                'expected_hash' => $hash,
                'observed_hash' => str_repeat('b', 64),
            ]);
    });

    it('detects managed config mode mismatches through the managed file resource plan', function (): void {
        $content = "address=/test/10.6.0.2\n";
        $hash = hash('sha256', $content);
        $node = createToolsProbeAppHostNode();
        $tool = NodeTool::factory()->create([
            'node_id' => $node->id,
            'name' => 'dns',
            'config' => [
                'managed_config' => [
                    'path' => '/etc/orbit/dns.conf',
                    'hash' => $hash,
                    'content' => $content,
                    'mode' => '0640',
                ],
            ],
        ]);
        $probe = toolsProbeWithRemoteShell(new QueuedToolsProbeRemoteShell(
            new RemoteShellResult(0, toolsProbeCapabilityStdout('/usr/bin/dns'), '', 1),
            new RemoteShellResult(0, toolsProbeManagedFileStdout(true, $hash, '0600'), '', 1),
        ));

        $snapshot = $probe->introspect($tool);
        $drift = $probe->diff($tool, $snapshot);

        expect(toolProbeIssue($drift, 'tool.config_mismatch')?->kind)
            ->toBe(DriftKind::Divergent)
            ->and(toolProbeIssue($drift, 'tool.config_mismatch')?->detail)
            ->toMatchArray([
                'path' => '/etc/orbit/dns.conf',
                'expected_hash' => $hash,
                'observed_hash' => $hash,
                'mode' => '0640',
                'observed_mode' => '0600',
            ]);
    });

    it('marks managed config probe failures as unverifiable instead of repairable mismatch', function (): void {
        $content = "address=/test/10.6.0.2\n";
        $hash = hash('sha256', $content);
        $node = createToolsProbeAppHostNode();
        $tool = NodeTool::factory()->create([
            'node_id' => $node->id,
            'name' => 'dns',
            'config' => [
                'managed_config' => [
                    'path' => '/etc/orbit/dns.conf',
                    'hash' => $hash,
                    'content' => $content,
                ],
            ],
        ]);
        $probe = toolsProbeWithRemoteShell(new QueuedToolsProbeRemoteShell(
            new RemoteShellResult(0, toolsProbeCapabilityStdout('/usr/bin/dns'), '', 1),
            new RemoteShellResult(255, '', 'ssh: connection refused', 1),
        ));

        $snapshot = $probe->introspect($tool);
        $drift = $probe->diff($tool, $snapshot);

        expect(toolProbeIssue($drift, 'tool.config_probe_failed')?->kind)
            ->toBe(DriftKind::Unverifiable)
            ->and(toolProbeIssue($drift, 'tool.config_mismatch'))
            ->toBeNull()
            ->and(toolProbeIssue($drift, 'tool.config_probe_failed')?->detail)
            ->toMatchArray([
                'path' => '/etc/orbit/dns.conf',
                'error' => 'ssh: connection refused',
            ]);
    });

    it('marks managed config intent incomplete when declared content cannot satisfy the managed file resource', function (): void {
        $node = createToolsProbeAppHostNode();
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

        $drift = new ToolsProbe()->diff($tool, new ProbeSnapshot([
            'dns' => ['installed' => true],
        ]));

        expect(toolProbeIssue($drift, 'tool.record_incomplete')?->kind)
            ->toBe(DriftKind::Missing)
            ->and(toolProbeIssue($drift, 'tool.record_incomplete')?->detail)
            ->toMatchArray([
                'tool' => 'dns',
                'field' => 'managed_config',
            ]);
    });

    it('detects missing managed credential material through the managed file resource plan', function (): void {
        $secret = 'generated-password';
        $hash = hash('sha256', $secret);
        $node = createToolsProbeAppHostNode();
        $tool = NodeTool::factory()->create([
            'node_id' => $node->id,
            'name' => 'opencode-cli',
            'credentials' => [
                'managed_secret' => [
                    'path' => '/home/orbit/.config/opencode-server/password',
                    'hash' => $hash,
                    'content' => $secret,
                ],
            ],
        ]);
        $probe = toolsProbeWithRemoteShell(new QueuedToolsProbeRemoteShell(
            new RemoteShellResult(0, toolsProbeCapabilityStdout('/usr/bin/opencode-server'), '', 1),
            new RemoteShellResult(0, toolsProbeManagedFileStdout(false, null, null), '', 1),
        ));

        $snapshot = $probe->introspect($tool);
        $drift = $probe->diff($tool, $snapshot);

        expect(toolProbeIssue($drift, 'tool.credentials_missing')?->kind)
            ->toBe(DriftKind::Missing)
            ->and(toolProbeIssue($drift, 'tool.credentials_missing')?->detail)
            ->toMatchArray([
                'path' => '/home/orbit/.config/opencode-server/password',
                'expected_hash' => $hash,
                'mode' => '0600',
            ]);
    });

    it('detects managed credential hash mismatches through the managed file resource plan', function (): void {
        $secret = 'generated-password';
        $hash = hash('sha256', $secret);
        $node = createToolsProbeAppHostNode();
        $tool = NodeTool::factory()->create([
            'node_id' => $node->id,
            'name' => 'opencode-cli',
            'credentials' => [
                'managed_secret' => [
                    'path' => '/home/orbit/.config/opencode-server/password',
                    'hash' => $hash,
                    'content' => $secret,
                ],
            ],
        ]);
        $probe = toolsProbeWithRemoteShell(new QueuedToolsProbeRemoteShell(
            new RemoteShellResult(0, toolsProbeCapabilityStdout('/usr/bin/opencode-server'), '', 1),
            new RemoteShellResult(0, toolsProbeManagedFileStdout(true, str_repeat('b', 64), '0644'), '', 1),
        ));

        $snapshot = $probe->introspect($tool);
        $drift = $probe->diff($tool, $snapshot);

        expect(toolProbeIssue($drift, 'tool.credentials_mismatch')?->kind)
            ->toBe(DriftKind::Divergent)
            ->and(toolProbeIssue($drift, 'tool.credentials_mismatch')?->detail)
            ->toMatchArray([
                'path' => '/home/orbit/.config/opencode-server/password',
                'expected_hash' => $hash,
                'observed_hash' => str_repeat('b', 64),
                'mode' => '0600',
            ]);
    });

    it('detects managed credential mode mismatches through the managed file resource plan', function (): void {
        $secret = 'generated-password';
        $hash = hash('sha256', $secret);
        $node = createToolsProbeAppHostNode();
        $tool = NodeTool::factory()->create([
            'node_id' => $node->id,
            'name' => 'opencode-cli',
            'credentials' => [
                'managed_secret' => [
                    'path' => '/home/orbit/.config/opencode-server/password',
                    'hash' => $hash,
                    'content' => $secret,
                ],
            ],
        ]);
        $probe = toolsProbeWithRemoteShell(new QueuedToolsProbeRemoteShell(
            new RemoteShellResult(0, toolsProbeCapabilityStdout('/usr/bin/opencode-server'), '', 1),
            new RemoteShellResult(0, toolsProbeManagedFileStdout(true, $hash, '0644'), '', 1),
        ));

        $snapshot = $probe->introspect($tool);
        $drift = $probe->diff($tool, $snapshot);

        expect(toolProbeIssue($drift, 'tool.credentials_mismatch')?->kind)
            ->toBe(DriftKind::Divergent)
            ->and(toolProbeIssue($drift, 'tool.credentials_mismatch')?->detail)
            ->toMatchArray([
                'path' => '/home/orbit/.config/opencode-server/password',
                'expected_hash' => $hash,
                'observed_hash' => $hash,
                'mode' => '0600',
                'observed_mode' => '0644',
            ]);
    });

    it('marks managed credential probe failures as unverifiable instead of repairable mismatch', function (): void {
        $secret = 'generated-password';
        $hash = hash('sha256', $secret);
        $node = createToolsProbeAppHostNode();
        $tool = NodeTool::factory()->create([
            'node_id' => $node->id,
            'name' => 'opencode-cli',
            'credentials' => [
                'managed_secret' => [
                    'path' => '/home/orbit/.config/opencode-server/password',
                    'hash' => $hash,
                    'content' => $secret,
                ],
            ],
        ]);
        $probe = toolsProbeWithRemoteShell(new QueuedToolsProbeRemoteShell(
            new RemoteShellResult(0, toolsProbeCapabilityStdout('/usr/bin/opencode-server'), '', 1),
            new RemoteShellResult(255, '', 'ssh: connection refused', 1),
        ));

        $snapshot = $probe->introspect($tool);
        $drift = $probe->diff($tool, $snapshot);

        expect(toolProbeIssue($drift, 'tool.credentials_probe_failed')?->kind)
            ->toBe(DriftKind::Unverifiable)
            ->and(toolProbeIssue($drift, 'tool.credentials_mismatch'))
            ->toBeNull()
            ->and(toolProbeIssue($drift, 'tool.credentials_probe_failed')?->detail)
            ->toMatchArray([
                'path' => '/home/orbit/.config/opencode-server/password',
                'error' => 'ssh: connection refused',
            ]);
    });

    it('marks managed secret intent incomplete when it is not a valid managed file resource', function (): void {
        $node = createToolsProbeAppHostNode();
        $tool = NodeTool::factory()->create([
            'node_id' => $node->id,
            'name' => 'opencode-cli',
            'credentials' => [
                'managed_secret' => [
                    'path' => 'relative/password',
                    'hash' => str_repeat('a', 64),
                    'content' => 'generated-password',
                ],
            ],
        ]);

        $drift = new ToolsProbe()->diff($tool, new ProbeSnapshot([
            'opencode-cli' => ['installed' => true],
        ]));

        expect(toolProbeIssue($drift, 'tool.record_incomplete')?->kind)
            ->toBe(DriftKind::Missing)
            ->and(toolProbeIssue($drift, 'tool.record_incomplete')?->detail)
            ->toMatchArray([
                'tool' => 'opencode-cli',
                'field' => 'managed_secret',
            ]);
    });

    it('detects missing agent tool proxy route', function (): void {
        $node = createToolsProbeAgentNode();
        $tool = NodeTool::factory()->create([
            'node_id' => $node->id,
            'name' => 'openclaw',
            'expected_state' => 'installed',
        ]);

        $drift = new ToolsProbe()->diff($tool, new ProbeSnapshot([]));

        expect(toolProbeIssue($drift, 'tool.agent_route_missing')?->kind)
            ->toBe(DriftKind::Missing)
            ->and(toolProbeIssue($drift, 'tool.agent_route_missing')?->detail)
            ->toMatchArray([
                'tool' => 'openclaw',
                'domain' => 'openclaw.agent',
            ]);
    });

    it('passes when agent tool proxy route exists', function (): void {
        $node = createToolsProbeAgentNode();
        $tool = NodeTool::factory()->create([
            'node_id' => $node->id,
            'name' => 'openclaw',
            'expected_state' => 'installed',
        ]);
        ProxyRoute::factory()->create([
            'node_id' => $node->id,
            'domain' => 'openclaw.agent',
            'owner_type' => 'tool',
            'kind' => 'proxy',
            'source_hash' => toolsProbeAgentRouteSourceHash($node, 'openclaw'),
            'config' => toolsProbeAgentRouteConfig('openclaw'),
        ]);

        $drift = new ToolsProbe()->diff($tool, new ProbeSnapshot([]));

        expect(toolProbeIssue($drift, 'tool.agent_route_missing'))->toBeNull();
    });

    it('detects drift when agent tool proxy route is owned by a different tool', function (): void {
        $node = createToolsProbeAgentNode();
        $tool = NodeTool::factory()->create([
            'node_id' => $node->id,
            'name' => 'openclaw',
            'expected_state' => 'installed',
        ]);
        ProxyRoute::factory()->create([
            'node_id' => $node->id,
            'domain' => 'openclaw.agent',
            'owner_type' => 'tool',
            'config' => ['owner_name' => 'hermes'],
        ]);

        $drift = new ToolsProbe()->diff($tool, new ProbeSnapshot([]));

        expect(toolProbeIssue($drift, 'tool.agent_route_missing')?->kind)
            ->toBe(DriftKind::Divergent)
            ->and(toolProbeIssue($drift, 'tool.agent_route_missing')?->detail)
            ->toMatchArray([
                'tool' => 'openclaw',
                'domain' => 'openclaw.agent',
                'route_owner' => 'hermes',
            ]);
    });

    it('detects drift when agent tool proxy route has the wrong kind', function (): void {
        $node = createToolsProbeAgentNode();
        $tool = NodeTool::factory()->create([
            'node_id' => $node->id,
            'name' => 'openclaw',
            'expected_state' => 'installed',
        ]);
        ProxyRoute::factory()->create([
            'node_id' => $node->id,
            'domain' => 'openclaw.agent',
            'owner_type' => 'tool',
            'kind' => 'upstream',
            'source_hash' => str_repeat('a', 64),
            'config' => toolsProbeAgentRouteConfig('openclaw'),
        ]);

        $drift = new ToolsProbe()->diff($tool, new ProbeSnapshot([]));

        expect(toolProbeIssue($drift, 'tool.agent_route_missing')?->kind)
            ->toBe(DriftKind::Divergent)
            ->and(toolProbeIssue($drift, 'tool.agent_route_missing')?->detail)
            ->toMatchArray([
                'tool' => 'openclaw',
                'domain' => 'openclaw.agent',
                'expected_kind' => 'proxy',
                'observed_kind' => 'upstream',
            ]);
    });

    it('detects drift when agent tool proxy route config or source hash is stale', function (): void {
        $node = createToolsProbeAgentNode();
        $tool = NodeTool::factory()->create([
            'node_id' => $node->id,
            'name' => 'openclaw',
            'expected_state' => 'installed',
        ]);
        ProxyRoute::factory()->create([
            'node_id' => $node->id,
            'domain' => 'openclaw.agent',
            'owner_type' => 'tool',
            'kind' => 'proxy',
            'source_hash' => str_repeat('b', 64),
            'config' => [
                'target' => ['type' => 'upstream', 'value' => 'http://127.0.0.1:9999'],
                'upstream' => 'http://127.0.0.1:9999',
                'owner_name' => 'openclaw',
            ],
        ]);

        $drift = new ToolsProbe()->diff($tool, new ProbeSnapshot([]));

        expect(toolProbeIssue($drift, 'tool.agent_route_missing')?->kind)
            ->toBe(DriftKind::Divergent)
            ->and(toolProbeIssue($drift, 'tool.agent_route_missing')?->detail)
            ->toMatchArray([
                'tool' => 'openclaw',
                'domain' => 'openclaw.agent',
                'expected_upstream' => 'http://host.docker.internal:8080',
                'observed_upstream' => 'http://127.0.0.1:9999',
            ]);
    });

    it('detects missing agent tool credentials metadata', function (): void {
        $node = createToolsProbeAgentNode();
        $tool = NodeTool::factory()->create([
            'node_id' => $node->id,
            'name' => 'openclaw',
            'expected_state' => 'installed',
            'credentials' => null,
        ]);

        $drift = new ToolsProbe()->diff($tool, new ProbeSnapshot([]));

        expect(toolProbeIssue($drift, 'tool.agent_credentials_missing')?->kind)->toBe(DriftKind::Missing);
    });

    it('passes when agent tool credentials metadata exists', function (): void {
        $node = createToolsProbeAgentNode();
        $tool = NodeTool::factory()->create([
            'node_id' => $node->id,
            'name' => 'openclaw',
            'expected_state' => 'installed',
            'credentials' => ['fields' => ['url' => 'https://openclaw.agent']],
        ]);

        $drift = new ToolsProbe()->diff($tool, new ToolsProbe()->introspect($tool));

        expect(toolProbeIssue($drift, 'tool.agent_credentials_missing'))->toBeNull();
    });

    it('detects missing agent user for agent tools', function (): void {
        Http::preventStrayRequests();
        Http::fake([
            'http://10.44.0.83:9477/v1/commands' => tools_probe_agent_runtime_response([
                'runtime_user' => false,
                'orbit_cli' => false,
            ]),
        ]);
        $node = createToolsProbeAgentNode();
        $node->forceFill([
            'orbit_agent_capable' => true,
            'wireguard_address' => '10.44.0.83',
        ])->save();
        $tool = NodeTool::factory()->create([
            'node_id' => $node->id,
            'name' => 'openclaw',
            'expected_state' => 'installed',
        ]);
        $probe = toolsProbeWithAgentPush(new ToolsProbeRemoteShell(exitCode: 1));

        $drift = $probe->diff($tool, $probe->introspect($tool));

        expect(toolProbeIssue($drift, 'tool.agent_user_missing')?->kind)->toBe(DriftKind::Missing);
        Http::assertSent(
            fn (Request $request): bool => tools_probe_agent_runtime_request_matches(
                request: $request,
                url: 'http://10.44.0.83:9477/v1/commands',
            ),
        );
    });

    it('detects an agent user that cannot execute the Orbit CLI for agent tools', function (): void {
        Http::preventStrayRequests();
        Http::fake([
            'http://10.44.0.84:9477/v1/commands' => tools_probe_agent_runtime_response([
                'runtime_user' => true,
                'orbit_cli' => false,
            ]),
        ]);
        $node = createToolsProbeAgentNode();
        $node->forceFill([
            'orbit_agent_capable' => true,
            'wireguard_address' => '10.44.0.84',
        ])->save();
        $tool = NodeTool::factory()->create([
            'node_id' => $node->id,
            'name' => 'openclaw',
            'expected_state' => 'installed',
            'credentials' => ['fields' => ['url' => 'https://openclaw.agent']],
        ]);
        $probe = toolsProbeWithAgentPush(new QueuedToolsProbeRemoteShell);

        $drift = $probe->diff($tool, new ProbeSnapshot([
            'openclaw' => ['installed' => true],
        ]));

        expect(toolProbeIssue($drift, 'tool.agent_orbit_cli_inaccessible')?->kind)->toBe(DriftKind::Divergent);
        Http::assertSent(
            fn (Request $request): bool => tools_probe_agent_runtime_request_matches(
                request: $request,
                url: 'http://10.44.0.84:9477/v1/commands',
            ),
        );
    });
});

final class ToolsProbeRemoteShell implements RemoteShell
{
    /** @var list<string> */
    public array $scripts;

    public function __construct(
        private int $exitCode = 0,
        private string $stdout = '',
    ) {
        $this->scripts = [];
    }

    /**
     * @param  array<string, mixed>  $options
     */
    public function run(Node $node, string $script, array $options = []): RemoteShellResult
    {
        $this->scripts[] = $script;

        return new RemoteShellResult(exitCode: $this->exitCode, stdout: $this->stdout, stderr: '', durationMs: 1);
    }
}

final class RecordingToolsProbeRemoteShell implements RemoteShell
{
    public string $script = '';

    public string $input = '';

    public function __construct(
        private int $exitCode = 0,
        private string $stdout = '',
    ) {}

    /**
     * @param  array<string, mixed>  $options
     */
    public function run(Node $node, string $script, array $options = []): RemoteShellResult
    {
        $this->script = $script;
        $this->input = is_string($options['input'] ?? null) ? $options['input'] : '';

        return new RemoteShellResult(exitCode: $this->exitCode, stdout: $this->stdout, stderr: '', durationMs: 1);
    }
}

final class QueuedToolsProbeRemoteShell implements RemoteShell
{
    /** @var list<string> */
    public array $scripts = [];

    /** @var list<array<string, mixed>> */
    public array $options = [];

    /**
     * @var list<RemoteShellResult>
     */
    private array $results;

    public function __construct(RemoteShellResult ...$results)
    {
        $this->results = $results;
    }

    /**
     * @param  array<string, mixed>  $options
     */
    public function run(Node $node, string $script, array $options = []): RemoteShellResult
    {
        $this->scripts[] = $script;
        $this->options[] = $options;

        return array_shift($this->results) ?? new RemoteShellResult(1, '', 'unexpected shell call', 1);
    }
}

final class ExecutingToolsProbeRemoteShell implements RemoteShell
{
    /**
     * @param  array<string, mixed>  $options
     */
    public function run(Node $node, string $script, array $options = []): RemoteShellResult
    {
        $result = Process::run($script);

        return new RemoteShellResult(
            exitCode: $result->exitCode(),
            stdout: $result->output(),
            stderr: $result->errorOutput(),
            durationMs: 1,
        );
    }
}
