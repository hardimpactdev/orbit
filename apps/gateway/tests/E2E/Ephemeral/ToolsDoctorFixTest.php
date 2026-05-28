<?php

declare(strict_types=1);

use App\E2E\Support\E2ETopologyHarness;
use App\E2E\Support\E2ETopologyKind;

pest()->group('e2e-feature', 'e2e-feature-operator_gateway_app-dev', 'e2e-feature-operator-gateway-dev');

it('repairs managed tool configuration drift from gateway intent', function (): void {
    $topology = e2eTopology(E2ETopologyKind::OperatorGatewayAppdev)
        ->withCurrentCheckout(roles: ['gateway']);
    $configPath = '/tmp/orbit-e2e-redis.conf';
    $configContent = "port 6379\nbind 127.0.0.1\n";

    try {
        toolsDoctorFixPrepareDevNode($topology, $configPath);
        toolsDoctorFixSeedGatewayIntent($topology, $configPath, $configContent);

        $result = $topology->ssh(
            'gateway',
            sprintf(
                'cd %s && orbit doctor --node=app-dev-1 --family=tool --restore --json',
                escapeshellarg($topology->checkout('gateway')),
            ),
            timeoutSeconds: 180,
        );
        $payload = json_decode(trim($result->output()), associative: true, flags: JSON_THROW_ON_ERROR);

        expect($result->successful())->toBeTrue()
            ->and($payload['success']['data']['doctor']['healthy'])->toBeTrue()
            ->and($payload['success']['data']['doctor']['summary']['fixed'])->toBe(1)
            ->and($payload['success']['data']['doctor']['actions'][0])->toMatchArray([
                'family' => 'tool',
                'node' => 'app-dev-1',
                'key' => 'tool.config_missing',
                'mode' => 'restore',
                'status' => 'completed',
            ]);

        $hash = $topology->ssh('dev', 'sha256sum '.escapeshellarg($configPath)." | awk '{print $1}'");

        expect(trim($hash->output()))->toBe(hash('sha256', $configContent));
    } finally {
        $topology->ssh('dev', 'sudo rm -f '.escapeshellarg($configPath).' /usr/local/bin/redis-server', timeoutSeconds: 60);
        $topology->cleanup();
    }
});

function toolsDoctorFixPrepareDevNode(E2ETopologyHarness $topology, string $configPath): void
{
    $topology->ssh(
        'dev',
        sprintf(
            'printf %s | sudo tee /usr/local/bin/redis-server >/dev/null && sudo chmod 0755 /usr/local/bin/redis-server && sudo rm -f %s',
            escapeshellarg("#!/usr/bin/env bash\necho 'Redis server v=7.2.0'\n"),
            escapeshellarg($configPath),
        ),
        timeoutSeconds: 60,
    );
}

function toolsDoctorFixSeedGatewayIntent(E2ETopologyHarness $topology, string $configPath, string $configContent): void
{
    $configPathValue = var_export($configPath, true);
    $configContentValue = var_export($configContent, true);
    $configHashValue = var_export(hash('sha256', $configContent), true);

    $php = <<<PHP
\$node = \\App\\Models\\Node::query()->where('name', 'app-dev-1')->firstOrFail();

\\App\\Models\\NodeTool::query()->updateOrCreate(
    ['node_id' => \$node->id, 'name' => 'redis'],
    [
        'expected_state' => 'installed',
        'expected_version' => null,
        'config' => [
            'managed_config' => [
                'path' => {$configPathValue},
                'hash' => {$configHashValue},
                'content' => {$configContentValue},
            ],
        ],
        'credentials' => null,
    ],
);

echo 'seeded';
PHP;

    $topology->ssh(
        'gateway',
        'cd '.escapeshellarg($topology->checkout('gateway')).' && php apps/gateway/artisan tinker --execute='.escapeshellarg($php),
        timeoutSeconds: 120,
    );
}
