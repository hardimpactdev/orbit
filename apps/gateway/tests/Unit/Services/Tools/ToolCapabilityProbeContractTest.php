<?php

declare(strict_types=1);

use App\Data\RemoteShell\RemoteShellResult;
use App\Services\Tools\ToolCapabilityProbeContract;
use App\Services\Tools\ToolCapabilityProbeInput;
use Symfony\Component\Process\Process;

it('normalizes capability metadata once for single and batch observations', function (): void {
    $input = ToolCapabilityProbeInput::fromMetadata(
        metadata: [
            'binary' => 42,
            'binary_as_user' => ' agent ',
            'version_command' => 'tool --version',
            'service' => false,
            'provider_command' => 'tool status',
            'container' => 'metadata-container',
            'probe' => 'test -e /opt/tool-ready',
        ],
        binaryFallback: 'tool',
        containerOverride: 'configured-container',
    );

    expect($input)->toEqual(new ToolCapabilityProbeInput(
        binary: 'tool',
        binaryAsUser: 'agent',
        versionCommand: 'tool --version',
        service: '',
        providerCommand: 'tool status',
        container: 'configured-container',
        extraProbe: 'test -e /opt/tool-ready',
    ));
});

it('owns the existing single capability script and tab separated reply contract', function (): void {
    $contract = new ToolCapabilityProbeContract;
    $script = $contract->renderOne(new ToolCapabilityProbeInput(
        binary: '/home/agent/.local/bin/tool',
        binaryAsUser: 'agent',
        versionCommand: '/home/agent/.local/bin/tool --version',
        service: 'tool.service',
        providerCommand: 'tool status',
        container: 'orbit-tool',
        extraProbe: 'test -e /home/agent/.tool-ready',
    ));
    $observation = $contract->parseOne(new RemoteShellResult(
        exitCode: 0,
        stdout: implode("\t", [
            '/home/agent/.local/bin/tool',
            'Tool 1.2.3',
            'running',
            '',
            '',
            '',
            '',
            '1',
            'running',
            'spec-hash',
            '0',
            'provider unavailable',
        ])
            ."\n",
        stderr: '',
        durationMs: 1,
    ));

    expect($script)->toStartWith('set -eu');
    expect($script)->toContain('# orbit-tool-probe:capability');
    expect($script)->toContain("binary='/home/agent/.local/bin/tool'");
    expect($script)->toContain("binary_as_user='agent'");
    expect($script)->toContain("container='orbit-tool'");
    expect($script)->toContain('printf \'%s\\t%s\\t%s\\t%s\\t%s\\t%s\\t%s\\t%s\\t%s\\t%s\\t%s\\t%s\\n\'');
    expect(str_contains($script, 'php -r'))->toBeFalse();
    expect($observation)->toBe([
        'installed' => true,
        'path' => '/home/agent/.local/bin/tool',
        'version' => 'Tool 1.2.3',
        'state' => 'running',
        'config_exists' => null,
        'config_hash' => null,
        'secret_exists' => null,
        'secret_hash' => null,
        'container_exists' => true,
        'container_state' => 'running',
        'container_spec_hash' => 'spec-hash',
        'provider_reachable' => false,
        'provider_error' => 'provider unavailable',
    ]);
});

it('owns ordered batch rendering and line separated JSON reply parsing', function (): void {
    $contract = new ToolCapabilityProbeContract;
    $script = $contract->renderMany([
        'composer' => new ToolCapabilityProbeInput(binary: 'composer', versionCommand: 'composer --version'),
        'docker' => new ToolCapabilityProbeInput(
            binary: 'docker',
            versionCommand: 'docker --version',
            providerCommand: 'docker info',
        ),
    ]);
    $observations = $contract->parseMany(new RemoteShellResult(
        exitCode: 0,
        stdout: json_encode([
            'name' => 'composer',
            'installed' => true,
            'path' => '/usr/local/bin/composer',
        ], JSON_THROW_ON_ERROR)
        ."\ninvalid\n"
        .json_encode([
            'name' => 'docker',
            'installed' => false,
            'path' => null,
        ], JSON_THROW_ON_ERROR)
        ."\n",
        stderr: '',
        durationMs: 1,
    ));

    expect($script)->toStartWith('set -eu');
    expect($script)->toContain('# orbit-tool-probe:capability-batch');
    expect($script)->toContain("'composer')");
    expect($script)->toContain("'docker')");
    expect($script)->toContain("composer\ndocker\nORBIT_TOOLS");
    expect($script)->toContain('printf \'{"name":');
    expect(str_contains($script, 'php -r'))->toBeFalse();
    expect($observations)->toBe([
        'composer' => [
            'installed' => true,
            'path' => '/usr/local/bin/composer',
        ],
        'docker' => [
            'installed' => false,
            'path' => null,
        ],
    ]);
});

it('keeps single and batch capability facts equivalent for the same safe input', function (): void {
    $contract = new ToolCapabilityProbeContract;
    $input = new ToolCapabilityProbeInput(
        binary: 'sh',
        versionCommand: "printf 'Orbit Tool 1.0\\n'",
    );

    $single = $contract->parseOne(tool_capability_probe_contract_run($contract->renderOne($input)));
    $batch = $contract->parseMany(tool_capability_probe_contract_run($contract->renderMany([
        'safe-tool' => $input,
    ])))['safe-tool'];

    unset($single['config_exists'], $single['config_hash'], $single['secret_exists'], $single['secret_hash']);

    expect($single)->toBe($batch);
});

it('keeps the capability wire contract out of the tools coordinator', function (): void {
    $gatewayPath = dirname(path: __DIR__, levels: 4);
    $probeSource = (string) file_get_contents($gatewayPath.'/app/Services/Tools/ToolsProbe.php');
    $contractSource = (string) file_get_contents($gatewayPath.'/app/Services/Tools/ToolCapabilityProbeContract.php');

    expect(str_contains($probeSource, '# orbit-tool-probe:capability'))->toBeFalse();
    expect(str_contains($probeSource, 'function toolCapabilityProbeScript('))->toBeFalse();
    expect(str_contains($probeSource, 'function batchedToolCapabilityProbeScript('))->toBeFalse();
    expect($contractSource)->toContain('# orbit-tool-probe:capability');
    expect($contractSource)->toContain('# orbit-tool-probe:capability-batch');
    expect(str_contains($contractSource, 'app('))->toBeFalse();
});

function tool_capability_probe_contract_run(string $script): RemoteShellResult
{
    $process = new Process(['/bin/sh', '-c', $script]);
    $process->run();

    return new RemoteShellResult(
        exitCode: $process->getExitCode() ?? 1,
        stdout: $process->getOutput(),
        stderr: $process->getErrorOutput(),
        durationMs: 1,
    );
}
