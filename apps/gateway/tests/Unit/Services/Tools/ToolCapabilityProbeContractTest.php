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

it('uses the canonical JSONL contract for a single capability observation', function (): void {
    $contract = new ToolCapabilityProbeContract;
    $script = $contract->renderOne('tool', new ToolCapabilityProbeInput(
        binary: 'sh',
        versionCommand: "printf 'Tool 1.2.3\\n'",
    ));
    $observation = $contract->parseOne('tool', tool_capability_probe_contract_run($script));

    expect($script)->toStartWith('set -eu');
    expect($script)->toContain('# orbit-tool-probe:capability');
    expect($script)->toContain('printf \'{"name":');
    expect(str_contains($script, '%s\\t%s'))->toBeFalse();
    expect($observation)->toMatchArray([
        'capability_probe_ok' => true,
        'installed' => true,
        'version' => 'Tool 1.2.3',
        'state' => 'unknown',
    ]);
    expect($observation['path'])->toBeString()->toEndWith('/sh');
});

it('keeps verified absence distinct from probe failure', function (): void {
    $contract = new ToolCapabilityProbeContract;
    $script = $contract->renderOne('missing-tool', new ToolCapabilityProbeInput(
        binary: '/orbit/does-not-exist',
    ));
    $result = tool_capability_probe_contract_run($script);
    $observation = $contract->parseOne('missing-tool', $result);

    expect($result->successful())->toBeTrue();
    expect($observation)->toMatchArray([
        'capability_probe_ok' => true,
        'installed' => false,
        'path' => null,
    ]);
});

it('escapes shell observation text as valid JSON', function (): void {
    $contract = new ToolCapabilityProbeContract;
    $script = $contract->renderOne('tool', new ToolCapabilityProbeInput(
        binary: 'sh',
        versionCommand: <<<'SH'
            printf '%s\n' 'Tool "quoted" C:\orbit\bin'
            SH,
    ));

    $observation = $contract->parseOne('tool', tool_capability_probe_contract_run($script));

    expect($observation)->toMatchArray([
        'capability_probe_ok' => true,
        'version' => 'Tool "quoted" C:\orbit\bin',
    ]);
});

it('keeps valid batch siblings when one reply line is malformed', function (): void {
    $contract = new ToolCapabilityProbeContract;
    $result = new RemoteShellResult(
        exitCode: 0,
        stdout: tool_capability_probe_json_line('composer', installed: true, path: '/usr/bin/composer')."\nnot-json\n",
        stderr: '',
        durationMs: 1,
    );

    $observations = $contract->parseMany($result, ['composer', 'docker']);

    expect($observations['composer'])->toMatchArray([
        'capability_probe_ok' => true,
        'installed' => true,
        'path' => '/usr/bin/composer',
    ]);
    expect($observations['docker'])->toBe([
        'capability_probe_ok' => false,
        'capability_probe_error' => 'Capability probe returned malformed JSON.',
    ]);
});

it('marks one requested tool unverifiable for invalid reply contracts', function (
    string $stdout,
    string $expectedError,
): void {
    $contract = new ToolCapabilityProbeContract;

    $observation = $contract->parseOne('composer', new RemoteShellResult(
        exitCode: 0,
        stdout: $stdout,
        stderr: '',
        durationMs: 1,
    ));

    expect($observation)->toBe([
        'capability_probe_ok' => false,
        'capability_probe_error' => $expectedError,
    ]);
})->with([
    'empty reply' => ['', 'Capability probe returned no observation.'],
    'missing installed' => [
        '{"name":"composer","path":"/usr/bin/composer"}'."\n",
        'Capability probe reply has an invalid installed field.',
    ],
    'non-boolean installed' => [
        '{"name":"composer","installed":"yes","path":"/usr/bin/composer"}'."\n",
        'Capability probe reply has an invalid installed field.',
    ],
    'wrong optional type' => [
        '{"name":"composer","installed":true,"path":42}'."\n",
        'Capability probe reply has an invalid path field.',
    ],
    'unknown name' => [
        tool_capability_probe_json_line('docker', installed: true, path: '/usr/bin/docker')."\n",
        'Capability probe reply identified an unexpected tool.',
    ],
    'duplicate name' => [
        tool_capability_probe_json_line('composer', installed: true, path: '/usr/bin/composer')
            ."\n"
            .tool_capability_probe_json_line('composer', installed: true, path: '/usr/bin/composer')
            ."\n",
        'Capability probe returned duplicate observations.',
    ],
]);

it('marks every requested tool unverifiable when the probe command fails', function (): void {
    $contract = new ToolCapabilityProbeContract;

    $observations = $contract->parseMany(new RemoteShellResult(
        exitCode: 23,
        stdout: '',
        stderr: 'shell failed',
        durationMs: 1,
    ), ['composer', 'docker']);

    expect($observations)->toBe([
        'composer' => [
            'capability_probe_ok' => false,
            'capability_probe_error' => 'Capability probe command failed with exit code 23.',
        ],
        'docker' => [
            'capability_probe_ok' => false,
            'capability_probe_error' => 'Capability probe command failed with exit code 23.',
        ],
    ]);
});

it('keeps the capability wire contract out of the tools coordinator', function (): void {
    $gatewayPath = dirname(path: __DIR__, levels: 4);
    $probeSource = (string) file_get_contents($gatewayPath.'/app/Services/Tools/ToolsProbe.php');
    $contractSource = (string) file_get_contents($gatewayPath.'/app/Services/Tools/ToolCapabilityProbeContract.php');

    expect(str_contains($probeSource, '# orbit-tool-probe:capability'))->toBeFalse();
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

function tool_capability_probe_json_line(string $name, bool $installed, ?string $path): string
{
    return json_encode([
        'name' => $name,
        'installed' => $installed,
        'path' => $path,
        'version' => null,
        'state' => 'unknown',
        'container_exists' => null,
        'container_state' => null,
        'container_spec_hash' => null,
        'provider_reachable' => null,
        'provider_error' => null,
    ], JSON_THROW_ON_ERROR);
}
