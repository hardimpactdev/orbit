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

    $topology->ssh(
        'gateway',
        sprintf(
            'cat > /tmp/orbit-fake-opencode.php <<%s%s%s',
            escapeshellarg('PHP'),
            "\n{$router}\n",
            'PHP',
        ),
        timeoutSeconds: 60,
    );

    $topology->ssh(
        'gateway',
        sprintf(
            'rm -f /tmp/orbit-agent-ide-message-request.json /tmp/orbit-fake-opencode.log; '.
            'nohup php -S 127.0.0.1:%d /tmp/orbit-fake-opencode.php > /tmp/orbit-fake-opencode.log 2>&1 & echo $! > /tmp/orbit-fake-opencode.pid',
            $port,
        ),
        timeoutSeconds: 60,
    );

    $topology->ssh(
        'gateway',
        sprintf(
            'for i in $(seq 1 30); do curl -fsS -X POST http://127.0.0.1:%d/session/health/prompt_async -d "{}" >/dev/null 2>&1 && exit 0; sleep 1; done; cat /tmp/orbit-fake-opencode.log; exit 1',
            $port,
        ),
        timeoutSeconds: 45,
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
        'environment' => 'development',
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
        'path' => '/srv/docs/workspaces/feature-docs',
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
        'cd '.escapeshellarg($topology->checkout('gateway')).' && php artisan tinker --execute='.escapeshellarg($script),
        timeoutSeconds: 120,
    );
}

it('sends a workspace message through the managed OpenCode transport', function (): void {
    $topology = e2eTopology(E2ETopologyKind::ControlGatewayDev)
        ->withCurrentCheckout(roles: ['gateway']);
    $port = random_int(48100, 48999);

    try {
        agentIdeMessageStartFakeOpenCode($topology, $port);
        agentIdeMessageSeedGatewayIntent($topology, $port);

        $result = $topology->ssh(
            'gateway',
            sprintf(
                'cd %s && php artisan agent-ide:message %s --workspace=feature-docs --json',
                escapeshellarg($topology->checkout('gateway')),
                escapeshellarg('Ship the docs'),
            ),
            timeoutSeconds: 120,
        );
        $payload = json_decode(trim($result->output()), associative: true, flags: JSON_THROW_ON_ERROR);

        $request = $topology->ssh(
            'gateway',
            'cat /tmp/orbit-agent-ide-message-request.json',
            timeoutSeconds: 60,
        );
        $requestPayload = json_decode(trim($request->output()), associative: true, flags: JSON_THROW_ON_ERROR);

        expect($payload['success']['data']['agent_ide']['adapter'])->toBe('opencode')
            ->and($payload['success']['data']['agent_ide']['target'])->toMatchArray([
                'app' => 'docs',
                'workspace' => 'feature-docs',
                'node' => 'app-dev-1',
            ])
            ->and($payload['success']['data']['agent_ide']['session']['id'])->toBe('sess_e2e')
            ->and($payload['success']['data']['agent_ide']['delivery'])->toMatchArray([
                'status' => 'sent',
                'message_bytes' => 13,
                'input' => 'argument',
            ])
            ->and($requestPayload)->toMatchArray([
                'providerID' => null,
                'modelID' => null,
                'text' => 'Ship the docs',
                'directory' => '/srv/docs/workspaces/feature-docs',
            ]);
    } finally {
        $topology->ssh(
            'gateway',
            'test ! -f /tmp/orbit-fake-opencode.pid || kill "$(cat /tmp/orbit-fake-opencode.pid)" >/dev/null 2>&1 || true',
            timeoutSeconds: 30,
        );
        $topology->cleanup();
    }
})->group('e2e-feature', 'e2e-feature-control-gateway-dev');
