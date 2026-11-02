<?php

// Disposable topology checks. Credentials stay in this Gateway process and never enter a receipt or argv.
use App\Infrastructure\AgentView\StreamWebSocketClient;
use App\Infrastructure\AgentView\WebSocketEndpoint;
use App\Models\Node;
use App\Models\Instance;
use App\Models\InstanceEnvironmentValue;
use App\Models\Project;
use App\Models\WebSocketApp;
use App\Models\WebSocketRuntime;
use Pusher\Pusher;
use Pusher\ApiErrorException;
use Symfony\Component\Process\Process;

chdir(dirname(__DIR__, 3).'/apps/gateway');
require 'vendor/autoload.php';
$app = require 'bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
umask(0077);

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

/** The HTTP status is safe to record; signed URLs, credentials and response bodies are not. */
function publishStatus(array $credentials, string $nonce): int
{
    $client = new Pusher($credentials['key'], $credentials['secret'], $credentials['app_id'], [
        'host' => '127.0.0.1', 'port' => (int) config('orbit.websocket.port'), 'scheme' => 'http', 'useTLS' => false, 'timeout' => 5,
    ]);
    try {
        $client->trigger('proof-orbit', 'proof-alive', ['nonce' => $nonce]);
        return 200;
    } catch (ApiErrorException $error) {
        return $error->getCode();
    } catch (Throwable) {
        verify(false, 'the real Reverb server answers the signed publish');
    }
}

function credentials(WebSocketApp $app): array
{
    return ['key' => $app->key, 'secret' => $app->secret, 'app_id' => $app->app_id, 'generation' => $app->credential_generation];
}

