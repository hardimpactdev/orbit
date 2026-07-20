<?php

declare(strict_types=1);

use App\E2E\Support\E2ETopologyHarness;
use App\E2E\Support\E2ETopologyKind;

function deployCommandSeedProductionApp(E2ETopologyHarness $topology, string $path): void
{
    $script = <<<'PHP'
        $node = \App\Models\Node::query()->where('name', 'app-dev-1')->firstOrFail();

        \App\Models\Project::query()->updateOrCreate(
            ['name' => 'docs'],
            [
                'node_id' => $node->id,
                'domain' => null,
                'path' => '__PATH__',
                'document_root' => 'public',
                'repository' => null,
                'environment' => 'production',
                'php_version' => '8.5',
                'runtime' => \App\Enums\Apps\AppRuntimeKind::Static->value,
                'adopted' => true,
            ],
        );

        echo 'seeded';
        PHP;

    $script = str_replace('__PATH__', $path, $script);

    $topology->ssh(
        'gateway',
        'cd '.escapeshellarg($topology->checkout('gateway')).' && php apps/gateway/artisan tinker --execute='
            .escapeshellarg($script),
        timeoutSeconds: 120,
    );
}

it('manages and runs a production app deployment pipeline', function (): void {
    $topology = e2eTopology(E2ETopologyKind::OperatorGatewayAppdev)
        ->withCurrentCheckout(roles: ['gateway', 'dev']);
    $path = '/tmp/orbit-deploy-e2e-'.strtolower(bin2hex(random_bytes(3)));

    try {
        e2eRestartGatewayApi($topology, 'deploy-command');
        $topology->ssh('dev', 'mkdir -p '.escapeshellarg($path), timeoutSeconds: 60);
        deployCommandSeedProductionApp($topology, $path);

        $checkout = escapeshellarg($topology->checkout('gateway'));
        $command = 'printf deployed > deploy-marker.txt && cat deploy-marker.txt';

        $add = $topology->ssh(
            'gateway',
            sprintf(
                'cd %s && orbit deploy:step-add docs %s --title=%s --timeout=120 --json',
                $checkout,
                escapeshellarg($command),
                escapeshellarg('Write marker'),
            ),
            timeoutSeconds: 120,
        );
        $addPayload = json_decode(trim($add->output()), associative: true, flags: JSON_THROW_ON_ERROR);

        $list = $topology->ssh(
            'gateway',
            "cd {$checkout} && orbit deploy:step-list docs --json",
            timeoutSeconds: 120,
        );
        $listPayload = json_decode(trim($list->output()), associative: true, flags: JSON_THROW_ON_ERROR);

        $run = $topology->ssh(
            'gateway',
            "cd {$checkout} && orbit deploy:run docs --json",
            timeoutSeconds: 180,
        );
        $runPayload = json_decode(trim($run->output()), associative: true, flags: JSON_THROW_ON_ERROR);
        $runData = e2eJsonCommandResultData($runPayload);
        $runId = $runData['run']['id'];
        $runStepId = $runData['run']['steps'][0]['id'];

        $history = $topology->ssh(
            'gateway',
            "cd {$checkout} && orbit deploy:history docs --limit=10 --json",
            timeoutSeconds: 120,
        );
        $historyPayload = json_decode(trim($history->output()), associative: true, flags: JSON_THROW_ON_ERROR);

        $log = $topology->ssh(
            'gateway',
            sprintf(
                'cd %s && orbit deploy:log docs %d --step=%d --lines=20 --json',
                $checkout,
                $runId,
                $runStepId,
            ),
            timeoutSeconds: 120,
        );
        $logPayload = json_decode(trim($log->output()), associative: true, flags: JSON_THROW_ON_ERROR);

        $remove = $topology->ssh(
            'gateway',
            sprintf(
                'cd %s && orbit deploy:step-remove docs %d --force --json',
                $checkout,
                $addPayload['success']['data']['step']['id'],
            ),
            timeoutSeconds: 120,
        );
        $removePayload = json_decode(trim($remove->output()), associative: true, flags: JSON_THROW_ON_ERROR);

        expect($addPayload['success']['data']['step'])
            ->toMatchArray([
                'project' => 'docs',
                'title' => 'Write marker',
                'order' => 1,
                'timeout_seconds' => 120,
            ])
            ->and($listPayload['success']['data']['steps'])
            ->toHaveCount(1)
            ->and($runData['run']['status'])
            ->toBe('completed')
            ->and($runData['output']['stdout'])
            ->toBe('deployed')
            ->and($historyPayload['success']['data']['runs'][0]['id'])
            ->toBe($runId)
            ->and($logPayload['success']['data']['steps'][0]['output']['stdout'])
            ->toBe('deployed')
            ->and($removePayload['success']['meta']['history_preserved'])
            ->toBeTrue();
    } finally {
        $topology->ssh('dev', 'sudo rm -rf '.escapeshellarg($path), timeoutSeconds: 60);
        $topology->cleanup();
    }
})->group('e2e-feature', 'e2e-feature-operator_gateway_app-dev', 'e2e-feature-operator-gateway-dev');
