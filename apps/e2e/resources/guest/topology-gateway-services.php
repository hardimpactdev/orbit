#!/usr/bin/env php
<?php

declare(strict_types=1);

use App\Domain\Certificates\GatewayCertificateIssuer;
use App\Infrastructure\AgentCommands\AgentCommandRunner;
use App\Infrastructure\Caddy\Build\NodeCaddyfileRenderer;
use App\Infrastructure\Gateway\GatewayCheckoutAccessConverger;
use App\Infrastructure\Gateway\GatewayCommandNode;
use App\Infrastructure\Gateway\GatewayFpmConfigRenderer;
use App\Infrastructure\Gateway\GatewayWebDirectoryConverger;
use App\Infrastructure\Gateway\NativeGatewayCertificatePublisher;
use App\Infrastructure\Gateway\NativeGatewayFpmConverger;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Application;

/** Fixture setup only: service policy stays in the canonical Gateway components. */
function configureMountedTopologyGateway(Application $app): string
{
    $node = GatewayCommandNode::find();
    $checkout = config()->string('orbit.gateway_checkout');
    $home = config()->string('orbit.home');
    if ($node === null || $node->name !== 'gateway' || $node->wireguard_ip !== '10.44.0.1') {
        throw new RuntimeException('e2e.fixture_gateway_identity_invalid');
    }
    $agents = $app->make(AgentCommandRunner::class);
    new GatewayCheckoutAccessConverger($agents, $checkout)->converge($node);
    new GatewayWebDirectoryConverger($agents, config()->string('orbit.gateway_web'))->converge($node);
    $certificate = $app->make(GatewayCertificateIssuer::class)->issue('gateway.orbit', '10.44.0.1');
    new NativeGatewayCertificatePublisher($agents, $home)->publish($node, $certificate);
    $pool = $app->make(GatewayFpmConfigRenderer::class)->renderPool($checkout, $home);
    $app->make(NativeGatewayFpmConverger::class)->converge($node, $pool);
    $caddy = $app->make(NodeCaddyfileRenderer::class)->render($node);
    if (! $caddy->buildable()) {
        throw new RuntimeException('e2e.fixture_gateway_caddy_unbuildable');
    }
    return $caddy->content;
}

if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
    try {
        if ($argc !== 1) {
            throw new RuntimeException('e2e.fixture_gateway_arguments_invalid');
        }
        require '/home/orbit/orbit/apps/gateway/vendor/autoload.php';
        $app = require '/home/orbit/orbit/apps/gateway/bootstrap/app.php';
        $app->make(Kernel::class)->bootstrap();
        if (config('orbit.gateway_checkout') !== '/home/orbit/orbit/apps/gateway' || config('orbit.home') !== '/home/orbit/.orbit') {
            throw new RuntimeException('e2e.fixture_gateway_mount_required');
        }
        echo configureMountedTopologyGateway($app);
    } catch (Throwable $error) {
        $code = $error instanceof \App\Domain\Nodes\NodeProvisioningException ? $error->errorCode : 'e2e.fixture_gateway_services_failed';
        fwrite(STDERR, $code."\n");
        exit(1);
    }
}
