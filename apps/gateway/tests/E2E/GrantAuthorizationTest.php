<?php

declare(strict_types=1);

use App\E2E\Support\E2EConfig;
use App\E2E\Support\E2EGatewayApi;
use App\E2E\Support\E2ETopologyHarness;
use App\E2E\Support\E2ETopologyKind;

it('enforces grants through real gateway middleware and node access rows', function (): void {
    $config = E2EConfig::fromEnvironment();
    $topology = e2eTopology(E2ETopologyKind::OperatorGatewayAppdevAppprod, withGatewayApi: true);
    $appPath = '/home/orbit/apps/grant-docs';
    $workspaceName = 'e2e-grant-'.strtolower(bin2hex(random_bytes(3)));
    $workspacePath = "{$appPath}/.worktrees/{$workspaceName}";

    try {
        $topology->withCurrentCheckout(roles: ['operator', 'gateway']);
        $gatewayApiIp = $topology->lease()->gatewayApiIp();

        e2eRestartGatewayApi($topology, 'grant-authorization');
        E2EGatewayApi::waitForGatewayApi($topology->instance('operator'), $config->operatorUser, $topology->lease()->sshKeyPair(), gatewayIp: $gatewayApiIp);

        grantAuthorizationE2eResetGatewayState($topology);

        $denied = grantAuthorizationE2eApi(
            topology: $topology,
            role: 'operator',
            method: 'DELETE',
            path: '/nodes/app-prod-1',
            wireGuardIp: '10.6.0.3',
            payload: ['destructive_consent' => true],
        );

        expect($denied['status'])->toBe(403)
            ->and($denied['body']['error']['code'])->toBe('authorization_failed')
            ->and($denied['body']['error']['meta'])->toMatchArray([
                'reason' => 'missing_permission',
                'missing_permission' => 'node:remove',
                'serving_node' => 'app-prod-1',
            ]);

        $gatewayGrant = grantAuthorizationE2eApi(
            topology: $topology,
            role: 'gateway',
            method: 'POST',
            path: '/nodes/grant',
            wireGuardIp: '10.6.0.2',
            payload: [
                'consuming_node' => 'operator-1',
                'serving_node' => 'gateway',
                'permissions' => 'node:grant',
            ],
        );

        expect($gatewayGrant['status'])->toBe(200)
            ->and($gatewayGrant['body']['success']['data'])->toMatchArray([
                'consuming_node' => 'operator-1',
                'serving_node' => 'gateway',
                'permissions' => ['node:grant'],
            ]);

        $targetGrant = grantAuthorizationE2eApi(
            topology: $topology,
            role: 'operator',
            method: 'POST',
            path: '/nodes/grant',
            wireGuardIp: '10.6.0.3',
            payload: [
                'consuming_node' => 'operator-1',
                'serving_node' => 'app-prod-1',
                'permissions' => 'node:remove',
            ],
        );

        expect($targetGrant['status'])->toBe(200)
            ->and($targetGrant['body']['success']['data'])->toMatchArray([
                'consuming_node' => 'operator-1',
                'serving_node' => 'app-prod-1',
                'permissions' => ['node:remove'],
            ]);

        $removeRole = grantAuthorizationE2eApi(
            topology: $topology,
            role: 'gateway',
            method: 'DELETE',
            path: '/nodes/app-dev-1/roles/app-development',
            wireGuardIp: '10.6.0.2',
            payload: ['force' => true],
        );

        expect($removeRole['status'])->toBe(200)
            ->and($removeRole['body']['success']['data'])->toMatchArray([
                'node' => 'app-dev-1',
                'role' => 'app-development',
            ]);

        grantAuthorizationE2eApplyAppDevelopmentRole($topology);

        expect(grantAuthorizationE2eSelfGrant($topology))->toMatchArray([
            'permissions' => ['workspace:setup'],
            'custom_permissions' => [],
        ]);

        grantAuthorizationE2eSetCustomSelfGrant($topology);

        $removeRoleWithCustomGrant = grantAuthorizationE2eApi(
            topology: $topology,
            role: 'gateway',
            method: 'DELETE',
            path: '/nodes/app-dev-1/roles/app-development',
            wireGuardIp: '10.6.0.2',
            payload: ['force' => true],
        );

        expect($removeRoleWithCustomGrant['status'])->toBe(200)
            ->and(grantAuthorizationE2eSelfGrant($topology))->toMatchArray([
                'permissions' => ['node:read'],
                'custom_permissions' => ['node:read'],
            ]);

        grantAuthorizationE2eApplyAppDevelopmentRole($topology);

        expect(grantAuthorizationE2eSelfGrant($topology))->toMatchArray([
            'permissions' => ['node:read', 'workspace:setup'],
            'custom_permissions' => ['node:read'],
        ]);

        grantAuthorizationE2eSeedWorkspaceApp($topology, $appPath);
        grantAuthorizationE2ePrepareWorkspacePath($topology, $appPath, $workspacePath);

        $workspaceSetup = grantAuthorizationE2eApi(
            topology: $topology,
            role: 'dev',
            method: 'POST',
            path: '/workspaces/setup',
            wireGuardIp: '10.6.0.4',
            payload: [
                'name' => $workspaceName,
                'app' => 'grant-docs',
                'path' => $workspacePath,
            ],
            timeoutSeconds: 300,
        );

        expect($workspaceSetup['status'])->toBe(200)
            ->and($workspaceSetup['body']['success']['data'])->toMatchArray([
                'app' => 'grant-docs',
                'workspace' => $workspaceName,
                'node' => 'app-dev-1',
                'action' => 'adopted',
            ]);

        $removed = grantAuthorizationE2eApi(
            topology: $topology,
            role: 'operator',
            method: 'DELETE',
            path: '/nodes/app-prod-1',
            wireGuardIp: '10.6.0.3',
            payload: ['destructive_consent' => true],
            timeoutSeconds: 180,
        );

        expect($removed['status'])->toBe(200)
            ->and($removed['body']['success']['data'])->toMatchArray([
                'name' => 'app-prod-1',
                'action' => 'removed',
            ]);

        $activity = grantAuthorizationE2eApi(
            topology: $topology,
            role: 'gateway',
            method: 'GET',
            path: '/activity?limit=25',
            wireGuardIp: '10.6.0.2',
        );

        expect($activity['status'])->toBe(200);

        $activities = $activity['body']['success']['data']['activities'];

        expect($activities)->not->toBeEmpty();

        foreach ($activities as $entry) {
            if (! is_array($entry['actor'] ?? null)) {
                continue;
            }

            expect($entry['actor'])->toHaveKey('node');
            expect($entry['actor'])->not->toHaveKey('role');
        }
    } finally {
        $topology->ssh('dev', 'sudo rm -rf '.escapeshellarg($appPath), timeoutSeconds: 60, allowFailure: true);
        $topology->reset();
        $topology->cleanup();
    }
})->group('e2e-feature', 'e2e-feature-operator_gateway_app-dev_app-prod', 'e2e-feature-operator-gateway-dev-prod');

