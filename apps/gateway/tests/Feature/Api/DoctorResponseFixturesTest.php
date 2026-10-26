<?php

declare(strict_types=1);

use App\Domain\Doctor\NodeInspectionData;
use App\Domain\Doctor\NodeStateInspector;
use App\Domain\Shared\LifecycleStatus;
use App\Domain\Tools\ToolManagerName;
use App\Domain\Tools\ToolStatus;
use App\Infrastructure\AgentCommands\AgentCommandRunner;
use App\Infrastructure\Ssh\SshExecutor;
use App\Models\Node;
use App\Models\Tool;
use App\Models\ToolManagerRecord;
use Tests\Support\FakeAgentCommandRunner;
use Tests\Support\ToolManagerFakeSshExecutor;

beforeEach(function (): void {
    $caller = doctor_fixture_node('doctor-caller', '10.44.0.2');
    $this->markAsGateway($caller);
    $this->withServerVariables(['REMOTE_ADDR' => $caller->wireguard_ip]);
    $this->withHeader('X-Orbit-Request-Id', fixture_request_id());
});

describe('doctor discovery fixtures', function (): void {
    it('records an informational-only healthy discovery', function (): void {
        $node = doctor_fixture_node('mini', '10.44.0.3', 'macos', 'mini');
        $ssh = doctor_fixture_bind(new NodeInspectionData(true, 'macos', 'arm64', true), [
            'formulae' => ['state' => 'complete', 'packages' => [doctor_fixture_package('jq', '1.7.1'), doctor_fixture_package('ripgrep', '14.1.1')]],
            'casks' => ['state' => 'complete', 'packages' => [doctor_fixture_package('font-hack', '2.0.0'), doctor_fixture_package('docker', '4.39.0', 'authorization_required')]],
        ], new FakeAgentCommandRunner()->answer('tool.vp-inventory', ['packages' => [['name' => 'left-pad', 'version' => '1.0.0']], 'scan_complete' => true]));
        $toolsBefore = Tool::query()->count();

        $response = $this->postJson('/api/v1/doctor', [
            'node_id' => $node->id,
            'families' => ['tool'],
        ])->assertOk();

        expect($response->json('data.healthy'))->toBeTrue()
            ->and($response->json('data.nodes.0.families.0.checked'))->toBe(0)
            ->and($response->json('data.nodes.0.families.0.status'))->toBe('healthy')
            ->and($response->json('data.summary.drift'))->toBe(0)
            ->and($response->json('data.summary.unverifiable'))->toBe(0)
            ->and($response->json('data.summary.informational'))->toBe(5)
            ->and($response->json('data.nodes.0.families.0.issues.2.resource_name'))->toBe('docker')
            ->and($response->json('data.nodes.0.families.0.issues.2.observed'))->toContain('authorization_required')
            ->and(Tool::query()->count())->toBe($toolsBefore)
            ->and(ToolManagerRecord::query()->count())->toBe(0);
        doctor_fixture_assert_read_only($ssh);
        record_fixture($response, 'doctor/doctor/informational', 'Orbit\\Sdk\\Requests\\Doctor\\RunDoctorRequest', 'POST /api/v1/doctor');
    });

    it('records mixed drift and informational findings', function (): void {
        $node = doctor_fixture_node('linux-node', '10.44.0.4');
        $manager = $node->toolManagers()->create([
            'name' => ToolManagerName::Brew->value,
            'status' => LifecycleStatus::Active,
        ]);
        $node->tools()->create([
            'tool_manager_id' => $manager->id,
            'package' => 'jq',
            'status' => ToolStatus::Installed,
            'installed_version' => '1.6.0',
        ]);
        $toolsBefore = Tool::query()->count();
        doctor_fixture_bind(new NodeInspectionData(true, 'linux', 'amd64', true), [
            'formulae' => ['state' => 'complete', 'packages' => [doctor_fixture_package('ripgrep', '14.1.1')]],
            'casks' => ['state' => 'unsupported', 'packages' => []],
        ], new FakeAgentCommandRunner()->answer('tool.homebrew-formula-inventory', ['packages' => []])->refuse('tool.vp-inventory', 'tool.vp_scope_absent'));

        $response = $this->postJson('/api/v1/doctor', [
            'node_id' => $node->id,
            'families' => ['tool'],
        ])->assertOk();

        expect($response->json('data.healthy'))->toBeFalse()
            ->and($response->json('data.nodes.0.families.0.checked'))->toBe(1)
            ->and($response->json('data.nodes.0.families.0.status'))->toBe('drift')
            ->and($response->json('data.summary.drift'))->toBe(1)
            ->and($response->json('data.summary.unverifiable'))->toBe(0)
            ->and($response->json('data.summary.informational'))->toBe(2)
            ->and($response->json('data.nodes.0.families.0.issues.0.code'))->toBe('tool.not_installed')
            ->and($response->json('data.nodes.0.families.0.issues.1.code'))->toBe('tool.inventory_scan')
            ->and($response->json('data.nodes.0.families.0.issues.2.resource_name'))->toBe('ripgrep')
            ->and(Tool::query()->count())->toBe($toolsBefore);
        record_fixture($response, 'doctor/doctor/mixed', 'Orbit\\Sdk\\Requests\\Doctor\\RunDoctorRequest', 'POST /api/v1/doctor');
    });

    it('records an unreachable node without an inventory scan', function (): void {
        $node = doctor_fixture_node('linux-node', '10.44.0.4');
        $manager = $node->toolManagers()->create([
            'name' => ToolManagerName::Apt->value,
            'status' => LifecycleStatus::Active,
        ]);
        $node->tools()->create([
            'tool_manager_id' => $manager->id,
            'package' => 'jq',
            'status' => ToolStatus::Installed,
        ]);
        $ssh = doctor_fixture_bind(new NodeInspectionData(false, null, null, null), []);

        $response = $this->postJson('/api/v1/doctor', [
            'node_id' => $node->id,
            'families' => ['tool'],
        ])->assertOk();

        expect($response->json('data.healthy'))->toBeFalse()
            ->and($response->json('data.nodes.0.families.0.checked'))->toBe(1)
            ->and($response->json('data.summary.drift'))->toBe(0)
            ->and($response->json('data.summary.unverifiable'))->toBe(1)
            ->and($response->json('data.summary.informational'))->toBe(0)
            ->and($response->json('data.nodes.0.families.0.issues'))->toHaveCount(1)
            ->and($response->json('data.nodes.0.families.0.issues.0.code'))->toBe('tool.node_unreachable')
            ->and($ssh->commands)->toBeEmpty();
        record_fixture($response, 'doctor/doctor/unreachable', 'Orbit\\Sdk\\Requests\\Doctor\\RunDoctorRequest', 'POST /api/v1/doctor');
    });

    it('records a truncated inventory as unverifiable with no tool rows', function (): void {
        $node = doctor_fixture_node('linux-node', '10.44.0.4');
        doctor_fixture_bind(new NodeInspectionData(true, 'linux', 'amd64', true), [
            'formulae' => ['state' => 'incomplete', 'packages' => []],
            'casks' => ['state' => 'unsupported', 'packages' => []],
        ], new FakeAgentCommandRunner()->refuse('tool.vp-inventory', 'tool.vp_output_invalid'));

        $response = $this->postJson('/api/v1/doctor', [
            'node_id' => $node->id,
            'families' => ['tool'],
        ])->assertOk();

        expect($response->json('data.healthy'))->toBeFalse()
            ->and($response->json('data.nodes.0.families.0.checked'))->toBe(0)
            ->and($response->json('data.nodes.0.families.0.status'))->toBe('unverifiable')
            ->and($response->json('data.summary.informational'))->toBe(0)
            ->and($response->json('data.summary.unverifiable'))->toBe(2)
            ->and(Tool::query()->count())->toBe(0);
        record_fixture($response, 'doctor/doctor/truncated', 'Orbit\\Sdk\\Requests\\Doctor\\RunDoctorRequest', 'POST /api/v1/doctor');
    });

    it('records absent managers as informational and ignores unsupported casks', function (): void {
        $node = doctor_fixture_node('linux-node', '10.44.0.4');
        $ssh = doctor_fixture_bind(new NodeInspectionData(true, 'linux', 'amd64', true), [], new FakeAgentCommandRunner()
            ->refuse('tool.homebrew-scan', 'tool.homebrew_scope_absent')
            ->refuse('tool.vp-inventory', 'tool.vp_scope_absent'));

        $response = $this->postJson('/api/v1/doctor', [
            'node_id' => $node->id,
            'families' => ['tool'],
        ])->assertOk();

        expect($response->json('data.healthy'))->toBeTrue()
            ->and($response->json('data.nodes.0.families.0.checked'))->toBe(0)
            ->and($response->json('data.summary.drift'))->toBe(0)
            ->and($response->json('data.summary.unverifiable'))->toBe(0)
            ->and($response->json('data.summary.informational'))->toBe(2)
            ->and($response->json('data.nodes.0.families.0.issues.0.resource_name'))->toBe('brew')
            ->and($response->json('data.nodes.0.families.0.issues.0.observed'))->toBe('absent')
            ->and($response->json('data.nodes.0.families.0.issues.1.resource_name'))->toBe('vp')
            ->and(json_encode($response->json('data.nodes.0.families.0.issues')))->not->toContain('brew-cask')
            ->and(Tool::query()->count())->toBe(0);
        doctor_fixture_assert_read_only($ssh);
        record_fixture($response, 'doctor/doctor/empty-managers', 'Orbit\\Sdk\\Requests\\Doctor\\RunDoctorRequest', 'POST /api/v1/doctor');
    });
});

