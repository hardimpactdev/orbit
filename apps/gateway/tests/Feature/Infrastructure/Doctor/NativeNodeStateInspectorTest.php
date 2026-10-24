<?php

declare(strict_types=1);

use App\Actions\Doctor\NodeDoctorProbe;
use App\Data\Doctor\DoctorIssueData;
use App\Domain\Doctor\DoctorInspectionException;
use App\Domain\Doctor\DoctorNodeContext;
use App\Infrastructure\AgentCommands\AgentDelivery;
use App\Infrastructure\AgentCommands\AgentRunnerFailure;
use App\Infrastructure\Doctor\NativeNodeStateInspector;
use App\Infrastructure\Processes\CommandDeadline;
use App\Models\Node;
use Tests\Support\FakeAgentCommandRunner;

function doctorNodeObservation(bool $mac = false): array
{
    return [
        'platform' => $mac ? 'darwin' : 'linux', 'architecture' => $mac ? 'aarch64' : 'x86_64', 'wireguard' => true,
        'agent_binary' => $mac ? null : true, 'agent_unit' => $mac ? null : true, 'agent_active' => $mac ? null : true,
        'agent_checksum' => $mac ? null : app(AgentDelivery::class)->artifacts()['linux-x86_64']['sha256'],
        'secret_checksum' => $mac ? null : str_repeat('b', 64),
        'disks' => [['location' => $mac ? 'home' : 'root', 'available_kib' => 16384, 'size_kib' => 26214400, 'free_inodes' => null, 'total_inodes' => null]],
    ];
}
function doctorObservedNode(bool $mac = false): Node
{
    return new Node(['name' => 'observed', 'user' => 'orbit', 'wireguard_ip' => '10.44.0.7', 'status' => 'active', 'platform' => $mac ? 'macos' : 'linux', 'architecture' => $mac ? 'aarch64' : 'x86_64', 'ssh_host_fingerprint' => 'SHA256:managed']);
}
function doctorNativeNodeInspector(FakeAgentCommandRunner $agents): NativeNodeStateInspector
{
    return new NativeNodeStateInspector($agents, new CommandDeadline, app(AgentDelivery::class));
}
it('maps one typed Node observation and compares only the recorded delivery pin', function (bool $mac): void {
    $agents = new FakeAgentCommandRunner()->answer('doctor.inspect-node', doctorNodeObservation($mac));
    $node = doctorObservedNode($mac);
    $result = doctorNativeNodeInspector($agents)->inspect($node);
    expect($agents->calls)->toBe([['node' => 'observed', 'command' => 'doctor.inspect-node', 'input' => ['user' => 'orbit', 'address' => '10.44.0.7']]])
        ->and($result->reachable)->toBeTrue()->and($result->platform)->toBe($mac ? 'darwin' : 'linux')
        ->and($result->architecture)->toBe($mac ? 'aarch64' : 'x86_64')->and($result->agentChecksumMatches)->toBe($mac ? null : true)
        ->and($result->agentSecretChecksum)->toBe($mac ? null : str_repeat('b', 64))->and($result->diskFilesystems)->toHaveCount(1);
    $report = new NodeDoctorProbe()->inspect(new DoctorNodeContext($node, $result));
    expect(array_filter($report->issues, static fn (DoctorIssueData $issue): bool => $issue->code === 'node.disk_low'))->toHaveCount(1);
})->with([true, false]);

it('reports a different binary and absent WireGuard address from typed observations', function (): void {
    $observation = doctorNodeObservation();
    $observation['agent_checksum'] = str_repeat('c', 64);
    $observation['wireguard'] = false;
    $agents = new FakeAgentCommandRunner()->answer('doctor.inspect-node', $observation);
    $result = doctorNativeNodeInspector($agents)->inspect(doctorObservedNode());
    expect($result->agentChecksumMatches)->toBeFalse()->and($result->wireGuardAddressMatches)->toBeFalse();
});

it('distinguishes transport failure from an unreadable observation', function (string $kind): void {
    $agents = new FakeAgentCommandRunner()->answer('doctor.inspect-node', fn () => throw new AgentRunnerFailure('doctor.inspect-node', $kind, 'failed'));
    $inspector = doctorNativeNodeInspector($agents);
    if (in_array($kind, [AgentRunnerFailure::Transport, AgentRunnerFailure::Timeout], true)) {
        expect($inspector->inspect(doctorObservedNode())->reachable)->toBeFalse();
    } else {
        expect(fn () => $inspector->inspect(doctorObservedNode()))->toThrow(DoctorInspectionException::class);
    }
})->with([AgentRunnerFailure::Transport, AgentRunnerFailure::Timeout, AgentRunnerFailure::MissingResult, AgentRunnerFailure::MalformedResult]);

it('does not invoke the runner without an address', function (): void {
    $agents = new FakeAgentCommandRunner;
    expect(doctorNativeNodeInspector($agents)->inspect(new Node)->reachable)->toBeFalse()->and($agents->calls)->toBe([]);
});

it('rejects a typed refusal or malformed observation without running another program', function (string $case): void {
    $agents = new FakeAgentCommandRunner;
    $data = doctorNodeObservation();
    if ($case === 'refusal') {
        $agents->refuse('doctor.inspect-node', 'doctor.node_inspection_failed');
    } else {
        match ($case) {
            'checksum' => $data['secret_checksum'] = 'secret bytes',
            'boolean' => $data['agent_active'] = 'true',
            'disk' => $data['disks'][0]['available_kib'] = '1',
            'architecture' => $data['architecture'] = 'arm64',
        };
        $agents->answer('doctor.inspect-node', $data);
    }
    expect(fn () => doctorNativeNodeInspector($agents)->inspect(doctorObservedNode()))->toThrow(DoctorInspectionException::class);
    expect($agents->calls)->toHaveCount(1);
})->with(['refusal', 'checksum', 'boolean', 'disk', 'architecture']);

it('keeps Node observations behind the typed agent boundary', function (): void {
    expect(file_get_contents(app_path('Infrastructure/Doctor/NativeNodeStateInspector.php')))->not->toContain('SshExecutor', 'RemoteCommand', 'ProcessRunner', '<<<', 'df ', 'uname');
});
