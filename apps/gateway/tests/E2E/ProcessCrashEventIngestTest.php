<?php

declare(strict_types=1);

use App\E2E\Support\E2ETopologyHarness;
use App\E2E\Support\E2ETopologyKind;

it('ingests authenticated crashed events from an app node through the gateway api', function (): void {
    $topology = e2eTopology(E2ETopologyKind::OperatorGatewayAppdev)
        ->withCurrentCheckout(roles: ['gateway']);
    $app = 'e2ecrash'.strtolower(bin2hex(random_bytes(3)));
    $appPath = "/home/orbit/apps/{$app}";
    $process = 'worker';
    $runtimeUnit = "orbit_{$app}_main_{$process}";
    $eventId = "e2e-crash-{$app}";

    try {
        processCrashEventSeedIntent($topology, $app, $appPath, $process);

        $payload = [
            'event_id' => $eventId,
            'event' => 'crashed',
            'unit' => $runtimeUnit,
            'exit_code' => 1,
            'exit_status' => 'exited',
            'at' => '2026-05-07T12:00:00+00:00',
        ];

        $first = processCrashEventPost($topology, $payload);
        $second = processCrashEventPost($topology, $payload);
        $state = processCrashEventState($topology, $eventId);

        expect($first['success']['meta'])->toMatchArray(['matched' => true])
            ->and($second['success']['meta'])->toMatchArray(['idempotent' => true])
            ->and($second['success']['data']['id'])->toBe($first['success']['data']['id'])
            ->and($state)->toMatchArray([
                'count' => 1,
                'event' => 'crashed',
                'node' => 'app-dev-1',
                'app' => $app,
                'process' => $process,
                'workspace' => null,
                'unit_name' => $runtimeUnit,
                'exit_code' => 1,
                'exit_status' => 'exited',
            ]);
    } finally {
        processCrashEventCleanup($topology, $app, $eventId);
        $topology->cleanup();
    }
})->group('e2e-feature', 'e2e-feature-operator_gateway_app-dev', 'e2e-feature-operator-gateway-dev');

function processCrashEventSeedIntent(E2ETopologyHarness $topology, string $app, string $path, string $process): void
{
    $script = <<<'PHP'
$node = \App\Models\Node::query()->where('name', 'app-dev-1')->firstOrFail();
$node->update(['status' => 'active', 'platform' => 'ubuntu']);

$app = \App\Models\App::query()->updateOrCreate(
    ['name' => '__APP__'],
    [
        'node_id' => $node->id,
        'path' => '__PATH__',
        'document_root' => 'public',
        'php_version' => '8.5',
        'adopted' => true,
    ],
);

\App\Models\Process::query()->updateOrCreate(
    ['app_id' => $app->id, 'name' => '__PROCESS__'],
    [
        'command' => 'sleep 300',
        'restart_policy' => 'never',
        'crash_notification' => 'agent_ide',
        'sort_order' => 1,
    ],
);

echo 'seeded';
PHP;

    $script = str_replace(
        ['__APP__', '__PATH__', '__PROCESS__'],
        [str_replace("'", "\\'", $app), str_replace("'", "\\'", $path), str_replace("'", "\\'", $process)],
        $script,
    );

    $topology->ssh(
        'gateway',
        'cd '.escapeshellarg($topology->checkout('gateway')).' && orbit tinker --execute='.escapeshellarg($script),
        timeoutSeconds: 120,
    );
}

/**
 * @param  array<string, mixed>  $payload
 * @return array<string, mixed>
 */
function processCrashEventPost(E2ETopologyHarness $topology, array $payload): array
{
    $payloadValue = var_export($payload, true);
    $script = <<<PHP
\$node = \\App\\Models\\Node::query()->where('name', 'app-dev-1')->firstOrFail();
\$request = \\Illuminate\\Http\\Request::create('/api/events/process', 'POST', {$payloadValue}, [], [], [
    'REMOTE_ADDR' => \$node->wireguard_address,
    'HTTP_ACCEPT' => 'application/json',
]);
\$response = app(\\Illuminate\\Contracts\\Http\\Kernel::class)->handle(\$request);
echo \$response->getContent();
PHP;

    $result = $topology->ssh(
        'gateway',
        'cd '.escapeshellarg($topology->checkout('gateway')).' && orbit tinker --execute='.escapeshellarg($script),
        timeoutSeconds: 120,
    );

    return json_decode(trim($result->output()), associative: true, flags: JSON_THROW_ON_ERROR);
}

/**
 * @return array<string, mixed>
 */
function processCrashEventState(E2ETopologyHarness $topology, string $eventId): array
{
    $eventIdValue = var_export($eventId, true);
    $script = <<<PHP
\$events = \\App\\Models\\ProcessEvent::query()
    ->with(['node', 'app', 'process', 'workspace'])
    ->where('event_id', {$eventIdValue})
    ->get();
\$event = \$events->first();

echo json_encode([
    'count' => \$events->count(),
    'event' => \$event?->event?->value,
    'node' => \$event?->node?->name,
    'app' => \$event?->app?->name,
    'process' => \$event?->process?->name,
    'workspace' => \$event?->workspace?->name,
    'unit_name' => \$event?->unit_name,
    'exit_code' => \$event?->exit_code,
    'exit_status' => \$event?->exit_status,
], JSON_THROW_ON_ERROR);
PHP;

    $result = $topology->ssh(
        'gateway',
        'cd '.escapeshellarg($topology->checkout('gateway')).' && orbit tinker --execute='.escapeshellarg($script),
        timeoutSeconds: 120,
    );

    return json_decode(trim($result->output()), associative: true, flags: JSON_THROW_ON_ERROR);
}

function processCrashEventCleanup(E2ETopologyHarness $topology, string $app, string $eventId): void
{
    $appValue = var_export($app, true);
    $eventIdValue = var_export($eventId, true);
    $script = <<<PHP
\\App\\Models\\ProcessEvent::query()->where('event_id', {$eventIdValue})->delete();

if (\$app = \\App\\Models\\App::query()->where('name', {$appValue})->first()) {
    \$app->processes()->delete();
    \$app->delete();
}
PHP;

    $topology->ssh(
        'gateway',
        'cd '.escapeshellarg($topology->checkout('gateway')).' && orbit tinker --execute='.escapeshellarg($script).' >/dev/null 2>&1 || true',
        timeoutSeconds: 120,
    );
}
