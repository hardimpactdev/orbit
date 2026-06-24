<?php

declare(strict_types=1);

use App\E2E\Support\E2ETopologyHarness;
use App\E2E\Support\E2ETopologyKind;

function agentIdeMessageStartFakeOpenCode(E2ETopologyHarness $topology, int $port): void
{
    $router = <<<'PHP'
        <?php

        $body = file_get_contents('php://input');
        file_put_contents('/tmp/orbit-agent-ide-message-request.json', $body === false ? '' : $body);
        http_response_code(204);
        PHP;

    e2ePutRuntimeFile($topology, 'gateway', '/tmp/orbit-fake-opencode.php', $router, timeoutSeconds: 60);
    e2eStartRuntimePhpServer(
        $topology,
        'gateway',
        $port,
        '/tmp/orbit-fake-opencode.php',
        '/tmp/orbit-fake-opencode.log',
        '/tmp/orbit-fake-opencode.pid',
    );
    e2eWaitForRuntimeHttpEndpoint(
        $topology,
        'gateway',
        $port,
        '/session/health/prompt_async',
        '/tmp/orbit-fake-opencode.log',
    );
}

function agentIdeMessageSeedGatewayIntent(E2ETopologyHarness $topology, int $port): void
{
    $script = <<<'PHP'
        $node = \App\Models\Node::query()->where('name', 'app-dev-1')->firstOrFail();

        $app = \App\Models\App::query()->updateOrCreate(
            ['name' => 'docs'],
            [
                'node_id' => $node->id,
                'domain' => null,
                'path' => '/srv/docs',
                'document_root' => 'public',
                'repository' => null,
                'php_version' => '8.5',
                'adopted' => true,
                'agent_ide_config' => ['adapter' => 'opencode'],
            ],
        );

        \App\Models\Workspace::query()->updateOrCreate(
            ['app_id' => $app->id, 'name' => 'feature-docs'],
            [
                'path' => '/srv/docs/.worktrees/feature-docs',
                'php_version' => null,
                'agent_ide' => 'opencode',
                'agent_ide_workspace_id' => 'sess_e2e',
                'lifecycle_status' => \App\Enums\WorkspaceLifecycleStatus::Expected,
            ],
        );

        \App\Models\NodeTool::query()->updateOrCreate(
            ['node_id' => $node->id, 'name' => 'opencode-server'],
            [
                'expected_state' => 'running',
                'expected_version' => null,
                'config' => null,
                'credentials' => [
                    'fields' => [
                        'url' => 'http://127.0.0.1:__PORT__',
                    ],
                ],
            ],
        );

        echo 'seeded';
        PHP;

    $script = str_replace('__PORT__', (string) $port, $script);

    $topology->ssh(
        'gateway',
        'cd '.escapeshellarg($topology->checkout('gateway')).' && php apps/gateway/artisan tinker --execute='
            .escapeshellarg($script),
        timeoutSeconds: 120,
    );
}

it('sends a workspace message through the managed OpenCode transport', function (): void {
    $topology = e2eTopology(E2ETopologyKind::OperatorGatewayAppdev)
        ->withCurrentCheckout(roles: ['gateway']);
    $port = random_int(48100, 48999);

    try {
        e2eRestartGatewayApi($topology, 'agent-ide-message');
        agentIdeMessageStartFakeOpenCode($topology, $port);
        agentIdeMessageSeedGatewayIntent($topology, $port);

        $result = $topology->ssh(
            'gateway',
            sprintf(
                'cd %s && orbit agent-ide:message %s --workspace=feature-docs --json',
                escapeshellarg($topology->checkout('gateway')),
                escapeshellarg('Ship the docs'),
            ),
            timeoutSeconds: 120,
        );
        $payload = json_decode(trim($result->output()), associative: true, flags: JSON_THROW_ON_ERROR);

        $request = e2eRunInRoleRuntime(
            $topology,
            'gateway',
            'cat /tmp/orbit-agent-ide-message-request.json',
            timeoutSeconds: 60,
        );
        $requestPayload = json_decode(trim($request->output()), associative: true, flags: JSON_THROW_ON_ERROR);

        expect($payload['success']['data']['agent_ide']['adapter'])
            ->toBe('opencode')
            ->and($payload['success']['data']['agent_ide']['target'])
            ->toMatchArray([
                'app' => 'docs',
                'workspace' => 'feature-docs',
                'node' => 'app-dev-1',
            ])
            ->and($payload['success']['data']['agent_ide']['session']['id'])
            ->toBe('sess_e2e')
            ->and($payload['success']['data']['agent_ide']['delivery'])
            ->toMatchArray([
                'status' => 'sent',
                'message_bytes' => 13,
                'input' => 'argument',
            ])
            ->and($requestPayload)
            ->toMatchArray([
                'providerID' => null,
                'modelID' => null,
                'text' => 'Ship the docs',
                'directory' => '/srv/docs/.worktrees/feature-docs',
            ]);
    } finally {
        e2eStopRuntimePhpServer($topology, 'gateway', '/tmp/orbit-fake-opencode.pid');
        $topology->cleanup();
    }
})->group('e2e-feature', 'e2e-feature-operator_gateway_app-dev', 'e2e-feature-operator-gateway-dev');
