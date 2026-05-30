<?php

declare(strict_types=1);

use App\E2E\Support\E2ETopologyHarness;
use App\E2E\Support\E2ETopologyKind;
use App\Services\Updates\UnattendedUpgradesAptConfig;
use Illuminate\Contracts\Process\ProcessResult;

it('reports missing unattended-upgrades posture on an Incus app node from the gateway', function (): void {
    $topology = e2eTopology(E2ETopologyKind::OperatorGatewayAppdev)
        ->withCurrentCheckout(roles: ['gateway']);

    try {
        nodeUpdatesDoctorPrepareGatewayRecord($topology);

        $topology->ssh(
            'dev',
            'sudo rm -f /etc/apt/apt.conf.d/20auto-upgrades /etc/apt/apt.conf.d/50unattended-upgrades',
            timeoutSeconds: 60,
        );

        $result = nodeUpdatesDoctorRun($topology, allowFailure: true);
        $payload = nodeUpdatesDoctorPayload($result->output());
        $issue = nodeUpdatesDoctorIssue($payload, 'node.updates_config_missing');

        expect($result->successful())->toBeFalse($result->output().$result->errorOutput())
            ->and($payload['error']['code'])->toBe('drift_detected')
            ->and($issue)->toMatchArray([
                'family' => 'node',
                'node' => 'app-dev-1',
                'key' => 'node.updates',
                'code' => 'node.updates_config_missing',
                'kind' => 'missing',
                'restorable' => true,
            ]);
    } finally {
        nodeUpdatesDoctorRestoreExpectedAptConfig($topology);
        $topology->cleanup();
    }
})->group('e2e-feature', 'e2e-provider-incus', 'e2e-feature-operator_gateway_app-dev', 'e2e-feature-operator-gateway-dev');

it('reports reboot-required update posture without attempting an automatic reboot', function (): void {
    $topology = e2eTopology(E2ETopologyKind::OperatorGatewayAppdev)
        ->withCurrentCheckout(roles: ['gateway']);

    try {
        nodeUpdatesDoctorPrepareGatewayRecord($topology);
        nodeUpdatesDoctorRestoreExpectedAptConfig($topology);

        $topology->ssh(
            'dev',
            "printf '%s\n' linux-image-generic | sudo tee /var/run/reboot-required.pkgs >/dev/null && sudo touch /var/run/reboot-required",
            timeoutSeconds: 60,
        );

        $result = nodeUpdatesDoctorRun($topology, allowFailure: true);
        $payload = nodeUpdatesDoctorPayload($result->output());
        $issue = nodeUpdatesDoctorIssue($payload, 'node.updates_reboot_required');

        expect($result->successful())->toBeFalse($result->output().$result->errorOutput())
            ->and($payload['error']['code'])->toBe('drift_detected')
            ->and($issue)->toMatchArray([
                'family' => 'node',
                'node' => 'app-dev-1',
                'key' => 'node.updates',
                'code' => 'node.updates_reboot_required',
                'kind' => 'divergent',
                'restorable' => false,
            ])
            ->and($issue['summary'])->toContain('Orbit will not reboot it automatically');
    } finally {
        $topology->ssh('dev', 'sudo rm -f /var/run/reboot-required /var/run/reboot-required.pkgs', timeoutSeconds: 60);
        $topology->cleanup();
    }
})->group('e2e-feature', 'e2e-provider-incus', 'e2e-feature-operator_gateway_app-dev', 'e2e-feature-operator-gateway-dev');

function nodeUpdatesDoctorPrepareGatewayRecord(E2ETopologyHarness $topology): void
{
    $php = <<<'PHP'
$node = \App\Models\Node::query()->where('name', 'app-dev-1')->firstOrFail();

$node->forceFill([
    'platform' => 'ubuntu_24-04',
    'status' => 'active',
])->save();

\App\Models\NodeRoleAssignment::query()->updateOrCreate(
    ['node_id' => $node->id, 'role' => \App\Enums\Nodes\NodeRoleName::AppDevelopment->value],
    [
        'status' => \App\Enums\Nodes\NodeRoleStatus::Active->value,
        'settings' => ['tld' => 'test'],
        'last_error' => null,
        'converged_at' => now(),
    ],
);

echo 'prepared';
PHP;

    $topology->ssh(
        'gateway',
        'cd '.escapeshellarg($topology->checkout('gateway')).' && php apps/gateway/artisan tinker --execute='.escapeshellarg($php),
        timeoutSeconds: 120,
    );
}

function nodeUpdatesDoctorRestoreExpectedAptConfig(E2ETopologyHarness $topology): void
{
    $config = new UnattendedUpgradesAptConfig;
    $autoUpgrades = rtrim($config->autoUpgrades(), "\n");
    $unattendedUpgrades = rtrim($config->unattendedUpgrades(), "\n");
    $script = <<<SH
sudo tee /etc/apt/apt.conf.d/20auto-upgrades >/dev/null <<'EOF'
{$autoUpgrades}
EOF
sudo tee /etc/apt/apt.conf.d/50unattended-upgrades >/dev/null <<'EOF'
{$unattendedUpgrades}
EOF
SH;

    $topology->ssh(
        'dev',
        $script,
        timeoutSeconds: 60,
    );
}

function nodeUpdatesDoctorRun(E2ETopologyHarness $topology, bool $allowFailure = false): ProcessResult
{
    return $topology->ssh(
        'gateway',
        sprintf(
            'cd %s && orbit doctor --node=app-dev-1 --family=node --key=node.updates --json',
            escapeshellarg($topology->checkout('gateway')),
        ),
        timeoutSeconds: 240,
        allowFailure: $allowFailure,
    );
}

/**
 * @return array<string, mixed>
 */
function nodeUpdatesDoctorPayload(string $output): array
{
    return json_decode(trim($output), associative: true, flags: JSON_THROW_ON_ERROR);
}

/**
 * @param  array<string, mixed>  $payload
 * @return array<string, mixed>
 */
function nodeUpdatesDoctorIssue(array $payload, string $code): array
{
    $issues = $payload['error']['data']['doctor']['issues'] ?? $payload['success']['data']['doctor']['issues'] ?? [];
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
