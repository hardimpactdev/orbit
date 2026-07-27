<?php

declare(strict_types=1);

use App\Data\Operations\ReleaseManifest;
use App\Data\RemoteShell\RemoteShellResult;
use App\Models\Node;
use App\Models\NodeRoleAssignment;
use App\Services\Ca\OrbitCaService;
use App\Services\Operations\FleetUpdateTargetSelector;
use App\Services\Operations\NodeAgentServicePayloadBuilder;
use App\Services\Operations\ProvisioningAgentInstaller;
use App\Services\Operations\ReleaseManifestResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Orbit\Core\Nodes\NodeSystemdServiceRenderer;
use Tests\Fakes\ProvisioningAgentInstallerRemoteExecutor;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    app()->instance(OrbitCaService::class, new ProvisioningAgentInstallerTestCa);
});

final readonly class ProvisioningAgentInstallerTestCa extends OrbitCaService
{
    public function rootCert(): string
    {
        return 'fake-root-ca';
    }
}

it('installs and starts the initial Agent over provisioning SSH before role convergence', function (): void {
    $gateway = Node::factory()->create([
        'name' => 'gateway-1',
        'platform' => 'ubuntu_24-04',
        'wireguard_address' => '10.6.0.2',
        'status' => 'active',
    ]);
    NodeRoleAssignment::factory()->create([
        'node_id' => $gateway->id,
        'role' => 'gateway',
        'status' => 'active',
    ]);
    $node = Node::factory()->create([
        'name' => 'app-dev-1',
        'platform' => 'ubuntu_24-04',
        'wireguard_address' => '10.6.0.4',
        'user' => 'orbit',
        'status' => 'provisioning',
    ]);

    $transport = new ProvisioningAgentInstallerRemoteExecutor;
    $transport->result = new RemoteShellResult(
        exitCode: 1,
        stdout: "artifact: OK\nagent-ready\n",
        stderr: 'retained SSH postamble returned non-zero',
        durationMs: 1,
    );
    $installer = new ProvisioningAgentInstaller(
        transport: $transport,
        manifests: provisioning_agent_installer_manifest_resolver(),
        agentServices: app(NodeAgentServicePayloadBuilder::class),
        targets: app(FleetUpdateTargetSelector::class),
    );

    $result = $installer->install($node);
    $systemdServices = app(NodeSystemdServiceRenderer::class);
    $agentUnit = $systemdServices->agentUnit(
        user: 'orbit',
        agentBinary: '/home/orbit/.local/bin/orbit-agent',
        orbitBinary: '/home/orbit/.local/bin/orbit',
        configPath: '/home/orbit/.config/orbit/agent.toml',
        httpBind: '10.6.0.4:9477',
    );

    expect($result->successful())
        ->toBeTrue()
        ->and($result->stderr)
        ->toBe('retained SSH postamble returned non-zero')
        ->and($transport->runs)
        ->toHaveCount(1)
        ->and($transport->runs[0]['node']->is($node))
        ->toBeTrue()
        ->and($transport->runs[0]['script'])
        ->toContain('tmp="$(mktemp -d "${TMPDIR:-/tmp}/orbit-agent-bootstrap.XXXXXX")"')
        ->toContain('orbit-agent-linux-x64')
        ->toContain('sha256sum -c -')
        ->toContain('/home/orbit/.local/bin/orbit-agent')
        ->toContain('/etc/systemd/system/orbit-agent.service')
        ->toContain(base64_encode($agentUnit))
        ->toContain(base64_encode($systemdServices->runtimeBootScript()))
        ->toContain(base64_encode($systemdServices->runtimeBootUnit()))
        ->toContain('/usr/local/libexec/orbit-runtime-boot-converge')
        ->toContain('/etc/systemd/system/orbit-runtime-boot-converge.service')
        ->toContain("systemctl enable 'orbit-runtime-boot-converge.service'")
        ->toContain("systemctl enable 'orbit-agent.service'")
        ->toContain("systemctl restart 'orbit-agent.service'")
        ->toContain('http://10.6.0.4:9477/v1/commands')
        ->toContain('if [ "$command_status" = 405 ]')
        ->and($transport->runs[0]['options']['input'])
        ->toBeString()
        ->not->toContain('gateway_url =')->and($transport->runs[0]['options'])
        ->not->toHaveKey('metadata')->and($node->fresh()->isProvisioning())->toBeTrue();

    $transport->result = new RemoteShellResult(
        exitCode: 1,
        stdout: "artifact: OK\n",
        stderr: 'agent service failed',
        durationMs: 1,
    );

    expect($installer->install($node)->successful())->toBeFalse();
});

it('refuses provisioning SSH after the node becomes active', function (): void {
    $gateway = Node::factory()->create([
        'name' => 'gateway-1',
        'platform' => 'ubuntu_24-04',
        'wireguard_address' => '10.6.0.2',
        'status' => 'active',
    ]);
    NodeRoleAssignment::factory()->create([
        'node_id' => $gateway->id,
        'role' => 'gateway',
        'status' => 'active',
    ]);

    $node = Node::factory()->create([
        'name' => 'app-dev-1',
        'platform' => 'ubuntu_24-04',
        'wireguard_address' => '10.6.0.4',
        'status' => 'active',
    ]);
    $transport = new ProvisioningAgentInstallerRemoteExecutor;
    $installer = new ProvisioningAgentInstaller(
        transport: $transport,
        manifests: provisioning_agent_installer_manifest_resolver(),
        agentServices: app(NodeAgentServicePayloadBuilder::class),
        targets: app(FleetUpdateTargetSelector::class),
    );

    expect(fn () => $installer->install($node))
        ->toThrow(\RuntimeException::class, 'Provisioning Agent installation requires a provisioning node.')
        ->and($transport->runs)
        ->toBeEmpty();
});

function provisioning_agent_installer_manifest_resolver(): ReleaseManifestResolver
{
    $manifest = ReleaseManifest::fromArray([
        'schema_version' => 1,
        'version' => '1.2.3',
        'source' => 'github-release',
        'images' => [
            'gateway' => 'ghcr.io/hardimpactdev/orbit-gateway@sha256:'.str_repeat('a', times: 64),
        ],
        'cli_artifacts' => [
            'linux-amd64' => [
                'url' => 'https://artifacts.orbit.test/orbit-linux-x64',
                'sha256' => str_repeat('b', times: 64),
            ],
            'darwin-arm64' => [
                'url' => 'https://artifacts.orbit.test/orbit-macos-arm64',
                'sha256' => str_repeat('c', times: 64),
            ],
        ],
        'agent_artifacts' => [
            'linux-amd64' => [
                'url' => 'https://artifacts.orbit.test/orbit-agent-linux-x64',
                'sha256' => str_repeat('d', times: 64),
            ],
        ],
        'role_images' => [
            'orbit-caddy' => 'ghcr.io/hardimpactdev/orbit-caddy@sha256:'.str_repeat('e', times: 64),
        ],
    ]);

    return new class($manifest) extends ReleaseManifestResolver {
        public function __construct(
            private ReleaseManifest $manifest,
        ) {}

        public function resolve(): ReleaseManifest
        {
            return $this->manifest;
        }
    };
}