function publish(WebSocketApp $app, string $nonce): void
{
    verify(publishStatus(credentials($app), $nonce) === 200, 'the real Reverb server accepts the app publish signature');
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
if ($mode === 'seed-rotation') {
    $project = Project::query()->firstOrCreate(['slug' => 'proof-websocket-rotation'], ['name' => 'Proof WebSocket rotation',
        'repository_url' => 'https://example.test/proof-websocket.git', 'default_branch' => 'main', 'root' => 'public']);
    Instance::query()->firstOrNew(['id' => 7001])->forceFill(['id' => 7001, 'project_id' => $project->id, 'node_id' => (int) $argv[3], 'name' => 'default',
        'environment' => 'development', 'checkout_path' => '/home/orbit/apps/proof-websocket/default', 'source_is_laravel' => true,
        'status' => 'active', 'provisioning_step' => 'active'])->save();
    exit(0);
}
if ($mode === 'offline') {
    Instance::query()->findOrFail(7001)->node->update(['status' => 'failed']);
    exit(0);
}
if ($mode === 'observe') {
    $apps = ['gateway' => WebSocketApp::query()->where('name', WebSocketApp::GatewayName)->sole()];
    if (isset($argv[3])) {
        $apps['changed'] = WebSocketApp::query()->where('name', $argv[3])->sole();
    }
    $clients = [];
    foreach ($apps as $slot => $application) {
        $clients[$slot] = $client = new StreamWebSocketClient;
        $client->connect(new WebSocketEndpoint('10.231.0.1', 443, 'reverb.orbit', '/app/'.$application->key.'?protocol=7&client=orbit-proof&version=1', '/home/orbit/.orbit/ca/root.pem'), 10);
        $client->send(['event' => 'pusher:subscribe', 'data' => ['channel' => 'proof-orbit']]);
    }
    $state = ['pid' => processId(), 'boots' => WebSocketRuntime::query()->where('required', true)->pluck('boot_id', 'id')->all(),
        'received' => false, 'ready' => [], 'app_closed' => false, 'old' => isset($apps['changed']) ? credentials($apps['changed']) : null];
    $deadline = microtime(true) + 180;
    do {
        foreach ($clients as $slot => $client) {
            foreach ($client->receive(0.1) as $message) {
                if (($message['event'] ?? null) === 'pusher_internal:subscription_succeeded') {
                    $state['ready'][$slot] = true;
                }
                if ($slot === 'gateway' && ($message['event'] ?? null) === 'proof-alive') {
                    $data = $message['data'] ?? [];
                    $data = is_string($data) ? json_decode($data, true) : $data;
                    if (($data['nonce'] ?? null) === $nonce) {
                        $state['received'] = true;
                    }
                }
                if (($message['event'] ?? null) === 'pusher:ping') {
                    $client->send(['event' => 'pusher:pong', 'data' => new stdClass]);
                }
            }
            if ($slot === 'changed' && ! $client->isConnected()) {
                $state['app_closed'] = true;
            }
        }
        file_put_contents($path.'.pending', json_encode($state, JSON_THROW_ON_ERROR));
        rename($path.'.pending', $path);
    } while ($clients['gateway']->isConnected() && microtime(true) < $deadline);
    exit(0);
}
if ($mode === 'prepare') {
    $command = new Process(['systemd-run', '--quiet', '--unit='.$unit, '--property=User=orbit', '--property=WorkingDirectory='.getcwd(), '/usr/bin/php', __FILE__, 'observe', $nonce, ...array_slice($argv, 3)]);
    $command->mustRun();
    $deadline = microtime(true) + 15;
    do {
        $state = is_file($path) ? json_decode(file_get_contents($path), true) : null;
        $ready = ($state['ready']['gateway'] ?? false) && (! isset($argv[3]) || ($state['ready']['changed'] ?? false));
        if ($ready) {
            break;
        }
        usleep(100_000);
    } while (microtime(true) < $deadline);
    verify($ready, 'the real Gateway and affected app connections are subscribed before the request');
    echo "parity setup: Gateway connection is subscribed\n";
    exit(0);
}
verify($mode === 'check', 'known check mode');
try {
    $operation = json_decode(file_get_contents(getenv('PROOF_OPERATION_FILE')), true, flags: JSON_THROW_ON_ERROR);
    verify($operation['status'] === 'succeeded' && $operation['swamp_run_id'] === null, 'the Operation succeeds without a Swamp run');
    verify($operation['result'] === ['outcome' => 'succeeded', 'data' => ['backend' => 'applied']], 'the result comes from Reverb reports');
    verify((fileperms($path) & 0777) === 0600, 'private fixture credentials stay in a mode-0600 file');
    $saved = json_decode(file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);
    verify($saved['pid'] === processId(), 'the Reverb service did not restart');
    verify($saved['boots'] !== [] && $saved['boots'] === WebSocketRuntime::query()->where('required', true)->pluck('boot_id', 'id')->all(), 'current required Reverb boots stayed the same');
    foreach (WebSocketRuntime::query()->where('required', true)->get() as $runtime) {
        verify($runtime->state === 'serving' && $runtime->epoch === $operation['input']['epoch'] && $runtime->applied_revision >= $operation['input']['revision'], 'each required boot applied this registry revision');
    }
    $kind = $operation['kind'];
    $changed = WebSocketApp::query()->where('app_id', $operation['input']['app']['app_id'])->first();
    $old = $saved['old'];
    if ($kind === 'websocket.app_destroy') {
        verify($changed === null && $old !== null, 'the app and its stored credentials are gone');
        verify(in_array(publishStatus($old, $nonce), [401, 403, 404], true), 'Reverb refuses the deleted app credentials');
    } else {
        verify($changed instanceof WebSocketApp && $changed->key === $operation['input']['app']['key'], 'the app keeps its admitted public key');
        verify(! str_contains(json_encode($operation), $changed->secret), 'the app secret stays out of the public Operation');
        publish($changed, $nonce);
        if ($kind === 'websocket.app_rotate') {
            verify($old !== null && $changed->app_id === $old['app_id'] && $changed->key === $old['key'], 'rotation keeps the app id and public key');
            verify($changed->secret !== $old['secret'] && $changed->credential_generation === $old['generation'] + 1, 'rotation changes the secret and advances one generation');
            verify(in_array(publishStatus($old, $nonce), [401, 403], true), 'Reverb rejects the old publish secret');
            verify(! str_contains(json_encode($operation), $old['secret']), 'the old secret stays out of the public Operation');
            $instance = Instance::query()->findOrFail(7001);
            verify($instance->node->status->value === 'failed', 'the attached Instance Node is recorded offline');
            $power = new Process(['incus', 'list', 'target', '--format', 'csv', '-c', 's']);
            $power->mustRun();
            verify(trim($power->getOutput()) === 'STOPPED', 'the attached Instance Node is actually stopped');
            $stored = InstanceEnvironmentValue::query()->where('instance_id', $instance->id)->get()->mapWithKeys(fn ($row) => [$row->env_key => $row->env_value])->all();
            verify(($stored['REVERB_APP_SECRET'] ?? null) === $changed->secret && ($stored['REVERB_APP_KEY'] ?? null) === $changed->key, 'the attached stored environment has the rotated credentials');
            verify(($operation['input']['app']['instance_rollout']['instance_ids'] ?? null) === [7001], 'the result names the separate Instance env sync');
        }
    }
    publish(WebSocketApp::query()->where('name', WebSocketApp::GatewayName)->sole(), $nonce);
    $deadline = microtime(true) + 5;
    do {
        $observed = json_decode(file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);
        if ($observed['received'] && ($old === null || $observed['app_closed'])) {
            break;
        }
        usleep(100_000);
    } while (microtime(true) < $deadline);
    verify($observed['received'], 'the Gateway connection stayed open and received the event');
    if ($old !== null) {
        verify($observed['app_closed'], 'Reverb closed the changed app connection while retaining the Gateway connection');
    }
    echo "parity: registry change applied, credentials verified, no Swamp run or Reverb restart, Gateway connection retained\n";
} finally {
    (new Process(['systemctl', 'stop', $unit]))->run();
    @unlink($path);
    @unlink($path.'.pending');
}