/**
 * @param  array<string, mixed>  $payload
 * @return array{status: int, body: array<string, mixed>}
 */
function grantAuthorizationE2eApi(
    E2ETopologyHarness $topology,
    string $role,
    string $method,
    string $path,
    string $wireGuardIp,
    array $payload = [],
    int $timeoutSeconds = 120,
): array {
    $url = grantAuthorizationE2eApiUrl($topology, $path);

    $parts = [
        'curl',
        '--connect-timeout 5',
        "--max-time {$timeoutSeconds}",
        '-sS',
        '-o "$response"',
        '-w "%{http_code}"',
        '-X '.escapeshellarg($method),
        escapeshellarg($url),
        '-H '.escapeshellarg('Accept: application/json'),
        '-H '.escapeshellarg('Content-Type: application/json'),
        '-H '.escapeshellarg("X-Orbit-E2E-WireGuard-Ip: {$wireGuardIp}"),
    ];

    if ($payload !== []) {
        $parts[] = '--data-binary '.escapeshellarg(json_encode($payload, JSON_THROW_ON_ERROR));
    }

    $command = implode('; ', [
        'set -e',
        'response=$(mktemp)',
        'trap \'rm -f "$response"\' EXIT',
        'status=$('.implode(' ', $parts).')',
        'printf "%s\n" "$status"',
        'cat "$response"',
    ]);

    $result = $topology->ssh($role, $command, timeoutSeconds: $timeoutSeconds + 30);
    $output = trim($result->output());

    if (! str_contains($output, "\n")) {
        throw new RuntimeException("Unexpected gateway API output: {$output}");
    }

    [$status, $body] = explode("\n", $output, 2);
    $decoded = json_decode(trim($body), associative: true, flags: JSON_THROW_ON_ERROR);

    if (! is_array($decoded)) {
        throw new RuntimeException("Gateway API response was not a JSON object: {$body}");
    }

    return [
        'status' => (int) $status,
        'body' => $decoded,
    ];
}

function grantAuthorizationE2eApiUrl(E2ETopologyHarness $topology, string $path): string
{
    $baseUrl = getenv('ORBIT_E2E_TOPOLOGY_PROVIDER') === 'docker'
        ? 'http://gateway'
        : 'http://'.$topology->lease()->gatewayApiIp();

    return $baseUrl.'/api/'.ltrim($path, '/');
}

