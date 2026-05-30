<?php

declare(strict_types=1);

use App\E2E\Support\E2ETopologyHarness;
use App\E2E\Support\E2ETopologyKind;

function phpRuntimeCommandsSeed(E2ETopologyHarness $topology): void
{
    $checkout = escapeshellarg($topology->checkout('gateway'));
    $script = <<<'PHP'
$nodes = \App\Models\Node::query()
    ->whereIn('name', ['operator-1', 'app-dev-1'])
    ->pluck('id', 'name');

foreach (['operator-1', 'app-dev-1'] as $name) {
    if (! $nodes->has($name)) {
        throw new \RuntimeException("Missing prepared node [{$name}].");
    }
}

\Spatie\Activitylog\Models\Activity::query()->delete();
\Illuminate\Support\Facades\DB::table('workspaces')->delete();
\App\Models\App::query()->delete();
\App\Models\NodeTool::query()->where('name', 'php')->delete();
\Illuminate\Support\Facades\DB::table('node_access')->delete();
\Illuminate\Support\Facades\DB::table('node_access')->insert([
    'consumer_node_id' => $nodes->get('operator-1'),
    'serving_node_id' => $nodes->get('app-dev-1'),
    'created_at' => now(),
    'updated_at' => now(),
]);

$app = \App\Models\App::query()->create([
    'name' => 'docs',
    'node_id' => $nodes->get('app-dev-1'),
    'path' => '/home/orbit/apps/docs',
    'document_root' => 'public',
    'php_version' => '8.4',
]);

\App\Models\Workspace::query()->create([
    'app_id' => $app->id,
    'name' => 'feature-docs',
    'path' => '/home/orbit/apps/docs/.worktrees/feature-docs',
    'php_version' => null,
    'lifecycle_status' => \App\Enums\WorkspaceLifecycleStatus::Expected,
]);

\App\Models\NodeTool::query()->create([
    'node_id' => $nodes->get('app-dev-1'),
    'name' => 'php',
    'expected_state' => 'running',
    'expected_version' => null,
    'config' => [
        'versions' => ['8.5', '8.4'],
        'images' => [
            'dunglas/frankenphp:1-php8.5-bookworm',
            'dunglas/frankenphp:1-php8.4-bookworm',
        ],
        'cli_version' => '8.4',
    ],
]);

echo 'seeded';
PHP;

    $topology->ssh(
        'gateway',
        "cd {$checkout} && php apps/gateway/artisan tinker --execute=".escapeshellarg($script),
        timeoutSeconds: 120,
    );

    $topology->ssh(
        'dev',
        'sudo install -m 0666 -o orbit -g orbit /dev/null /tmp/orbit-php-runtime-install.log',
        timeoutSeconds: 60,
    );
}

/**
 * @return array<string, mixed>
 */
function phpRuntimeCommandsGatewayState(E2ETopologyHarness $topology): array
{
    $script = <<<'PHP'
$tool = \App\Models\NodeTool::query()
    ->where('name', 'php')
    ->first();

echo json_encode([
    'app_php_version' => \App\Models\App::query()->where('name', 'docs')->value('php_version'),
    'workspace_php_version' => \App\Models\Workspace::query()->where('name', 'feature-docs')->value('php_version'),
    'php_tool_count' => \App\Models\NodeTool::query()->where('name', 'php')->count(),
    'php_tool_config' => $tool?->config,
], JSON_THROW_ON_ERROR);
PHP;

    $result = $topology->ssh(
        'gateway',
        'cd '.escapeshellarg($topology->checkout('gateway')).' && php apps/gateway/artisan tinker --execute='.escapeshellarg($script),
        timeoutSeconds: 120,
    );

    return json_decode(trim($result->output()), associative: true, flags: JSON_THROW_ON_ERROR);
}

