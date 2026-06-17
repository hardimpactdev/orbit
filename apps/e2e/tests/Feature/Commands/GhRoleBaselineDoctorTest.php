<?php

declare(strict_types=1);

use App\E2E\Support\E2EConfig;
use App\E2E\Support\E2ETopologyCapabilities;
use App\E2E\Support\E2ETopologyFactory;
use App\E2E\Support\E2ETopologyHarness;
use App\E2E\Support\E2ETopologyKind;
use App\E2E\Support\E2ETopologyUnavailable;
use Illuminate\Contracts\Process\ProcessResult;

it('repairs gh on app-role nodes through the tool doctor', function (): void {
    $topology = ghRoleBaselineTopology()
        ->withCurrentCheckout(roles: ['gateway']);

    try {
        ghRoleBaselineConvergeAppRoles($topology);
        e2eRestartGatewayApi($topology, 'gh-role-baseline');

        foreach (['dev' => 'app-dev-1', 'prod' => 'app-prod-1'] as $role => $node) {
            ghRoleBaselineWaitForGithubDns($topology, $role);
            ghRoleBaselineRemoveGh($topology, $role);
            ghRoleBaselineWaitForGatewayRemoteShell($topology, $node);

            $result = ghRoleBaselineRunToolDoctorRestore($topology, $node);
            $data = e2eJsonCommandData(e2eJsonCommandPayload($result->output()));
            $action = ghRoleBaselineGhAction($data, $node);

            expect($result->successful())->toBeTrue($result->output().$result->errorOutput())
                ->and($data['doctor']['healthy'])->toBeTrue()
                ->and($action)->toMatchArray([
                    'family' => 'tool',
                    'node' => $node,
                    'key' => 'tool.capability_missing',
                    'mode' => 'restore',
                    'status' => 'completed',
                    'details' => ['tool' => 'gh'],
                ]);

            $probe = $topology->ssh($role, 'command -v gh && gh --version | head -n 1', timeoutSeconds: 60);

            expect($probe->successful())->toBeTrue($probe->output().$probe->errorOutput())
                ->and($probe->output())->toContain('gh version');
        }
    } finally {
        $topology->cleanup();
    }
})->group('e2e-feature', 'e2e-provider-incus', 'e2e-feature-operator_gateway_app-dev_app-prod');

function ghRoleBaselineTopology(): E2ETopologyHarness
{
    if (getenv('ORBIT_E2E') !== '1') {
        test()->markTestSkipped('Set ORBIT_E2E=1 to run prepared-topology feature tests against a provider.');
    }

    $factory = E2ETopologyFactory::fromEnvironment()
        ->withSshUsers(['operator' => E2EConfig::fromEnvironment()->operatorUser])
        ->withGatewayApi()
        ->requireCapabilities(E2ETopologyCapabilities::vm());

    try {
        $lease = $factory->require(E2ETopologyKind::OperatorGatewayAppdevAppprod);
    } catch (E2ETopologyUnavailable $exception) {
        if (e2eFailsOnTopologyUnavailable()) {
            throw $exception;
        }

        test()->markTestSkipped($exception->getMessage());
    }

    return new E2ETopologyHarness($lease);
}

function ghRoleBaselineConvergeAppRoles(E2ETopologyHarness $topology): void
{
    $php = <<<'PHP'
$converger = app(\App\Services\Nodes\Roles\NodeRoleBaselineConverger::class);
$allowedByNode = [
    'app-dev-1' => ['caddy', 'php-cli', 'composer', 'gh', 'laravel-installer'],
    'app-prod-1' => ['caddy', 'php-cli', 'composer', 'gh', 'laravel-installer'],
];

foreach ([
    'app-dev-1' => \App\Enums\Nodes\NodeRoleName::AppDevelopment->value,
    'app-prod-1' => \App\Enums\Nodes\NodeRoleName::AppProduction->value,
] as $nodeName => $role) {
    $node = \App\Models\Node::query()->where('name', $nodeName)->firstOrFail();
    $node->forceFill([
        'platform' => $node->platform ?: 'ubuntu_24-04',
        'status' => 'active',
    ])->save();

    $assignment = \App\Models\NodeRoleAssignment::query()
        ->where('node_id', $node->id)
        ->where('role', $role)
        ->firstOrFail();

    $converger->converge($node, $assignment);

    \App\Models\NodeTool::query()
        ->where('node_id', $node->id)
        ->whereNotIn('name', $allowedByNode[$nodeName])
        ->delete();

    \App\Models\NodeTool::query()
        ->where('node_id', $node->id)
        ->where('name', 'gh')
        ->where('expected_state', 'installed')
        ->firstOrFail();
}

echo 'converged';
PHP;

    $result = $topology->ssh(
        'gateway',
        'cd '.escapeshellarg($topology->checkout('gateway')).' && php apps/gateway/artisan tinker --execute='.escapeshellarg($php),
        timeoutSeconds: 120,
    );

    expect($result->successful())->toBeTrue($result->output().$result->errorOutput());
}