function grantAuthorizationE2eResetGatewayState(E2ETopologyHarness $topology): void
{
    grantAuthorizationE2eTinker($topology, <<<'PHP'
$nodes = \App\Models\Node::query()
    ->whereIn('name', ['gateway', 'operator-1', 'app-dev-1', 'app-prod-1'])
    ->pluck('id', 'name');

foreach (['gateway', 'operator-1', 'app-dev-1', 'app-prod-1'] as $name) {
    if (! $nodes->has($name)) {
        throw new \RuntimeException("Missing prepared node [{$name}].");
    }
}

\Illuminate\Support\Facades\DB::table('workspace_run_steps')->delete();
\Illuminate\Support\Facades\DB::table('workspace_runs')->delete();
\Illuminate\Support\Facades\DB::table('workspace_steps')->delete();
\Illuminate\Support\Facades\DB::table('processes')->delete();
\Illuminate\Support\Facades\DB::table('proxy_routes')->delete();
\Illuminate\Support\Facades\DB::table('workspaces')->delete();
\App\Models\App::query()->delete();
\Illuminate\Support\Facades\DB::table('node_access')->delete();
\Illuminate\Support\Facades\DB::table('activity_log')->delete();

echo 'reset';
PHP);
}

function grantAuthorizationE2eApplyAppDevelopmentRole(E2ETopologyHarness $topology): void
{
    $response = grantAuthorizationE2eApi(
        topology: $topology,
        role: 'gateway',
        method: 'POST',
        path: '/nodes/app-dev-1/roles',
        wireGuardIp: '10.6.0.2',
        payload: [
            'role' => 'app-development',
            'settings' => ['tld' => 'test'],
        ],
        timeoutSeconds: 180,
    );

    expect($response['status'])->toBe(200)
        ->and($response['body']['success']['data']['node'])->toBe('app-dev-1');
}

/**
 * @return array{permissions: list<string>|null, custom_permissions: list<string>|null}
 */
function grantAuthorizationE2eSelfGrant(E2ETopologyHarness $topology): array
{
    $payload = grantAuthorizationE2eTinker($topology, <<<'PHP'
$node = \App\Models\Node::query()->where('name', 'app-dev-1')->firstOrFail();
$grant = \App\Models\NodeAccess::query()
    ->where('consumer_node_id', $node->id)
    ->where('serving_node_id', $node->id)
    ->first();

echo json_encode([
    'permissions' => $grant?->permissions,
    'custom_permissions' => $grant?->custom_permissions,
], JSON_THROW_ON_ERROR);
PHP);

    $decoded = json_decode($payload, associative: true, flags: JSON_THROW_ON_ERROR);

    if (! is_array($decoded)) {
        throw new RuntimeException("Self-grant state was not a JSON object: {$payload}");
    }

    return $decoded;
}

function grantAuthorizationE2eSetCustomSelfGrant(E2ETopologyHarness $topology): void
{
    grantAuthorizationE2eTinker($topology, <<<'PHP'
$node = \App\Models\Node::query()->where('name', 'app-dev-1')->firstOrFail();
$grant = \App\Models\NodeAccess::query()->firstOrNew([
    'consumer_node_id' => $node->id,
    'serving_node_id' => $node->id,
]);

$grant->permissions = ['node:read', 'workspace:setup'];
$grant->custom_permissions = ['node:read'];
$grant->save();

echo 'custom';
PHP);
}

function grantAuthorizationE2eSeedWorkspaceApp(E2ETopologyHarness $topology, string $appPath): void
{
    $appPathValue = var_export($appPath, true);

    grantAuthorizationE2eTinker($topology, <<<PHP
\$node = \\App\\Models\\Node::query()->where('name', 'app-dev-1')->firstOrFail();

\\App\\Models\\App::query()->updateOrCreate([
    'name' => 'grant-docs',
], [
    'node_id' => \$node->id,
    'environment' => 'development',
    'path' => {$appPathValue},
    'document_root' => 'public',
    'php_version' => '8.5',
    'adopted' => true,
]);

echo 'app';
PHP);
}

function grantAuthorizationE2ePrepareWorkspacePath(E2ETopologyHarness $topology, string $appPath, string $workspacePath): void
{
    $topology->ssh(
        'dev',
        sprintf(
            <<<'SH'
sudo rm -rf %1$s
mkdir -p %1$s/public
cd %1$s
git init -b main
git config user.email orbit@example.test
git config user.name Orbit
printf 'ok\n' > public/index.html
git add .
git commit -m init
mkdir -p %2$s/public
SH,
            escapeshellarg($appPath),
            escapeshellarg($workspacePath),
        ),
        timeoutSeconds: 120,
    );
}

function grantAuthorizationE2eTinker(E2ETopologyHarness $topology, string $script): string
{
    $checkout = escapeshellarg($topology->checkout('gateway'));

    $result = $topology->ssh(
        'gateway',
        "cd {$checkout} && orbit tinker --execute=".escapeshellarg($script),
        timeoutSeconds: 180,
    );

    return trim($result->output());
}
