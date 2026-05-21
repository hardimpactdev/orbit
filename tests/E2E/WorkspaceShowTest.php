<?php

declare(strict_types=1);

use App\E2E\Support\E2EConfig;
use App\E2E\Support\E2EGatewayApi;
use App\E2E\Support\E2ETopologyHarness;
use App\E2E\Support\E2ETopologyKind;

function workspaceShowE2ESeed(E2ETopologyHarness $topology): void
{
    $checkout = escapeshellarg($topology->checkout('gateway'));
    $script = <<<'PHP'
$nodes = \App\Models\Node::query()
    ->whereIn('name', ['control-1', 'app-dev-1'])
    ->pluck('id', 'name');

foreach (['control-1', 'app-dev-1'] as $name) {
    if (! $nodes->has($name)) {
        throw new \RuntimeException("Missing prepared node [{$name}].");
    }
}

\Illuminate\Support\Facades\DB::table('workspace_run_steps')->delete();
\Illuminate\Support\Facades\DB::table('workspace_runs')->delete();
\Illuminate\Support\Facades\DB::table('workspaces')->delete();
\App\Models\App::query()->delete();
\Illuminate\Support\Facades\DB::table('node_access')->delete();
\Illuminate\Support\Facades\DB::table('node_access')->insert([
    'consumer_node_id' => $nodes->get('control-1'),
    'serving_node_id' => $nodes->get('app-dev-1'),
    'permissions' => json_encode(['workspace:read'], JSON_THROW_ON_ERROR),
    'custom_permissions' => json_encode([], JSON_THROW_ON_ERROR),
    'created_at' => now(),
    'updated_at' => now(),
]);

$app = \App\Models\App::query()->create([
    'name' => 'docs',
    'node_id' => $nodes->get('app-dev-1'),
    'environment' => 'development',
    'path' => '/srv/docs',
    'document_root' => 'public',
]);

\App\Models\Workspace::query()->create([
    'app_id' => $app->id,
    'name' => 'feature-docs',
    'path' => '/srv/docs/.worktrees/feature-docs',
    'lifecycle_status' => \App\Enums\WorkspaceLifecycleStatus::Expected,
]);

echo 'seeded';
PHP;

    $topology->ssh(
        'gateway',
        "cd {$checkout} && php artisan tinker --execute=".escapeshellarg($script),
        timeoutSeconds: 120,
    );
}

it('shows workspace details from a non-gateway caller through the gateway api', function (): void {
    $config = E2EConfig::fromEnvironment();
    $topology = e2eTopology(E2ETopologyKind::OperatorGatewayAppdev, withGatewayApi: true);

    try {
        $topology->withCurrentCheckout(roles: ['control', 'gateway']);
        $gatewayApiIp = $topology->lease()->gatewayApiIp();

        e2eRestartGatewayApi($topology, 'workspace-show');
        E2EGatewayApi::waitForGatewayApi($topology->instance('control'), $config->controlUser, $topology->lease()->sshKeyPair(), gatewayIp: $gatewayApiIp);

        workspaceShowE2ESeed($topology);

        // Human output happy path
        $humanResult = $topology->ssh(
            'control',
            sprintf(
                'cd %s && php artisan workspace:show feature-docs --app=docs',
                escapeshellarg($topology->checkout('control')),
            ),
            timeoutSeconds: 120,
            allowFailure: true,
        );

        expect($humanResult->successful())->toBeTrue()
            ->and($humanResult->output())->toContain('Workspace: feature-docs')
            ->and($humanResult->output())->toContain('App')
            ->and($humanResult->output())->toContain('docs');

        // JSON output happy path
        $jsonResult = $topology->ssh(
            'control',
            sprintf(
                'cd %s && php artisan workspace:show feature-docs --app=docs --json',
                escapeshellarg($topology->checkout('control')),
            ),
            timeoutSeconds: 120,
        );

        $payload = json_decode(trim($jsonResult->output()), associative: true, flags: JSON_THROW_ON_ERROR);
        $workspace = $payload['success']['data']['workspace'] ?? null;

        expect($workspace)->toBeArray()
            ->and($workspace['name'])->toBe('feature-docs')
            ->and($workspace['app'])->toBe('docs')
            ->and($payload['success']['meta']['registry_only'])->toBeTrue()
            ->and($workspace)->not->toHaveKey('live_checks');

        // Not-found error in non-interactive mode (--json with bad name)
        $notFoundResult = $topology->ssh(
            'control',
            sprintf(
                'cd %s && php artisan workspace:show nonexistent-ws --app=docs --json',
                escapeshellarg($topology->checkout('control')),
            ),
            timeoutSeconds: 120,
            allowFailure: true,
        );

        $notFoundPayload = json_decode(trim($notFoundResult->output()), associative: true, flags: JSON_THROW_ON_ERROR);

        expect($notFoundResult->successful())->toBeFalse()
            ->and($notFoundPayload['error']['code'])->toBe('workspace.not_found');
    } finally {
        $topology->cleanup();
    }
})->group('e2e-feature', 'e2e-feature-operator-gateway-appdev', 'e2e-feature-control-gateway-dev');
