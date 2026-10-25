<?php

declare(strict_types=1);

use App\Domain\Shared\ResourceOperationException;
use App\Domain\WireGuard\GatewayPeerProjectionManager;
use App\Infrastructure\Files\ProtectedFileWriter;
use App\Infrastructure\WireGuard\NativeGatewayPeerProjectionManager;
use App\Infrastructure\WireGuard\VpnConfigurationRepository;
use App\Infrastructure\WireGuard\WireGuardServerConfigRenderer;
use App\Models\Node;
use Illuminate\Contracts\Http\Kernel as HttpKernel;
use Illuminate\Http\Request;
use Tests\Support\FakeAgentCommandRunner;
use Tests\Support\ForkedGatewayProcess;

$configuration = app(VpnConfigurationRepository::class);

if ($mode === 'assign') {
    // Initialize SQLite before the barrier so connection PRAGMAs do not race a peer's write.
    $node = Node::query()->findOrFail($nodeId);
    ForkedGatewayProcess::write($socket, 'ready');
    if (ForkedGatewayProcess::read($socket) !== 'assign') {
        throw new RuntimeException('The key assignment barrier was not released.');
    }

    try {
        $configuration->assignPeerKey($node, $publicKey);
        echo 'assigned';
        exit(0);
    } catch (ResourceOperationException $exception) {
        echo $exception->errorCode;
        exit(1);
    }
}

$agents = new FakeAgentCommandRunner()->answer('wireguard.project-hub', static function (array $input) use ($home): array {
    file_put_contents($home.'/live.conf', $input['config']);

    return [];
});
$native = new NativeGatewayPeerProjectionManager(
    $configuration, new WireGuardServerConfigRenderer, new ProtectedFileWriter, $agents, $home,
);
$app->instance(GatewayPeerProjectionManager::class, new class($native, $mode) implements GatewayPeerProjectionManager
{
    public function __construct(private readonly GatewayPeerProjectionManager $native, private readonly string $mode) {}

    public function converge(Node $node): void
    {
        if ($this->mode === 'before-publication') {
            exit(86);
        }

        $this->native->converge($node);

        if ($this->mode === 'after-publication') {
            exit(87);
        }
    }

    public function remove(Node $node): void
    {
        $this->native->remove($node);
    }

    public function restore(Node $node): void
    {
        $this->native->restore($node);
    }
});
$request = Request::create('/api/v1/nodes/'.$nodeId.'/wireguard-key', 'PATCH', server: [
    'REMOTE_ADDR' => '10.44.0.1', 'CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json',
], content: json_encode(['wireguard_public_key' => $publicKey], JSON_THROW_ON_ERROR));
$response = $app->make(HttpKernel::class)->handle($request);
echo $response->getContent();
exit($response->getStatusCode() === 200 ? 0 : 1);
