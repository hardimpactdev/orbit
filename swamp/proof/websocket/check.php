<?php

// Disposable topology checks. Credentials stay in this Gateway process and never enter a receipt or argv.
use App\Infrastructure\AgentView\StreamWebSocketClient;
use App\Infrastructure\AgentView\WebSocketEndpoint;
use App\Models\Node;
use App\Models\WebSocketApp;
use App\Models\WebSocketRuntime;
use Pusher\Pusher;
use Symfony\Component\Process\Process;

chdir(dirname(__DIR__, 3).'/apps/gateway');
require 'vendor/autoload.php';
$app = require 'bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

function verify(bool $condition, string $message): void
{
    if (! $condition) {
        fwrite(STDERR, 'parity: '.$message."\n");
        exit(1);
    }
}

function statePath(string $nonce): string
{
    verify(preg_match('/\A[a-f0-9-]{36}\z/', $nonce) === 1, 'valid proof nonce');

    return '/home/orbit/.orbit/swamp-proof/websocket-'.$nonce.'.json';
}

function publish(WebSocketApp $app, string $nonce): void
{
    $client = new Pusher($app->key, $app->secret, $app->app_id, [
        'host' => '127.0.0.1', 'port' => (int) config('orbit.websocket.port'), 'scheme' => 'http', 'useTLS' => false, 'timeout' => 5,
    ]);
    try {
        $client->trigger('proof-orbit', 'proof-alive', ['nonce' => $nonce]);
    } catch (Throwable) {
        verify(false, 'the real Reverb server accepts the app publish signature');
    }
}

function processId(): string
{
    $process = new Process(['systemctl', 'show', 'orbit-websocket', '--property=MainPID', '--value']);
    $process->mustRun();
    $pid = trim($process->getOutput());
    verify(ctype_digit($pid) && (int) $pid > 0, 'the Reverb service is running');

    return $pid;
}

[$script, $mode, $nonce] = $argv;
$path = statePath($nonce);
$unit = 'orbit-proof-websocket-'.$nonce;
if ($mode === 'observe') {
    $gateway = WebSocketApp::query()->where('name', WebSocketApp::GatewayName)->sole();
    $client = new StreamWebSocketClient;
    $client->connect(new WebSocketEndpoint('10.231.0.1', 443, 'reverb.orbit', '/app/'.$gateway->key.'?protocol=7&client=orbit-proof&version=1', '/home/orbit/.orbit/ca/root.pem'), 10);
    $client->send(['event' => 'pusher:subscribe', 'data' => ['channel' => 'proof-orbit']]);
    $state = ['pid' => processId(), 'boots' => WebSocketRuntime::query()->where('required', true)->pluck('boot_id', 'id')->all(), 'received' => false];
    $deadline = microtime(true) + 180;
    do {
        foreach ($client->receive(1) as $message) {
            if (($message['event'] ?? null) === 'pusher_internal:subscription_succeeded') {
                file_put_contents($path, json_encode($state, JSON_THROW_ON_ERROR), LOCK_EX);
            }
            if (($message['event'] ?? null) === 'proof-alive') {
                $data = $message['data'] ?? [];
                $data = is_string($data) ? json_decode($data, true) : $data;
                if (($data['nonce'] ?? null) === $nonce) {
                    $state['received'] = true;
                    file_put_contents($path, json_encode($state, JSON_THROW_ON_ERROR), LOCK_EX);
                }
            }
            if (($message['event'] ?? null) === 'pusher:ping') {
                $client->send(['event' => 'pusher:pong', 'data' => new stdClass]);
            }
        }
    } while ($client->isConnected() && microtime(true) < $deadline);
    exit(0);
}
if ($mode === 'prepare') {
    $command = new Process(['systemd-run', '--quiet', '--unit='.$unit, '--property=User=orbit', '--property=WorkingDirectory='.getcwd(), '/usr/bin/php', __FILE__, 'observe', $nonce]);
    $command->mustRun();
    $deadline = microtime(true) + 15;
    while (! is_file($path) && microtime(true) < $deadline) {
        usleep(100_000);
    }
    verify(is_file($path), 'a real Gateway-app connection is subscribed before the request');
    echo "parity setup: Gateway connection is subscribed\n";
    exit(0);
}
verify($mode === 'check', 'known check mode');
try {
    $operation = json_decode(file_get_contents(getenv('PROOF_OPERATION_FILE')), true, flags: JSON_THROW_ON_ERROR);
    verify($operation['status'] === 'succeeded' && $operation['swamp_run_id'] === null, 'the Operation succeeds without a Swamp run');
    verify($operation['result'] === ['outcome' => 'succeeded', 'data' => ['backend' => 'applied']], 'the result comes from Reverb reports');
    $saved = json_decode(file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);
    verify($saved['pid'] === processId(), 'the Reverb service did not restart');
    verify($saved['boots'] !== [] && $saved['boots'] === WebSocketRuntime::query()->where('required', true)->pluck('boot_id', 'id')->all(), 'current required Reverb boots stayed the same');
    foreach (WebSocketRuntime::query()->where('required', true)->get() as $runtime) {
        verify($runtime->state === 'serving' && $runtime->epoch === $operation['input']['epoch'] && $runtime->applied_revision >= $operation['input']['revision'], 'each required boot applied this registry revision');
    }
    $created = WebSocketApp::query()->where('app_id', $operation['input']['app']['app_id'])->sole();
    verify($created->key === $operation['input']['app']['key'], 'the app key is the admitted public key');
    verify(! str_contains(json_encode($operation), $created->secret), 'the app secret stays out of the public Operation');
    publish($created, $nonce);
    publish(WebSocketApp::query()->where('name', WebSocketApp::GatewayName)->sole(), $nonce);
    $deadline = microtime(true) + 5;
    do {
        $observed = json_decode(file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);
        if ($observed['received']) {
            break;
        }
        usleep(100_000);
    } while (microtime(true) < $deadline);
    verify($observed['received'], 'the Gateway connection stayed open and received the event');
    echo "parity: app served, all boots applied, no Swamp run or Reverb restart, existing Gateway connection received an event\n";
} finally {
    (new Process(['systemctl', 'stop', $unit]))->run();
    @unlink($path);
}