function doctor_fixture_node(
    string $name,
    string $address,
    string $platform = 'linux',
    string $user = 'orbit',
): Node {
    return Node::query()->create([
        'name' => $name,
        'status' => LifecycleStatus::Active,
        'platform' => $platform,
        'architecture' => $platform === 'macos' ? 'arm64' : 'amd64',
        'public_ssh_host' => '192.0.2.40',
        'user' => $user,
        'wireguard_ip' => $address,
        'ssh_host_fingerprint' => 'SHA256:'.str_repeat('A', 43),
    ]);
}

/** @param array<string, mixed> $observations */
function doctor_fixture_bind(NodeInspectionData $inspection, array $observations, ?FakeAgentCommandRunner $agents = null): ToolManagerFakeSshExecutor
{
    app()->instance(NodeStateInspector::class, new class($inspection) implements NodeStateInspector
    {
        public function __construct(private NodeInspectionData $inspection) {}

        public function inspect(Node $node): NodeInspectionData
        {
            return $this->inspection;
        }
    });
    $agents ??= new FakeAgentCommandRunner;
    if ($observations !== []) {
        $agents->answer('tool.homebrew-scan', $observations);
    }
    app()->instance(AgentCommandRunner::class, $agents);
    $ssh = new ToolManagerFakeSshExecutor([]);
    app()->instance(SshExecutor::class, $ssh);

    return $ssh;
}

function doctor_fixture_package(string $name, ?string $version, ?string $block = null): array
{
    return ['name' => $name, 'installed_version' => $version, 'block' => $block, 'dependency' => false];
}

function doctor_fixture_assert_read_only(ToolManagerFakeSshExecutor $ssh): void
{
    foreach ($ssh->arguments() as $arguments) {
        expect($arguments)->not->toContain('install')
            ->not->toContain('uninstall')
            ->not->toContain('upgrade')
            ->not->toContain('autoremove');
    }
}