it('reads and changes PHP runtime intent without installing runtimes', function (): void {
    $topology = e2eTopology(E2ETopologyKind::OperatorGatewayAppdev);

    try {
        $topology->withCurrentCheckout(roles: ['gateway']);
        e2eRestartGatewayApi($topology, 'php-runtime-commands');
        phpRuntimeCommandsSeed($topology);

        $list = $topology->ssh(
            'gateway',
            sprintf(
                'cd %s && orbit php:list --app=docs --workspace=feature-docs --json',
                escapeshellarg($topology->checkout('gateway')),
            ),
            timeoutSeconds: 120,
        );
        $listPayload = json_decode(trim($list->output()), associative: true, flags: JSON_THROW_ON_ERROR);

        expect($list->successful())->toBeTrue()
            ->and($listPayload['success']['data']['php'])->toMatchArray([
                'node' => 'app-dev-1',
                'available_images' => ['8.5', '8.4'],
                'cli' => '8.4',
                'app' => [
                    'name' => 'docs',
                    'php_version' => '8.4',
                ],
                'workspace' => [
                    'name' => 'feature-docs',
                    'php_version' => '8.4',
                    'inherits' => true,
                ],
            ]);

        $appUse = $topology->ssh(
            'gateway',
            sprintf(
                'cd %s && orbit php:use 8.5 --app=docs --json',
                escapeshellarg($topology->checkout('gateway')),
            ),
            timeoutSeconds: 120,
        );
        $appUsePayload = json_decode(trim($appUse->output()), associative: true, flags: JSON_THROW_ON_ERROR);

        $workspaceUse = $topology->ssh(
            'gateway',
            sprintf(
                'cd %s && orbit php:use 8.4 --app=docs --workspace=feature-docs --json',
                escapeshellarg($topology->checkout('gateway')),
            ),
            timeoutSeconds: 120,
        );
        $workspaceUsePayload = json_decode(trim($workspaceUse->output()), associative: true, flags: JSON_THROW_ON_ERROR);

        $cliUse = $topology->ssh(
            'gateway',
            sprintf(
                'cd %s && orbit php:use 8.5 --node=app-dev-1 --cli --json',
                escapeshellarg($topology->checkout('gateway')),
            ),
            timeoutSeconds: 120,
        );
        $cliUsePayload = json_decode(trim($cliUse->output()), associative: true, flags: JSON_THROW_ON_ERROR);

        expect($appUse->successful())->toBeTrue()
            ->and($appUsePayload['success']['data']['result'])->toMatchArray([
                'target' => 'app',
                'app' => 'docs',
                'previous' => '8.4',
                'version' => '8.5',
                'changed' => true,
            ])
            ->and($workspaceUse->successful())->toBeTrue()
            ->and($workspaceUsePayload['success']['data']['result'])->toMatchArray([
                'target' => 'workspace',
                'workspace' => 'feature-docs',
                'version' => '8.4',
                'inherits' => false,
                'changed' => true,
            ])
            ->and($cliUse->successful())->toBeTrue()
            ->and($cliUsePayload['success']['data']['result'])->toMatchArray([
                'target' => 'node_cli',
                'node' => 'app-dev-1',
                'previous' => '8.4',
                'version' => '8.5',
                'changed' => true,
            ]);

        $state = phpRuntimeCommandsGatewayState($topology);

        expect($state)->toMatchArray([
            'app_php_version' => '8.5',
            'workspace_php_version' => '8.4',
            'php_tool_count' => 1,
            'php_tool_config' => [
                'versions' => ['8.5', '8.4'],
                'images' => [
                    'dunglas/frankenphp:1-php8.5-bookworm',
                    'dunglas/frankenphp:1-php8.4-bookworm',
                ],
                'cli_version' => '8.5',
            ],
        ]);

        $installLog = $topology->ssh('dev', 'cat /tmp/orbit-php-runtime-install.log', timeoutSeconds: 60);

        expect($installLog->successful())->toBeTrue()
            ->and(trim($installLog->output()))->toBe('');
    } finally {
        $topology->cleanup();
    }
})->group('e2e-feature', 'e2e-feature-operator_gateway_app-dev', 'e2e-feature-operator-gateway-dev');