function ghRoleBaselineWaitForGatewayRemoteShell(E2ETopologyHarness $topology, string $node): void
{
    $nodeName = var_export($node, true);
    $php = <<<PHP
\$node = \App\Models\Node::query()->where('name', {$nodeName})->firstOrFail();
\$deadline = time() + 120;
\$last = null;
\$ready = false;

do {
    \$last = app(\App\Contracts\RemoteShell::class)->run(\$node, 'true', ['timeout' => 20]);

    if (\$last->successful()) {
        \$ready = true;
        break;
    }

    sleep(2);
} while (time() < \$deadline);

if (! \$ready) {
    throw new \RuntimeException(\$last?->output() ?: 'gateway remote shell did not become ready');
}

echo 'ready';
PHP;

    $result = $topology->ssh(
        'gateway',
        'cd '.escapeshellarg($topology->checkout('gateway')).' && php apps/gateway/artisan tinker --execute='.escapeshellarg($php),
        timeoutSeconds: 150,
    );

    expect($result->successful())->toBeTrue($result->output().$result->errorOutput());
}

function ghRoleBaselineWaitForGithubDns(E2ETopologyHarness $topology, string $role): void
{
    $result = $topology->ssh(
        $role,
        'deadline=$((SECONDS+30)); until timeout 5 getent hosts cli.github.com >/dev/null 2>&1; do if [ "$SECONDS" -ge "$deadline" ]; then timeout 5 getent hosts cli.github.com; exit 1; fi; sleep 2; done',
        timeoutSeconds: 60,
        allowFailure: true,
    );

    if (! $result->successful()) {
        test()->markTestSkipped('Incus node cannot resolve cli.github.com; skipping GitHub CLI baseline repair test.');
    }
}

function ghRoleBaselineRemoveGh(E2ETopologyHarness $topology, string $role): void
{
    $result = $topology->ssh(
        $role,
        'sudo apt-get -o DPkg::Lock::Timeout=300 purge -y -qq gh >/tmp/orbit-gh-purge.log 2>&1 || true; sudo rm -f /usr/bin/gh /usr/local/bin/gh; ! command -v gh >/dev/null 2>&1',
        timeoutSeconds: 180,
    );

    expect($result->successful())->toBeTrue($result->output().$result->errorOutput());
}

function ghRoleBaselineRunToolDoctorRestore(E2ETopologyHarness $topology, string $node): ProcessResult
{
    return $topology->ssh(
        'gateway',
        sprintf(
            'cd %s && orbit doctor --node=%s --family=tool --key=tool.capability_missing --restore --json',
            escapeshellarg($topology->checkout('gateway')),
            escapeshellarg($node),
        ),
        timeoutSeconds: 420,
    );
}

/**
 * @param  array<string, mixed>  $data
 * @return array<string, mixed>
 */
function ghRoleBaselineGhAction(array $data, string $node): array
{
    $actions = $data['doctor']['actions'] ?? [];

    if (! is_array($actions)) {
        return [];
    }

    foreach ($actions as $action) {
        if (! is_array($action)) {
            continue;
        }

        if (($action['node'] ?? null) === $node && data_get($action, 'details.tool') === 'gh') {
            return $action;
        }
    }

    return [];
}
