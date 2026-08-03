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
use App\Services\Operations\OperationRunRecorder;
use App\Services\Operations\OperationTokenFactory;
use App\Services\Proxy\ProxyRouteRenderer;
use App\Services\RemoteShell\LocalExecutorCommandBuilder;
use App\Services\RemoteShell\RemoteLocalExecutor;
use App\Services\RemoteShell\RunsInternalCommands;
use App\Services\Runtime\OrbitCaddyContainer;
use App\Services\Runtime\OrbitContainerNames;
use App\Services\Tools\ToolCatalog;
use App\Services\Tools\ToolDefinitionRegistry;
use App\Services\Tools\ToolScriptDispatcher;
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

function toolsProbeOrbitRootCaPath(): string
{
    $configRoot = config('orbit.paths.config_root');
    expect($configRoot)->toBeString();
    $caDir = rtrim((string) $configRoot, '/').'/ca';
    if (! is_dir($caDir)) {
        mkdir($caDir, 0755, true);
    }
    $path = $caDir.'/root.crt';
    if (! is_file($path)) {
        file_put_contents($path, "-----BEGIN CERTIFICATE-----\ntest-orbit-root\n-----END CERTIFICATE-----\n");
    }

    return $path;
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
    $executor = new ToolsProbeScriptExecutor($remoteShell);

    return new ToolsProbe(
        catalog: $catalog,
        localExecutor: $executor,
        scripts: new ToolScriptDispatcher($executor),
    );
}

function toolsProbeWithAgentPush(RemoteShell $_remoteShell): ToolsProbe
{
    $localExecutor = toolsProbeLocalExecutor();

    return new ToolsProbe(
        localExecutor: $localExecutor,
        scripts: new ToolScriptDispatcher($localExecutor),
    );
}

function toolsProbeLocalExecutor(): RemoteLocalExecutor
{
    $secret = config('app.key');

    if (! is_string($secret) || trim($secret) === '') {
        throw new RuntimeException('Application key is not configured for operation token signing.');
    }

    return new RemoteLocalExecutor(
        commands: app(LocalExecutorCommandBuilder::class),
        operationTokens: app(OperationTokenFactory::class),
        activityLogger: app(ActivityLogger::class),
        operationRuns: app(OperationRunRecorder::class),
        applicationKey: $secret,
    );
}

/**
 * @return array{target: array{type: string, value: string}, upstream: string, owner_name: string}
 */
function toolsProbeAgentRouteConfig(string $tool): array
{
    $port = 8080;
    $upstream = "http://host.docker.internal:{$port}";

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

/**
 * Absolute path that does not exist on the test host so bare `[ -x "$binary" ]` fails.
 */
function toolsProbeInaccessibleOwnerBinaryPath(): string
{
    return '/home/agent/.hermes/bin/hermes-owner-probe-'.bin2hex(random_bytes(4));
}

/**
 * Install a PATH-first fake `sudo` that only supports owner-scoped probe shapes.
 *
 * @param  array{allow_user?: string, test_x_ok?: bool, binary?: string, version_line?: string}  $config
 * @return array{dir: string, path_prefix: string}
 */
function toolsProbeInstallFakeSudo(array $config = []): array
{
    $dir = sys_get_temp_dir().'/orbit-fake-sudo-'.bin2hex(random_bytes(8));
    mkdir($dir, 0700, true);

    $allowUser = $config['allow_user'] ?? 'agent';
    $testXOk = $config['test_x_ok'] ?? true ? '1' : '0';
    $binary = $config['binary'] ?? '';
    $versionLine = $config['version_line'] ?? 'Hermes 2026.7.1-2 (owner-probe)';

    $script = <<<'BASH'
        #!/usr/bin/env bash
        set -euo pipefail

        user=""
        while [ "$#" -gt 0 ]; do
            case "$1" in
                -u)
                    user="${2:-}"
                    shift 2
                    ;;
                -H|-n|-S|-k|-E|-A)
                    shift
                    ;;
                --)
                    shift
                    break
                    ;;
                -*)
                    shift
                    ;;
                *)
                    break
                    ;;
            esac
        done

        expected_user="${ORBIT_FAKE_SUDO_ALLOW_USER:-agent}"
        if [ "$user" != "$expected_user" ]; then
            printf 'fake-sudo: unexpected user %s\n' "$user" >&2
            exit 1
        fi

        if [ "${1:-}" = "test" ] && [ "${2:-}" = "-x" ]; then
            target="${3:-}"
            if [ "${ORBIT_FAKE_SUDO_TEST_X_OK:-1}" != "1" ]; then
                exit 1
            fi
            if [ -n "${ORBIT_FAKE_SUDO_BINARY:-}" ] && [ "$target" != "${ORBIT_FAKE_SUDO_BINARY}" ]; then
                exit 1
            fi
            if [ -z "$target" ]; then
                exit 1
            fi
            exit 0
        fi

        if [ "${1:-}" = "bash" ] && [ "${2:-}" = "-lc" ]; then
            cmd="${3:-}"
            case "$cmd" in
                *--version*)
                    printf '%s\n' "${ORBIT_FAKE_SUDO_VERSION_LINE:-Hermes 1.0.0}"
                    exit 0
                    ;;
            esac
            exit 0
        fi

        printf 'fake-sudo: unsupported argv: %s\n' "$*" >&2
        exit 1
        BASH;

    $path = $dir.'/sudo';
    file_put_contents($path, $script);
    chmod($path, 0755);

    return [
        'dir' => $dir,
        'path_prefix' => $dir,
        'env' => [
            'ORBIT_FAKE_SUDO_ALLOW_USER' => $allowUser,
            'ORBIT_FAKE_SUDO_TEST_X_OK' => $testXOk,
            'ORBIT_FAKE_SUDO_BINARY' => $binary,
            'ORBIT_FAKE_SUDO_VERSION_LINE' => $versionLine,
        ],
    ];
}

