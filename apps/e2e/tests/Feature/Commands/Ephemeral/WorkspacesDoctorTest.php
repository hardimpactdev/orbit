<?php

declare(strict_types=1);

use App\E2E\Support\E2ETopologyHarness;
use App\E2E\Support\E2ETopologyKind;

pest()->group('e2e-feature', 'e2e-feature-operator_gateway_app-dev', 'e2e-feature-operator-gateway-dev');

it('reports workspace source drift from gateway intent', function (): void {
    $topology = e2eTopology(E2ETopologyKind::OperatorGatewayAppdev)
        ->withCurrentCheckout(roles: ['gateway']);
    $workspaceName = 'e2e-ws-doc-'.strtolower(bin2hex(random_bytes(3)));
    $workspacePath = "/home/orbit/apps/docs/.worktrees/{$workspaceName}";

    try {
        e2eRestartGatewayApi($topology, 'workspaces-doctor');
        workspacesDoctorSeedGatewayIntent($topology, $workspaceName, $workspacePath);
        $topology->ssh('dev', 'sudo rm -rf '.escapeshellarg($workspacePath), timeoutSeconds: 60);

        $result = $topology->instance('gateway')->ssh(
            'orbit',
            $topology->lease()->sshKeyPair(),
            sprintf(
                'cd %s && orbit doctor --node=app-dev-1 --family=workspace --json',
                escapeshellarg($topology->checkout('gateway')),
            ),
            180,
        );
        $payload = json_decode(trim($result->output()), associative: true, flags: JSON_THROW_ON_ERROR);
        $error = e2eJsonCommandError($payload);

        expect($result->successful())
            ->toBeFalse()
            ->and($error['code'])
            ->toBe('drift_detected')
            ->and($error['data']['doctor']['issues'][0])
            ->toMatchArray([
                'family' => 'workspace',
                'node' => 'app-dev-1',
                'key' => 'workspace.path_missing',
                'kind' => 'missing',
            ]);
    } finally {
        $topology->cleanup();
    }
});

function workspacesDoctorSeedGatewayIntent(
    E2ETopologyHarness $topology,
    string $workspaceName,
    string $workspacePath,
): void {
    $workspaceNameValue = var_export($workspaceName, true);
    $workspacePathValue = var_export($workspacePath, true);
    $script = <<<PHP
        \$node = \\App\\Models\\Node::query()->where('name', 'app-dev-1')->firstOrFail();

        \\Illuminate\\Support\\Facades\\DB::table('workspace_run_steps')->delete();
        \\Illuminate\\Support\\Facades\\DB::table('workspace_runs')->delete();
        \\Illuminate\\Support\\Facades\\DB::table('workspace_steps')->delete();
        \\Illuminate\\Support\\Facades\\DB::table('proxy_routes')->delete();
        \\Illuminate\\Support\\Facades\\DB::table('workspaces')->delete();
        \\App\\Models\\App::query()->delete();

        \$app = \\App\\Models\\App::query()->create([
            'name' => 'docs',
            'node_id' => \$node->id,
            'path' => '/home/orbit/apps/docs',
            'document_root' => 'public',
            'php_version' => '8.5',
        ]);

        \\App\\Models\\Workspace::query()->create([
            'app_id' => \$app->id,
            'name' => {$workspaceNameValue},
            'path' => {$workspacePathValue},
            'php_version' => null,
            'lifecycle_status' => \\App\\Enums\\WorkspaceLifecycleStatus::Expected,
        ]);

        echo 'seeded';
        PHP;

    $topology->ssh(
        'gateway',
        'cd '.escapeshellarg($topology->checkout('gateway')).' && php apps/gateway/artisan tinker --execute='
            .escapeshellarg($script),
        timeoutSeconds: 120,
    );
}
