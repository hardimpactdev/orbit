<?php

declare(strict_types=1);

use App\E2E\Support\E2EConfig;
use App\E2E\Support\E2EGatewayApi;
use App\E2E\Support\E2ETopologyHarness;
use App\E2E\Support\E2ETopologyKind;

it('renders app-dev FrankenPHP thread pool config for a registered app runtime', function (): void {
    $config = E2EConfig::fromEnvironment();
    $topology = e2eTopology(E2ETopologyKind::OperatorGatewayAppdev, withGatewayApi: true);
    $name = 'e2e-franken-'.strtolower(bin2hex(random_bytes(3)));
    $path = "/home/orbit/apps/{$name}";
    $containerName = "orbit-app-{$name}";

    try {
        $topology->withCurrentCheckout(roles: ['operator', 'gateway']);
        $gatewayApiIp = $topology->lease()->gatewayApiIp();

        e2eRestartGatewayApi($topology, 'app-runtime-config');
        E2EGatewayApi::waitForGatewayApi($topology->instance('operator'), $config->operatorUser, $topology->lease()->sshKeyPair(), gatewayIp: $gatewayApiIp);

        appRuntimeConfigGrantAccess($topology);
        appRuntimeConfigCreateMinimalPhpApp($topology, $path);

        $register = $topology->ssh(
            'operator',
            sprintf(
                'cd %s && orbit app:register %s --node=app-dev-1 --path=%s --json',
                escapeshellarg($topology->checkout('operator')),
                escapeshellarg($name),
                escapeshellarg($path),
            ),
            timeoutSeconds: 180,
        );
        $registrationPayload = e2eJsonCommandPayload($register->output());

        expect(e2eJsonCommandResultData($registrationPayload)['result']['action'])->toBe('adopted');

        $runtime = appRuntimeConfigRenderedRuntime($topology, $name);
        $environment = $runtime['environment'] ?? [];

        expect($runtime['container_name'] ?? null)->toBe($containerName)
            ->and($environment['FRANKENPHP_CONFIG'] ?? null)->toBe("max_threads auto\nmax_idle_time 1h")
            ->and($environment)->not->toHaveKey('MAX_REQUESTS')
            ->and($environment['FRANKENPHP_CONFIG'] ?? '')->not->toContain('worker')
            ->and($runtime['process_runtime_config']['container_spec_hash'] ?? null)->toBe($runtime['container_spec_hash'] ?? '');
    } finally {
        $topology->ssh(
            'dev',
            sprintf(
                'docker rm -f %s >/dev/null 2>&1 || true; sudo rm -rf %s',
                escapeshellarg($containerName),
                escapeshellarg($path),
            ),
            timeoutSeconds: 60,
        );
        $topology->cleanup();
    }
})->group('e2e-feature', 'e2e-feature-operator_gateway_app-dev', 'e2e-feature-operator-gateway-dev');

function appRuntimeConfigGrantAccess(E2ETopologyHarness $topology): void
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

\Illuminate\Support\Facades\DB::table('node_access')->updateOrInsert([
    'consumer_node_id' => $nodes->get('operator-1'),
    'serving_node_id' => $nodes->get('app-dev-1'),
], [
    'created_at' => now(),
    'updated_at' => now(),
]);

echo 'granted';
PHP;

    $topology->ssh(
        'gateway',
        "cd {$checkout} && php apps/gateway/artisan tinker --execute=".escapeshellarg($script),
        timeoutSeconds: 120,
    );
}

function appRuntimeConfigCreateMinimalPhpApp(E2ETopologyHarness $topology, string $path): void
{
    $indexPhp = "<?php\nhttp_response_code(200);\necho 'orbit-e2e-runtime-config-ok';\n";
    $composerJson = json_encode([
        'name' => 'orbit-e2e/runtime-config',
        'require' => (object) [],
        'config' => ['optimize-autoloader' => false],
    ], JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT);

    $topology->ssh(
        'dev',
        sprintf(
            'sudo -u orbit bash -lc %s',
            escapeshellarg(implode(' && ', [
                sprintf('mkdir -p %s', escapeshellarg("{$path}/public")),
                sprintf('printf %%s %s > %s', escapeshellarg($indexPhp), escapeshellarg("{$path}/public/index.php")),
                sprintf('printf %%s %s > %s', escapeshellarg($composerJson), escapeshellarg("{$path}/composer.json")),
            ])),
        ),
        timeoutSeconds: 60,
    );
}

/**
 * @return array<string, mixed>
 */
function appRuntimeConfigRenderedRuntime(E2ETopologyHarness $topology, string $appName): array
{
    $checkout = escapeshellarg($topology->checkout('gateway'));
    $appNameValue = var_export($appName, true);
    $script = <<<PHP
\$app = \\App\\Models\\App::query()
    ->with('node.roleAssignments')
    ->where('name', {$appNameValue})
    ->firstOrFail();
\$container = app(\\App\\Services\\Apps\\AppRuntimeContainerRenderer::class)->render(\$app);
\$process = \\App\\Models\\Process::query()
    ->where('name', "frankenphp-{\$app->name}")
    ->first();

echo json_encode([
    'environment' => \$container->environment(),
    'container_name' => \$container->name(),
    'container_spec_hash' => \$container->specHash(),
    'process_runtime_config' => \$process?->runtime_config,
], JSON_THROW_ON_ERROR);
PHP;

    $result = $topology->ssh(
        'gateway',
        "cd {$checkout} && php apps/gateway/artisan tinker --execute=".escapeshellarg($script),
        timeoutSeconds: 120,
    );

    return json_decode(trim($result->output()), associative: true, flags: JSON_THROW_ON_ERROR);
}
