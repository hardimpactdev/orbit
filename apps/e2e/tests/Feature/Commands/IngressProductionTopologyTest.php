<?php

declare(strict_types=1);

use App\E2E\Support\E2ECommand;
use App\E2E\Support\E2EConfig;
use App\E2E\Support\E2EGatewayApi;
use App\E2E\Support\E2ETopologyHarness;
use App\E2E\Support\E2ETopologyKind;

/**
 * @return array<string, mixed>
 */
function preparedIngressProductionRoute(E2ETopologyHarness $topology, string $domain): array
{
    $domainValue = var_export($domain, true);

    $route = E2ECommand::gatewayArtisan(
        $topology->instance('gateway'),
        'tinker --execute='.escapeshellarg(<<<PHP
            echo json_encode(app(\\App\\Services\\Proxy\\ProxyRouteQuery::class)
                ->toRouteEntity(\\App\\Models\\ProxyRoute::query()->where('domain', {$domainValue})->firstOrFail()), JSON_THROW_ON_ERROR);
            PHP),
        'Could not read prepared app production ingress state',
        timeoutSeconds: 120,
    );

    return json_decode(trim($route->output()), associative: true, flags: JSON_THROW_ON_ERROR);
}

function prepareIngressProductionRuntime(E2ETopologyHarness $topology): void
{
    $commonRuntime = <<<'SH'
        set -e
        sudo install -d -m 0755 /etc/caddy/sites /usr/local/bin
        printf '%s\n' '#!/usr/bin/env sh' 'exit 0' | sudo tee /usr/local/bin/systemctl >/dev/null
        sudo chmod 0755 /usr/local/bin/systemctl
        SH;

    $topology->ssh('ingress', $commonRuntime, timeoutSeconds: 60);

    $appRuntime = <<<'SH'
        sudo install -d -m 0755 /usr/sbin
        printf '%s\n' '#!/usr/bin/env sh' 'exit 0' | sudo tee /usr/sbin/php-fpm8.5 >/dev/null
        sudo chmod 0755 /usr/sbin/php-fpm8.5
        SH;

    $topology->ssh('prod', $commonRuntime.PHP_EOL.$appRuntime, timeoutSeconds: 60);
}

it('serves a production app through a prepared ingress topology', function (): void {
    $config = E2EConfig::fromEnvironment();
    $topology = e2eTopology(E2ETopologyKind::OperatorGatewayAppprodIngress, withGatewayApi: true);
    $suffix = strtolower(bin2hex(random_bytes(3)));
    $name = "ingress-{$suffix}";
    $domain = "docs-{$suffix}.example.test";
    $path = "/home/orbit/apps/{$name}";

    try {
        expect($topology->lease()->devApp())
            ->toBeNull()
            ->and($topology->lease()->agent())
            ->toBeNull()
            ->and($topology->lease()->ingress())
            ->not
            ->toBeNull()
            ->and(collect($topology->instanceNames())
                ->contains(
                    fn (string $instanceName): bool => (
                        str_contains($instanceName, '-dev') || str_contains($instanceName, '-agent')
                    ),
                ))
            ->toBeFalse();

        $colocatedIngress = $topology->lease()->ingress()?->name() === $topology->lease()->prodApp()?->name();

        $topology->withCurrentCheckout(roles: ['operator', 'gateway', 'prod', 'ingress']);
        $gatewayApiIp = $topology->lease()->gatewayApiIp();

        e2eRestartGatewayApi($topology, 'ingress-production');
        E2EGatewayApi::waitForGatewayApi(
            $topology->instance('operator'),
            $config->operatorUser,
            $topology->lease()->sshKeyPair(),
            gatewayIp: $gatewayApiIp,
        );

        e2eGrantNodeAccess($topology, serving: 'app-prod-1');
        prepareIngressProductionRuntime($topology);

        $topology->ssh(
            'prod',
            sprintf('sudo install -d -o orbit -g orbit %s', escapeshellarg($path)),
            timeoutSeconds: 60,
        );

        $result = $topology->ssh(
            'operator',
            sprintf(
                'cd %s && orbit instance:register %s --node=app-prod-1 --path=%s --domain=%s --root=public --php-version=8.5 --json',
                escapeshellarg($topology->checkout('operator')),
                escapeshellarg($name),
                escapeshellarg($path),
                escapeshellarg($domain),
            ),
            timeoutSeconds: 240,
        );

        $payload = json_decode(trim($result->output()), associative: true, flags: JSON_THROW_ON_ERROR);
        $data = e2eJsonCommandResultData($payload);
        $app = $data['app'] ?? null;
        $route = preparedIngressProductionRoute($topology, $domain);
        $backendPort = $colocatedIngress ? 8081 : 80;

        expect($app)
            ->toBeArray()
            ->and($app['name'])
            ->toBe($name)
            ->and($app['node'])
            ->toBe('app-prod-1')
            ->and($app['url'])
            ->toBe("https://{$domain}")
            ->and($route['node'])
            ->toBe($colocatedIngress ? 'app-prod-1' : 'edge-1')
            ->and($route['placement'])
            ->toBe('ingress')
            ->and($route['router'])
            ->toMatchArray([
                'node' => 'gateway',
                'url' => 'http://10.6.0.2:80',
                'backend_pool' => [
                    ['node' => 'app-prod-1', 'url' => "http://10.6.0.5:{$backendPort}"],
                ],
            ]);
    } finally {
        $topology->ssh(
            'prod',
            'sudo rm -rf '.escapeshellarg($path),
            timeoutSeconds: 60,
            allowFailure: true,
        );
        $topology->cleanup();
    }
})->group('e2e-feature', 'e2e-feature-operator_gateway_app-prod_ingress');
