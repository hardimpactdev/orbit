<?php

declare(strict_types=1);

use App\E2E\Support\E2ETopologyHarness;
use App\E2E\Support\E2ETopologyKind;
use Illuminate\Contracts\Process\ProcessResult;

pest()->group('e2e-feature', 'e2e-provider-incus', 'e2e-feature-operator_gateway_app-dev', 'e2e-feature-operator-gateway-dev');

it('repairs missing and mode-drifted managed tool configuration from gateway intent', function (): void {
    $topology = e2eTopology(E2ETopologyKind::OperatorGatewayAppdev)
        ->withCurrentCheckout(roles: ['gateway']);
    $configPath = '/etc/orbit/e2e-opencode-server.json';
    $configContent = "{\"hostname\":\"127.0.0.1\",\"port\":4096}\n";
    $expectedHash = hash('sha256', $configContent);

    try {
        e2eRestartGatewayApi($topology, 'tools-doctor-fix');
        toolsDoctorFixPrepareDevNode($topology, $configPath);
        toolsDoctorFixSeedGatewayIntent($topology, $configPath, $configContent);

        $missing = toolsDoctorFixRun($topology, 'tool.config_missing', allowFailure: true);
        $missingPayload = e2eJsonCommandPayload($missing->output());
        $missingIssue = toolsDoctorFixIssue($missingPayload, 'tool.config_missing');

        expect($missing->successful())->toBeFalse($missing->output().$missing->errorOutput())
            ->and(e2eJsonCommandError($missingPayload)['code'])->toBe('drift_detected')
            ->and($missingIssue)->toMatchArray([
                'family' => 'tool',
                'node' => 'app-dev-1',
                'key' => 'tool.config_missing',
                'code' => 'tool.config_missing',
                'kind' => 'missing',
                'restorable' => true,
                'detail' => [
                    'tool' => 'opencode-server',
                    'path' => $configPath,
                    'mode' => '0640',
                    'directory_mode' => '0755',
                    'sensitive' => false,
                    'expected_hash' => $expectedHash,
                ],
            ]);

        $result = toolsDoctorFixRun($topology, 'tool.config_missing', restore: true);
        $data = e2eJsonCommandData(e2eJsonCommandPayload($result->output()));

        expect($result->successful())->toBeTrue()
            ->and($data['doctor']['healthy'])->toBeTrue()
            ->and($data['doctor']['summary']['fixed'])->toBe(1)
            ->and($data['doctor']['actions'][0])->toMatchArray([
                'family' => 'tool',
                'node' => 'app-dev-1',
                'key' => 'tool.config_missing',
                'mode' => 'restore',
                'status' => 'completed',
            ]);

        expect(toolsDoctorFixRemoteHash($topology, $configPath))->toBe($expectedHash)
            ->and(toolsDoctorFixRemoteMode($topology, $configPath))->toBe('640');

        $topology->ssh('dev', 'sudo chmod 0600 '.escapeshellarg($configPath), timeoutSeconds: 60);

        $mismatch = toolsDoctorFixRun($topology, 'tool.config_mismatch', allowFailure: true);
        $mismatchPayload = e2eJsonCommandPayload($mismatch->output());
        $mismatchIssue = toolsDoctorFixIssue($mismatchPayload, 'tool.config_mismatch');

        expect($mismatch->successful())->toBeFalse($mismatch->output().$mismatch->errorOutput())
            ->and(e2eJsonCommandError($mismatchPayload)['code'])->toBe('drift_detected')
            ->and($mismatchIssue)->toMatchArray([
                'family' => 'tool',
                'node' => 'app-dev-1',
                'key' => 'tool.config_mismatch',
                'code' => 'tool.config_mismatch',
                'kind' => 'divergent',
                'restorable' => true,
                'detail' => [
                    'tool' => 'opencode-server',
                    'path' => $configPath,
                    'mode' => '0640',
                    'directory_mode' => '0755',
                    'sensitive' => false,
                    'observed_mode' => '600',
                    'expected_hash' => $expectedHash,
                    'observed_hash' => $expectedHash,
                ],
            ]);

        $restoreMode = toolsDoctorFixRun($topology, 'tool.config_mismatch', restore: true);
        $restoreModeData = e2eJsonCommandData(e2eJsonCommandPayload($restoreMode->output()));

        expect($restoreMode->successful())->toBeTrue($restoreMode->output().$restoreMode->errorOutput())
            ->and($restoreModeData['doctor']['healthy'])->toBeTrue()
            ->and($restoreModeData['doctor']['summary']['fixed'])->toBe(1)
            ->and(toolsDoctorFixRemoteHash($topology, $configPath))->toBe($expectedHash)
            ->and(toolsDoctorFixRemoteMode($topology, $configPath))->toBe('640');
    } finally {
        $topology->ssh('dev', 'sudo rm -f '.escapeshellarg($configPath).' /usr/local/bin/opencode', timeoutSeconds: 60);
        $topology->cleanup();
    }
});