/**
 * @param  array{binary: string, binary_as_user?: string, version_command?: string}  $metadata
 */
function toolsProbeOwnerScopedCatalog(string $slug, array $metadata): ToolCatalog
{
    return new ToolCatalog(new ToolDefinitionRegistry([
        new class($slug, $metadata) extends BaseTool {
            /**
             * @param  array{binary: string, binary_as_user?: string, version_command?: string}  $metadata
             */
            public function __construct(
                private string $toolSlug,
                private array $metadata,
            ) {}

            public function slug(): string
            {
                return $this->toolSlug;
            }

            public function probeMetadata(): array
            {
                return $this->metadata;
            }
        },
    ]));
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
        && agentPushRequestOperationIdMatchesToken($request)
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
        $probe = toolsProbeWithRemoteShell(new ToolsProbeRemoteShell(
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
        $probe = toolsProbeWithRemoteShell(new ToolsProbeRemoteShell(exitCode: 1));

        $snapshot = $probe->introspect($tool);
        $drift = $probe->diff($tool, $snapshot);

        expect(toolProbeIssue($drift, 'tool.capability_missing')?->kind)->toBe(DriftKind::Missing);
    });

    it('passes when live capability exists', function (): void {
        $node = createToolsProbeAppHostNode();
        $tool = NodeTool::factory()->create(['node_id' => $node->id, 'name' => 'composer']);
        $probe = toolsProbeWithRemoteShell(new ToolsProbeRemoteShell(exitCode: 0, stdout: "/usr/local/bin/composer\n"));

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

        // php-cli uses the dedicated php_cli_runtimes probe: absolute paths are
        // passed as quoted literals to probe_minor (not a PATH-style binary= assignment).
        expect($shell->script)
            ->toStartWith('set -eu')
            ->and($shell->script)
            ->toContain('probe_minor')
            ->and($shell->script)
            ->toContain('probe_minor "8.5" "8.5.8" "/opt/orbit/php/8.5/bin/php"')
            ->and($shell->script)
            ->toContain('binary="$3"')
            ->and($shell->script)
            ->toContain('[ -x "$binary" ]')
            ->and($shell->script)
            ->not->toContain('command -v');
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
        expect($shell->script)
            ->toContain('/home/deploy/.local/bin/claude')
            ->toContain('sudo -u')
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
        $probe = toolsProbeWithRemoteShell($recordingShell, $orbstackCatalog);

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
        $snapshot = toolsProbeWithRemoteShell(new ExecutingToolsProbeRemoteShell, $failingCatalog)->introspect($tool);

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
        $probe = toolsProbeWithRemoteShell($recordingShell, $orbstackCatalog);

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
        $snapshot = toolsProbeWithRemoteShell(new ExecutingToolsProbeRemoteShell, $failingCatalog)
            ->introspectMany([$tool])['orbstack-probe'];

        expect($snapshot->get('orbstack-probe'))
            ->toMatchArray([
                'installed' => false,
            ]);
    });

    it('observes owner-scoped absolute binaries via sudo -u test -x in single capability probes', function (): void {
        $binary = toolsProbeInaccessibleOwnerBinaryPath();
        $version = 'Hermes 2026.7.1-2 (owner-probe-single)';
        $fake = toolsProbeInstallFakeSudo([
            'allow_user' => 'agent',
            'test_x_ok' => true,
            'binary' => $binary,
            'version_line' => $version,
        ]);
        $node = createToolsProbeAppHostNode();
        $tool = NodeTool::factory()->create(['node_id' => $node->id, 'name' => 'owner-probe-tool']);
        $catalog = toolsProbeOwnerScopedCatalog('owner-probe-tool', [
            'binary' => $binary,
            'binary_as_user' => 'agent',
            'version_command' => 'sudo -u agent -H bash -lc '.escapeshellarg("{$binary} --version"),
        ]);
        $shell = new PathPrefixedExecutingToolsProbeRemoteShell($fake['path_prefix'], $fake['env']);
        $probe = toolsProbeWithRemoteShell($shell, $catalog);

        $snapshot = $probe->introspect($tool);

        expect($snapshot->get('owner-probe-tool'))
            ->toMatchArray([
                'installed' => true,
                'path' => $binary,
                'version' => $version,
            ]);
    });

    it('marks owner-scoped absolute binaries absent when sudo -u test -x fails in single probes', function (): void {
        $binary = toolsProbeInaccessibleOwnerBinaryPath();
        $fake = toolsProbeInstallFakeSudo([
            'allow_user' => 'agent',
            'test_x_ok' => false,
            'binary' => $binary,
        ]);
        $node = createToolsProbeAppHostNode();
        $tool = NodeTool::factory()->create(['node_id' => $node->id, 'name' => 'owner-probe-tool']);
        $catalog = toolsProbeOwnerScopedCatalog('owner-probe-tool', [
            'binary' => $binary,
            'binary_as_user' => 'agent',
            'version_command' => 'sudo -u agent -H bash -lc '.escapeshellarg("{$binary} --version"),
        ]);
        $probe = toolsProbeWithRemoteShell(
            new PathPrefixedExecutingToolsProbeRemoteShell($fake['path_prefix'], $fake['env']),
            $catalog,
        );

        $snapshot = $probe->introspect($tool);

        expect($snapshot->get('owner-probe-tool'))
            ->toMatchArray([
                'installed' => false,
            ]);
    });

    it('observes owner-scoped absolute binaries via sudo -u test -x in batch capability probes', function (): void {
        $binary = toolsProbeInaccessibleOwnerBinaryPath();
        $version = 'Hermes 2026.7.1-2 (owner-probe-batch)';
        $fake = toolsProbeInstallFakeSudo([
            'allow_user' => 'agent',
            'test_x_ok' => true,
            'binary' => $binary,
            'version_line' => $version,
        ]);
        $node = createToolsProbeAppHostNode();
        $tool = NodeTool::factory()->create(['node_id' => $node->id, 'name' => 'owner-probe-tool']);
        $catalog = toolsProbeOwnerScopedCatalog('owner-probe-tool', [
            'binary' => $binary,
            'binary_as_user' => 'agent',
            'version_command' => 'sudo -u agent -H bash -lc '.escapeshellarg("{$binary} --version"),
        ]);
        $probe = toolsProbeWithRemoteShell(
            new PathPrefixedExecutingToolsProbeRemoteShell($fake['path_prefix'], $fake['env']),
            $catalog,
        );

        $snapshots = $probe->introspectMany([$tool]);

        expect($snapshots['owner-probe-tool']->get('owner-probe-tool'))
            ->toMatchArray([
                'installed' => true,
                'path' => $binary,
                'version' => $version,
            ]);
    });

    it('marks owner-scoped absolute binaries absent when sudo -u test -x fails in batch probes', function (): void {
        $binary = toolsProbeInaccessibleOwnerBinaryPath();
        $fake = toolsProbeInstallFakeSudo([
            'allow_user' => 'agent',
            'test_x_ok' => false,
            'binary' => $binary,
        ]);
        $node = createToolsProbeAppHostNode();
        $tool = NodeTool::factory()->create(['node_id' => $node->id, 'name' => 'owner-probe-tool']);
        $catalog = toolsProbeOwnerScopedCatalog('owner-probe-tool', [
            'binary' => $binary,
            'binary_as_user' => 'agent',
            'version_command' => 'sudo -u agent -H bash -lc '.escapeshellarg("{$binary} --version"),
        ]);
        $probe = toolsProbeWithRemoteShell(
            new PathPrefixedExecutingToolsProbeRemoteShell($fake['path_prefix'], $fake['env']),
            $catalog,
        );

        $snapshots = $probe->introspectMany([$tool]);

        expect($snapshots['owner-probe-tool']->get('owner-probe-tool'))
            ->toMatchArray([
                'installed' => false,
            ]);
    });

    it('emits owner-scoped test -x for absolute binaries with binary_as_user in single and batch scripts', function (): void {
        $binary = '/home/agent/.hermes/bin/hermes';
        $node = createToolsProbeAppHostNode();
        $tool = NodeTool::factory()->create(['node_id' => $node->id, 'name' => 'hermes']);
        $catalog = toolsProbeOwnerScopedCatalog(slug: 'hermes', metadata: [
            'binary' => $binary,
            'binary_as_user' => 'agent',
            'version_command' => "sudo -u agent -H bash -lc '{$binary} --version'",
        ]);

        $singleShell = new RecordingToolsProbeRemoteShell(exitCode: 1, stdout: '');
        toolsProbeWithRemoteShell($singleShell, $catalog)->introspect($tool);

        expect($singleShell->script)
            ->toContain('# orbit-tool-probe:capability')
            ->toContain('binary_as_user=')
            ->toContain('sudo -u')
            ->toContain('test -x')
            ->toContain($binary);

        $batchShell = new RecordingToolsProbeRemoteShell;
        toolsProbeWithRemoteShell($batchShell, $catalog)->introspectMany([$tool]);

        expect($batchShell->script)
            ->toContain('# orbit-tool-probe:capability-batch')
            ->toContain('binary_as_user=')
            ->toContain('sudo -u')
            ->toContain('test -x')
            ->toContain($binary);
    });

    it('keeps bare absolute [ -x ] when binary_as_user is absent', function (): void {
        $node = createToolsProbeAppHostNode();
        $tool = NodeTool::factory()->create(['node_id' => $node->id, 'name' => 'bare-abs-tool']);
        $catalog = toolsProbeOwnerScopedCatalog('bare-abs-tool', [
            'binary' => '/usr/local/bin/example-tool',
            'version_command' => '/usr/local/bin/example-tool --version',
        ]);
        $shell = new RecordingToolsProbeRemoteShell(exitCode: 1, stdout: '');
        toolsProbeWithRemoteShell($shell, $catalog)->introspect($tool);

        expect($shell->script)
            ->toContain('[ -x "$binary" ]')
            ->and($shell->script)
            ->not->toMatch('/binary_as_user=\'[a-z]/');
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
        expect($shell->script)
            ->toContain("binary='docker'")
            ->toContain('docker --version')
            ->toContain('docker info')
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
        $node = createToolsProbeAppHostNode(['platform' => 'macos_14']);
        $tool = NodeTool::factory()->create(['node_id' => $node->id, 'name' => 'php']);
        $shell = new RecordingToolsProbeRemoteShell(
            exitCode: 0,
            stdout: "ghcr.io/hardimpactdev/orbit-frankenphp:2-php8.5-bookworm\n",
        );
        $probe = toolsProbeWithRemoteShell($shell);

        $snapshot = $probe->introspect($tool);
        $input = json_decode($shell->input, associative: true, flags: JSON_THROW_ON_ERROR);

        expect($shell->script)
            ->toContain('docker image inspect')
            ->not
            ->toContain('command -v php')
            ->and($input['script'])
            ->toContain('ghcr.io/hardimpactdev/orbit-frankenphp:2-php8.5-bookworm')
            ->and($snapshot->get('php'))
            ->toMatchArray([
                'installed' => true,
                'version' => '8.5',
                'images' => ['ghcr.io/hardimpactdev/orbit-frankenphp:2-php8.5-bookworm'],
                'image_inventory_available' => true,
                'image_inventory_error' => null,
            ])
            ->and($probe->diff($tool, $snapshot))
            ->toBe([]);
    });

    it('frankenphp does not accept host PHP output as PHP tool capability', function (): void {
        $node = createToolsProbeAppHostNode();
        $tool = NodeTool::factory()->create(['node_id' => $node->id, 'name' => 'php']);
        $probe = toolsProbeWithRemoteShell(new ToolsProbeRemoteShell(
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
        $probe = toolsProbeWithRemoteShell(new ToolsProbeRemoteShell(
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
        $probe = toolsProbeWithRemoteShell(new ToolsProbeRemoteShell(
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
        $probe = toolsProbeWithRemoteShell(new ToolsProbeRemoteShell(
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
        $probe = toolsProbeWithRemoteShell(new ToolsProbeRemoteShell(
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
        $probe = toolsProbeWithRemoteShell($shell);

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
                $probe = toolsProbeWithRemoteShell($shell);

                $snapshot = $probe->introspect($tool);
                $drift = $probe->diff($tool, $snapshot);
                $issueKeys = array_map(fn ($entry) => $entry->key, $drift);

                expect($shell->script)
                    ->toContain('docker container inspect')
                    ->toContain('.State.Restarting')
                    ->toContain($container->name())
                    ->toContain('orbit-e2e-dev-abc123-dev-orbit-caddy')
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
        $probe = toolsProbeWithRemoteShell($shell);

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
        $probe = toolsProbeWithRemoteShell(new ToolsProbeRemoteShell(
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
        $probe = toolsProbeWithRemoteShell(new ToolsProbeRemoteShell(
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
        $probe = toolsProbeWithRemoteShell($shell);

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
        $probe = toolsProbeWithRemoteShell($shell, $catalog);

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

    it('leaves a missing agent tool proxy route to the proxy family', function (): void {
        $node = createToolsProbeAgentNode();
        $tool = NodeTool::factory()->create([
            'node_id' => $node->id,
            'name' => 'hermes',
            'expected_state' => 'installed',
        ]);

        $drift = new ToolsProbe()->diff($tool, new ProbeSnapshot([]));

        expect(toolProbeIssue($drift, 'tool.agent_route_missing'))->toBeNull();
    });

    it('passes when agent tool proxy route exists', function (): void {
        $node = createToolsProbeAgentNode();
        $tool = NodeTool::factory()->create([
            'node_id' => $node->id,
            'name' => 'hermes',
            'expected_state' => 'installed',
        ]);
        ProxyRoute::factory()->create([
            'node_id' => $node->id,
            'domain' => 'hermes.agent',
            'owner_type' => 'tool',
            'kind' => 'proxy',
            'source_hash' => toolsProbeAgentRouteSourceHash($node, tool: 'hermes'),
            'config' => toolsProbeAgentRouteConfig(tool: 'hermes'),
        ]);

        $drift = new ToolsProbe()->diff($tool, new ProbeSnapshot([]));

        expect(toolProbeIssue($drift, 'tool.agent_route_missing'))->toBeNull();
    });

    it('leaves agent tool proxy route ownership conflicts to the proxy family', function (): void {
        $node = createToolsProbeAgentNode();
        $tool = NodeTool::factory()->create([
            'node_id' => $node->id,
            'name' => 'hermes',
            'expected_state' => 'installed',
        ]);
        ProxyRoute::factory()->create([
            'node_id' => $node->id,
            'domain' => 'hermes.agent',
            'owner_type' => 'tool',
            'config' => ['owner_name' => 'hermes'],
        ]);

        $drift = new ToolsProbe()->diff($tool, new ProbeSnapshot([]));

        expect(toolProbeIssue($drift, 'tool.agent_route_missing'))->toBeNull();
    });

    it('leaves agent tool proxy route kind drift to the proxy family', function (): void {
        $node = createToolsProbeAgentNode();
        $tool = NodeTool::factory()->create([
            'node_id' => $node->id,
            'name' => 'hermes',
            'expected_state' => 'installed',
        ]);
        ProxyRoute::factory()->create([
            'node_id' => $node->id,
            'domain' => 'hermes.agent',
            'owner_type' => 'tool',
            'kind' => 'upstream',
            'source_hash' => str_repeat('a', 64),
            'config' => toolsProbeAgentRouteConfig(tool: 'hermes'),
        ]);

        $drift = new ToolsProbe()->diff($tool, new ProbeSnapshot([]));

        expect(toolProbeIssue($drift, 'tool.agent_route_missing'))->toBeNull();
    });

    it('leaves agent tool proxy route config and source-hash drift to the proxy family', function (): void {
        $node = createToolsProbeAgentNode();
        $tool = NodeTool::factory()->create([
            'node_id' => $node->id,
            'name' => 'hermes',
            'expected_state' => 'installed',
        ]);
        ProxyRoute::factory()->create([
            'node_id' => $node->id,
            'domain' => 'hermes.agent',
            'owner_type' => 'tool',
            'kind' => 'proxy',
            'source_hash' => str_repeat('b', 64),
            'config' => [
                'target' => ['type' => 'upstream', 'value' => 'http://127.0.0.1:9999'],
                'upstream' => 'http://127.0.0.1:9999',
                'owner_name' => 'hermes',
            ],
        ]);

        $drift = new ToolsProbe()->diff($tool, new ProbeSnapshot([]));

        expect(toolProbeIssue($drift, 'tool.agent_route_missing'))->toBeNull();
    });

    it('detects missing agent tool credentials metadata', function (): void {
        $node = createToolsProbeAgentNode();
        $tool = NodeTool::factory()->create([
            'node_id' => $node->id,
            'name' => 'hermes',
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
            'name' => 'hermes',
            'expected_state' => 'installed',
            'credentials' => ['fields' => ['url' => 'https://hermes.agent']],
        ]);

        $drift = new ToolsProbe()->diff($tool, new ProbeSnapshot([
            'hermes' => ['installed' => true],
        ]));

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
            'managed' => true,
            'wireguard_address' => '10.44.0.83',
        ])->save();
        $tool = NodeTool::factory()->create([
            'node_id' => $node->id,
            'name' => 'hermes',
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
            'managed' => true,
            'wireguard_address' => '10.44.0.84',
        ])->save();
        $tool = NodeTool::factory()->create([
            'node_id' => $node->id,
            'name' => 'hermes',
            'expected_state' => 'installed',
            'credentials' => ['fields' => ['url' => 'https://hermes.agent']],
        ]);
        $probe = toolsProbeWithAgentPush(new QueuedToolsProbeRemoteShell);

        $drift = $probe->diff($tool, new ProbeSnapshot([
            'hermes' => ['installed' => true],
        ]));

        expect(toolProbeIssue($drift, 'tool.agent_orbit_cli_inaccessible')?->kind)->toBe(DriftKind::Divergent);
        Http::assertSent(
            fn (Request $request): bool => tools_probe_agent_runtime_request_matches(
                request: $request,
                url: 'http://10.44.0.84:9477/v1/commands',
            ),
        );
    });

    it('reports unverifiable agent runtime when inspection raises', function (): void {
        $node = createToolsProbeAgentNode();
        $tool = NodeTool::factory()->create([
            'node_id' => $node->id,
            'name' => 'hermes',
            'expected_state' => 'installed',
        ]);
        $executor = new class implements \App\Services\RemoteShell\RunsInternalCommands {
            public function runInternal(
                \App\Models\Node $node,
                string $commandName,
                array $arguments = [],
                array $commandOptions = [],
                array $transportOptions = [],
            ): RemoteShellResult {
                throw new RuntimeException('agent runtime unavailable');
            }
        };
        $probe = new ToolsProbe(localExecutor: $executor);

        $drift = $probe->diff($tool, new ProbeSnapshot([
            'hermes' => ['installed' => true],
        ]));

        expect(toolProbeIssue($drift, 'tool.agent_runtime_probe_failed')?->kind)
            ->toBe(DriftKind::Unverifiable)
            ->and(toolProbeIssue($drift, 'tool.agent_runtime_probe_failed')?->detail)
            ->toMatchArray([
                'tool' => 'hermes',
                'reason' => 'exception',
                'error' => 'agent runtime unavailable',
            ]);
    });

    it('reports unverifiable agent runtime when inspection returns non-success', function (): void {
        $node = createToolsProbeAgentNode();
        $tool = NodeTool::factory()->create([
            'node_id' => $node->id,
            'name' => 'hermes',
            'expected_state' => 'installed',
        ]);
        $executor = new class implements \App\Services\RemoteShell\RunsInternalCommands {
            public function runInternal(
                \App\Models\Node $node,
                string $commandName,
                array $arguments = [],
                array $commandOptions = [],
                array $transportOptions = [],
            ): RemoteShellResult {
                return new RemoteShellResult(
                    exitCode: 3,
                    stdout: '',
                    stderr: 'id: agent: no such user',
                    durationMs: 1,
                );
            }
        };
        $probe = new ToolsProbe(localExecutor: $executor);

        $drift = $probe->diff($tool, new ProbeSnapshot([
            'hermes' => ['installed' => true],
        ]));

        expect(toolProbeIssue($drift, 'tool.agent_runtime_probe_failed')?->kind)
            ->toBe(DriftKind::Unverifiable)
            ->and(toolProbeIssue($drift, 'tool.agent_runtime_probe_failed')?->detail)
            ->toMatchArray([
                'reason' => 'non_success',
                'error' => 'id: agent: no such user',
                'exit_code' => 3,
            ]);
    });

    it('reports unverifiable agent runtime when inspection payload is empty or malformed', function (): void {
        $node = createToolsProbeAgentNode();
        $tool = NodeTool::factory()->create([
            'node_id' => $node->id,
            'name' => 'hermes',
            'expected_state' => 'installed',
        ]);
        $executor = new class implements \App\Services\RemoteShell\RunsInternalCommands {
            public function runInternal(
                \App\Models\Node $node,
                string $commandName,
                array $arguments = [],
                array $commandOptions = [],
                array $transportOptions = [],
            ): RemoteShellResult {
                return new RemoteShellResult(
                    exitCode: 0,
                    stdout: '{not-valid',
                    stderr: '',
                    durationMs: 1,
                );
            }
        };
        $probe = new ToolsProbe(localExecutor: $executor);

        $drift = $probe->diff($tool, new ProbeSnapshot([
            'hermes' => ['installed' => true],
        ]));

        expect(toolProbeIssue($drift, 'tool.agent_runtime_probe_failed')?->kind)
            ->toBe(DriftKind::Unverifiable)
            ->and(toolProbeIssue($drift, 'tool.agent_runtime_probe_failed')?->detail['reason'] ?? null)
            ->toBe('malformed_payload');
    });

    it('reports unverifiable agent runtime for failure JSON envelopes with exit zero', function (): void {
        $node = createToolsProbeAgentNode();
        $tool = NodeTool::factory()->create([
            'node_id' => $node->id,
            'name' => 'hermes',
            'expected_state' => 'installed',
        ]);
        $executor = new class implements \App\Services\RemoteShell\RunsInternalCommands {
            public function runInternal(
                \App\Models\Node $node,
                string $commandName,
                array $arguments = [],
                array $commandOptions = [],
                array $transportOptions = [],
            ): RemoteShellResult {
                return new RemoteShellResult(
                    exitCode: 0,
                    stdout: json_encode([
                        'error' => [
                            'code' => 'probe_failed',
                            'message' => 'runtime probe refused',
                        ],
                    ], JSON_THROW_ON_ERROR),
                    stderr: '',
                    durationMs: 1,
                );
            }
        };
        $probe = new ToolsProbe(localExecutor: $executor);

        $drift = $probe->diff($tool, new ProbeSnapshot([
            'hermes' => ['installed' => true],
        ]));

        expect(toolProbeIssue($drift, 'tool.agent_runtime_probe_failed')?->kind)
            ->toBe(DriftKind::Unverifiable)
            ->and(toolProbeIssue($drift, 'tool.agent_user_missing'))
            ->toBeNull();
    });
});

final readonly class ToolsProbeScriptExecutor implements RunsInternalCommands
{
    public function __construct(
        private RemoteShell $shell,
    ) {}

    public function runInternal(
        Node $node,
        string $commandName,
        array $arguments = [],
        array $commandOptions = [],
        array $transportOptions = [],
    ): RemoteShellResult {
        if ($commandName !== 'internal:tool:run-script') {
            $script = implode(' ', [
                $commandName,
                ...array_map(static fn (mixed $argument): string => escapeshellarg((string) $argument), $arguments),
            ]);

            return $this->shell->run($node, $script, $transportOptions);
        }

        $payload = json_decode((string) ($transportOptions['input'] ?? ''), true, flags: JSON_THROW_ON_ERROR);
        $result = $this->shell->run($node, (string) ($payload['script'] ?? ''), $transportOptions);

        if (! $result->successful()) {
            return $result;
        }

        return new RemoteShellResult(
            exitCode: 0,
            stdout: json_encode([
                'success' => [
                    'data' => [
                        'exit_code' => $result->exitCode,
                        'stdout' => $result->stdout,
                        'stderr' => $result->stderr,
                        'duration_ms' => $result->durationMs,
                    ],
                ],
            ], JSON_THROW_ON_ERROR),
            stderr: '',
            durationMs: $result->durationMs,
        );
    }
}

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

final class PathPrefixedExecutingToolsProbeRemoteShell implements RemoteShell
{
    /**
     * @param  array<string, string>  $env
     */
    public function __construct(
        private string $pathPrefix,
        private array $env = [],
    ) {}

    /**
     * @param  array<string, mixed>  $options
     */
    public function run(Node $node, string $script, array $options = []): RemoteShellResult
    {
        $path = $this->pathPrefix.':'.(getenv('PATH') ?: '/usr/bin:/bin:/usr/sbin:/sbin');
        $lines = ['export PATH='.escapeshellarg($path)];

        foreach ($this->env as $key => $value) {
            $lines[] = 'export '.escapeshellarg($key).'='.escapeshellarg($value);
        }

        $lines[] = $script;
        $result = Process::run(['bash', '-c', implode("\n", $lines)]);

        return new RemoteShellResult(
            exitCode: $result->exitCode(),
            stdout: $result->output(),
            stderr: $result->errorOutput(),
            durationMs: 1,
        );
    }
}

it('flags unreachable autonomous-agent consumer https urls for installed agent tools', function (): void {
    toolsProbeOrbitRootCaPath();
    Http::preventStrayRequests();
    Http::fake([
        'https://hermes.agent' => Http::response('bad gateway', 502),
    ]);

    $node = createToolsProbeAgentNode();
    $tool = NodeTool::factory()->create([
        'node_id' => $node->id,
        'name' => 'hermes',
        'expected_state' => 'installed',
        'credentials' => ['fields' => ['url' => 'https://hermes.agent']],
    ]);

    $drift = new ToolsProbe()->diff($tool, new ProbeSnapshot([
        'hermes' => ['installed' => true],
    ]));

    $issue = toolProbeIssue($drift, 'tool.agent_consumer_url_unreachable');

    expect($issue)
        ->not
        ->toBeNull()
        ->and($issue?->kind)
        ->toBe(DriftKind::Divergent)
        ->and($issue?->detail['expected_url'] ?? null)
        ->toBe('https://hermes.agent')
        ->and($issue?->detail['observed'] ?? null)
        ->toBe('HTTP 502')
        ->and($issue?->detail['next_command'] ?? null)
        ->toContain('--family=proxy');

    Http::assertSent(fn (Request $request): bool => $request->url() === 'https://hermes.agent');
});

it('treats http 404 consumer responses as unreachable', function (): void {
    toolsProbeOrbitRootCaPath();
    Http::preventStrayRequests();
    Http::fake([
        'https://hermes.agent' => Http::response('missing', 404),
    ]);

    $node = createToolsProbeAgentNode();
    $tool = NodeTool::factory()->create([
        'node_id' => $node->id,
        'name' => 'hermes',
        'expected_state' => 'installed',
    ]);

    $drift = new ToolsProbe()->diff($tool, new ProbeSnapshot([
        'hermes' => ['installed' => true],
    ]));

    expect(toolProbeIssue($drift, 'tool.agent_consumer_url_unreachable')?->detail['observed'] ?? null)
        ->toBe('HTTP 404');
});

it('accepts 2xx autonomous-agent consumer https urls', function (): void {
    toolsProbeOrbitRootCaPath();
    Http::preventStrayRequests();
    Http::fake([
        'https://hermes.agent' => Http::response('ok', 200),
    ]);

    $node = createToolsProbeAgentNode();
    $tool = NodeTool::factory()->create([
        'node_id' => $node->id,
        'name' => 'hermes',
        'expected_state' => 'installed',
        'credentials' => ['fields' => ['url' => 'https://hermes.agent']],
    ]);

    $drift = new ToolsProbe()->diff($tool, new ProbeSnapshot([
        'hermes' => ['installed' => true],
    ]));

    expect(toolProbeIssue($drift, 'tool.agent_consumer_url_unreachable'))->toBeNull();
});
