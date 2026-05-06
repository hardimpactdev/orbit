<?php

declare(strict_types=1);

use App\E2E\Support\E2ETopologyCapabilities;
use App\E2E\Support\E2ETopologyFactory;
use App\E2E\Support\E2ETopologyHarness;
use App\E2E\Support\E2ETopologyKind;
use App\E2E\Support\E2ETopologyUnavailable;

it('stops a managed system service tool on an app node from the gateway', function (): void {
    try {
        $lease = E2ETopologyFactory::fromEnvironment()
            ->requireCapabilities(E2ETopologyCapabilities::vm())
            ->require(E2ETopologyKind::ControlGatewayDev);
    } catch (E2ETopologyUnavailable $exception) {
        test()->markTestSkipped($exception->getMessage());
    }

    $topology = new E2ETopologyHarness($lease)
        ->withCurrentCheckout(roles: ['gateway']);

    try {
        toolStopSeedGatewayIntent($topology);

        $result = $topology->ssh(
            'gateway',
            sprintf(
                'cd %s && php artisan tool:stop supervisor --node=app-dev-1 --json',
                escapeshellarg($topology->checkout('gateway')),
            ),
            timeoutSeconds: 180,
        );
        $payload = json_decode(trim($result->output()), associative: true, flags: JSON_THROW_ON_ERROR);

        expect($result->successful())->toBeTrue()
            ->and($payload['success']['data']['tool'])->toMatchArray([
                'name' => 'supervisor',
                'node' => 'app-dev-1',
                'expected_state' => 'installed',
            ]);

        $status = $topology->ssh('dev', 'systemctl is-active supervisor.service || true', timeoutSeconds: 60);

        expect(trim($status->output()))->toBe('inactive');
    } finally {
        $topology->cleanup();
    }
})->group('e2e-feature', 'e2e-provider-incus', 'e2e-feature-control-gateway-dev');

function toolStopSeedGatewayIntent(E2ETopologyHarness $topology): void
{
    $php = <<<'PHP'
$node = \App\Models\Node::query()->where('name', 'app-dev-1')->firstOrFail();

\App\Models\NodeTool::query()->updateOrCreate(
    ['node_id' => $node->id, 'name' => 'supervisor'],
    [
        'expected_state' => 'running',
        'expected_version' => null,
        'config' => null,
        'credentials' => null,
    ],
);

echo 'seeded';
PHP;

    $topology->ssh(
        'gateway',
        'cd '.escapeshellarg($topology->checkout('gateway')).' && php artisan tinker --execute='.escapeshellarg($php),
        timeoutSeconds: 120,
    );
}
