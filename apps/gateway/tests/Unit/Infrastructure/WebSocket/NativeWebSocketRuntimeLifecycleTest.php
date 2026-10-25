<?php

declare(strict_types=1);

use App\Domain\Nodes\ManagedUserAccount;
use App\Domain\Nodes\ManagedUserAccountResolver;
use App\Domain\Nodes\NodeRoleOperationException;
use App\Domain\Nodes\RoleName;
use App\Domain\Shared\LifecycleStatus;
use App\Domain\WebSocket\WebSocketServerCredentials;
use App\Infrastructure\Nodes\AgentCaddyPackage;
use App\Infrastructure\Nodes\Roles\RolePrerequisites;
use App\Infrastructure\WebSocket\NativeWebSocketRuntimeLifecycle;
use App\Models\Node;
use App\Models\NodeRole;
use Illuminate\Support\Env;
use Tests\Support\FakeAgentCommandRunner;
use Tests\TestCase;

uses(TestCase::class);

function websocket_runtime_node(): Node
{
    $node = new Node(['name' => 'websocket', 'public_ssh_port' => 22, 'user' => 'orbit', 'wireguard_ip' => '10.44.0.9']);
    $node->exists = true;
    $node->id = 1;

    return $node;
}

function websocket_runtime_credentials(): WebSocketServerCredentials
{
    return new WebSocketServerCredentials('base64:'.base64_encode('k'), 'test-process', 'test-registry-token', 'test-notification-token');
}

function websocket_runtime_lifecycle(FakeAgentCommandRunner $agents, string $ref = 'release-42'): NativeWebSocketRuntimeLifecycle
{
    return new NativeWebSocketRuntimeLifecycle(
        prerequisites: new RolePrerequisites($agents, new AgentCaddyPackage($agents)),
        agents: $agents,
        accounts: new class implements ManagedUserAccountResolver
        {
            public function resolve(Node $node): ManagedUserAccount
            {
                return new ManagedUserAccount('orbit-websocket', 'orbit-websocket', '/home/orbit-websocket');
            }
        },
        repository: 'https://example.test/orbit-reverb.git',
        ref: $ref,
        installPath: '/opt/orbit/websocket',
        port: 8790,
    );
}

function websocket_runtime_agents(): FakeAgentCommandRunner
{
    return (new FakeAgentCommandRunner)
        ->answer('caddy.install-package', ['changed' => false, 'version' => '2.10.2'])
        ->answer('roles.install-prerequisites', [])
        ->answer('websocket.converge-runtime', ['release' => '/opt/orbit/websocket.releases/release.1'])
        ->answer('websocket.remove-runtime', [])
        ->answer('websocket.runtime-active', ['active' => true]);
}

it('installs prerequisites, then converges the pinned Reverb release with its rendered environment and unit', function (): void {
    $agents = websocket_runtime_agents();

    websocket_runtime_lifecycle($agents)->converge(websocket_runtime_node(), new NodeRole(['role' => RoleName::WebSocket, 'status' => LifecycleStatus::Provisioning]), websocket_runtime_credentials());

    $input = $agents->calls[2]['input'];
    expect($agents->commands())->toBe(['caddy.install-package', 'roles.install-prerequisites', 'websocket.converge-runtime'])
        ->and($agents->calls[1]['input']['packages'])->toContain('caddy', 'composer', 'git', 'php-curl', 'php-xml')
        ->and(array_diff_key($input, ['environment' => true, 'unit' => true]))->toBe([
            'user' => 'orbit-websocket', 'group' => 'orbit-websocket', 'install_path' => '/opt/orbit/websocket',
            'repository' => 'https://example.test/orbit-reverb.git', 'ref' => 'release-42', 'port' => 8790,
        ])
        ->and($input['environment'])->toContain('REVERB_SERVER_PORT=8790')
        ->and($input['unit'])->toContain('/opt/orbit/websocket', 'User=orbit-websocket');
});

it('installs the server commit pinned by the Gateway release despite a former environment override', function (): void {
    $environment = Env::getRepository();
    $previous = $environment->get('ORBIT_WEBSOCKET_REF');
    $environment->set('ORBIT_WEBSOCKET_REF', 'main');
    try {
        $release = require __DIR__.'/../../../../config/orbit.php';
        $ref = $release['websocket']['ref'];
        expect($ref)->toMatch('/\A[0-9a-f]{40}\z/');
        config()->set('orbit.websocket.ref', $ref);
        $agents = websocket_runtime_agents();
        websocket_runtime_lifecycle($agents, ref: '')->converge(websocket_runtime_node(), new NodeRole(['role' => RoleName::WebSocket]), websocket_runtime_credentials());
        expect($agents->calls[2]['input']['ref'])->toBe($ref);
    } finally {
        if ($previous === null) {
            $environment->clear('ORBIT_WEBSOCKET_REF');
        } else {
            $environment->set('ORBIT_WEBSOCKET_REF', $previous);
        }
    }
});

it('throws a NodeRoleOperationException when convergence fails', function (): void {
    $agents = websocket_runtime_agents()->refuse('websocket.converge-runtime', 'websocket.runtime_not_listening', ['port' => 8790]);

    expect(fn () => websocket_runtime_lifecycle($agents)->converge(websocket_runtime_node(), new NodeRole(['role' => RoleName::WebSocket]), websocket_runtime_credentials()))
        ->toThrow(fn (NodeRoleOperationException $exception) => expect($exception->underlyingErrorCode)->toBe('websocket.runtime_convergence_failed'));
});

it('removes the runtime, purging its releases only when asked', function (bool $purge): void {
    $agents = websocket_runtime_agents();

    websocket_runtime_lifecycle($agents)->remove(websocket_runtime_node(), new NodeRole, $purge);

    expect($agents->calls[0])->toBe(['node' => 'websocket', 'command' => 'websocket.remove-runtime', 'input' => ['install_path' => '/opt/orbit/websocket', 'purge' => $purge]]);
})->with([true, false]);

it('reports the service health from the agent', function (): void {
    expect(websocket_runtime_lifecycle(websocket_runtime_agents())->health(websocket_runtime_node()))->toBeTrue()
        ->and(websocket_runtime_lifecycle(websocket_runtime_agents()->answer('websocket.runtime-active', ['active' => false]))->health(websocket_runtime_node()))->toBeFalse();
});