function toolsDoctorFixPrepareDevNode(E2ETopologyHarness $topology, string $configPath): void
{
    $result = $topology->ssh(
        'dev',
        sprintf(
            'printf %s | sudo tee /usr/local/bin/opencode >/dev/null && sudo chmod 0755 /usr/local/bin/opencode && sudo rm -f %s',
            escapeshellarg("#!/usr/bin/env bash\necho 'opencode 1.0.0'\n"),
            escapeshellarg($configPath),
        ),
        timeoutSeconds: 60,
    );

    expect($result->successful())->toBeTrue($result->output().$result->errorOutput());
}

function toolsDoctorFixSeedGatewayIntent(E2ETopologyHarness $topology, string $configPath, string $configContent): void
{
    $configPathValue = var_export($configPath, true);
    $configContentValue = var_export($configContent, true);
    $configHashValue = var_export(hash('sha256', $configContent), true);
    $configModeValue = var_export('0640', true);

    $php = <<<PHP
\$node = \\App\\Models\\Node::query()->where('name', 'app-dev-1')->firstOrFail();

\\App\\Models\\NodeTool::query()->updateOrCreate(
    ['node_id' => \$node->id, 'name' => 'opencode-server'],
    [
        'expected_state' => 'installed',
        'expected_version' => null,
        'config' => [
            'managed_config' => [
                'path' => {$configPathValue},
                'hash' => {$configHashValue},
                'content' => {$configContentValue},
                'mode' => {$configModeValue},
            ],
        ],
        'credentials' => null,
    ],
);

echo 'seeded';
PHP;

    $result = $topology->ssh(
        'gateway',
        'cd '.escapeshellarg($topology->checkout('gateway')).' && php apps/gateway/artisan tinker --execute='.escapeshellarg($php),
        timeoutSeconds: 120,
    );

    expect($result->successful())->toBeTrue($result->output().$result->errorOutput());
}

function toolsDoctorFixRun(
    E2ETopologyHarness $topology,
    string $key,
    bool $restore = false,
    bool $allowFailure = false,
): ProcessResult {
    return $topology->ssh(
        'gateway',
        sprintf(
            'cd %s && orbit doctor --node=app-dev-1 --family=tool --key=%s%s --json',
            escapeshellarg($topology->checkout('gateway')),
            escapeshellarg($key),
            $restore ? ' --restore' : '',
        ),
        timeoutSeconds: 180,
        allowFailure: $allowFailure,
    );
}

/**
 * @param  array<string, mixed>  $payload
 * @return array<string, mixed>
 */
function toolsDoctorFixIssue(array $payload, string $code): array
{
    $error = e2eJsonCommandError($payload);
    $data = $error !== []
        ? ($error['data'] ?? [])
        : e2eJsonCommandData($payload);

    $issues = is_array($data)
        ? ($data['doctor']['issues'] ?? [])
        : [];
    $issue = collect($issues)->firstWhere('code', $code);

    if (is_array($issue)) {
        return $issue;
    }

    $reportedCodes = collect($issues)
        ->pluck('code')
        ->filter()
        ->implode(', ');

    throw new RuntimeException(sprintf(
        'Expected doctor issue [%s] was not reported. Reported issues: [%s]. Payload: %s',
        $code,
        $reportedCodes,
        json_encode($payload, JSON_THROW_ON_ERROR),
    ));
}

function toolsDoctorFixRemoteHash(E2ETopologyHarness $topology, string $path): string
{
    $result = $topology->ssh(
        'dev',
        'sudo sha256sum '.escapeshellarg($path)." | awk '{print $1}'",
        timeoutSeconds: 60,
    );

    expect($result->successful())->toBeTrue($result->output().$result->errorOutput());

    return trim($result->output());
}

function toolsDoctorFixRemoteMode(E2ETopologyHarness $topology, string $path): string
{
    $result = $topology->ssh(
        'dev',
        "sudo stat -c '%a' ".escapeshellarg($path),
        timeoutSeconds: 60,
    );

    expect($result->successful())->toBeTrue($result->output().$result->errorOutput());

    return trim($result->output());
}
