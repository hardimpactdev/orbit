<?php

declare(strict_types=1);

use App\Domain\Shared\ResourceOperationException;
use App\Domain\WireGuard\GatewayPeerProjectionManager;
use App\Infrastructure\Files\ProtectedFileWriter;
use App\Infrastructure\Processes\CommandResult;
use App\Infrastructure\Processes\ProcessInvocation;
use App\Infrastructure\Processes\ProcessRunner;
use App\Infrastructure\WireGuard\NativeGatewayPeerProjectionManager;
use App\Infrastructure\WireGuard\VpnConfigurationRepository;
use App\Infrastructure\WireGuard\WireGuardServerConfigRenderer;
use App\Models\Node;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Contracts\Http\Kernel as HttpKernel;
use Illuminate\Http\Request;
use Tests\Support\TestDatabaseGuard;

require dirname(__DIR__, 3).'/vendor/autoload.php';

$home = $argv[1];
$mode = $argv[2];
$nodeId = (int) $argv[3];
$publicKey = $argv[4];
$app = require dirname(__DIR__, 3).'/bootstrap/app.php';
$app->loadEnvironmentFrom('.env.example');
TestDatabaseGuard::register($app);
$app->make(Kernel::class)->bootstrap();
config()->set('orbit.home', $home);
config()->set('broadcasting.default', 'null');
$configuration = app(VpnConfigurationRepository::class);

if ($mode === 'assign') {
    // Initialize SQLite before the barrier so connection PRAGMAs do not race a peer's write.
    $node = Node::query()->findOrFail($nodeId);
    file_put_contents($home.'/ready-'.$nodeId, 'ready');
    $deadline = microtime(true) + 10;

    while (! is_file($home.'/assign-go')) {
        if (microtime(true) > $deadline) {
            exit(90);
        }

        usleep(1_000);
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

$processes = new class($home) implements ProcessRunner
{
    public function __construct(private readonly string $home) {}

    public function run(ProcessInvocation $invocation): CommandResult
    {
        if ($invocation->arguments === ['sudo', 'mv', '-f', '--', '/etc/wireguard/orbit-candidate.conf', '/etc/wireguard/orbit.conf']) {
            copy($this->home.'/generated/wireguard/orbit.conf', $this->home.'/live.conf');
        }

        return new CommandResult(0, '', '', 1, false);
    }
};
$native = new NativeGatewayPeerProjectionManager(
    $configuration, new WireGuardServerConfigRenderer, new ProtectedFileWriter, $processes, $home,
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
