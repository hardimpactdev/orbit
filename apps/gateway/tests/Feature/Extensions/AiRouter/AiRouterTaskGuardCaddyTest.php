<?php

declare(strict_types=1);

use App\Domain\TaskVms\TaskVmHost;
use App\Domain\TaskVms\TaskVmSettings;
use App\Models\Node;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;
use Orbit\Extensions\AiRouter\Domain\AiRouterState;
use Orbit\Extensions\AiRouter\Domain\AiRouterTaskGuard;

uses(LazilyRefreshDatabase::class);

/** A free loopback TCP port. */
function guardFreePort(): int
{
    $socket = stream_socket_server('tcp://127.0.0.1:0');
    $port = (int) substr((string) stream_socket_get_name($socket, false), strrpos((string) stream_socket_get_name($socket, false), ':') + 1);
    fclose($socket);

    return $port;
}

/**
 * Runs the Caddyfile the Gateway renders on a real Caddy, moved onto loopback: loopback stands in for the task VM range, the key files
 * for the systemd credentials, and a second Caddy site for CLIProxyAPI, which echoes what it receives.
 */
it('passes only a POST of the task key from the range to the three inference routes, with the anchor key in its place', function (): void {
    if (! is_executable(trim((string) shell_exec('command -v caddy')))) {
        $this->markTestSkipped('Caddy is not installed.');
    }
    $node = Node::withoutEvents(fn (): Node => Node::query()->create(['name' => 'services', 'status' => 'active', 'platform' => 'linux',
        'architecture' => 'x86_64', 'public_ssh_host' => '10.44.0.3', 'wireguard_ip' => '10.44.0.3', 'user' => 'orbit']));
    $upstream = guardFreePort();
    $guard = guardFreePort();
    app()->instance(TaskVmSettings::class, new TaskVmSettings(true, null, '10.44.0.128/25',
        [new TaskVmHost(9, 'orbit-tasks', 'orbittask0', '10.252.7.0/24', 6, 4, '8GiB', '20GiB')],
        '/pi', str_repeat('a', 64), ['gpt-6']));
    app(AiRouterState::class)->enable($node->id, 'cache', "http://10.44.0.3:{$upstream}", 'management', 'read', 'control');
    $directory = sys_get_temp_dir().'/orbit-guard-'.bin2hex(random_bytes(4));
    mkdir($directory);
    file_put_contents("{$directory}/task.key", 'task-key');
    file_put_contents("{$directory}/anchor.key", 'anchor-key');
    $caddyfile = strtr(app(AiRouterTaskGuard::class)->caddyfile($node), [
        'bind 10.44.0.3' => 'bind 127.0.0.1', 'remote_ip 10.44.0.128/25' => 'remote_ip 127.0.0.1/32',
        AiRouterTaskGuard::Credentials => $directory, 'http://:'.AiRouterTaskGuard::Port => "http://:{$guard}",
    ])."http://:{$upstream} {\n\tbind 127.0.0.1\n\trespond \"auth={header.Authorization} api={header.X-Api-Key} uri={uri}\"\n}\n";
    file_put_contents("{$directory}/Caddyfile", $caddyfile);
    $caddy = Process::start(['caddy', 'run', '--adapter', 'caddyfile', '--config', "{$directory}/Caddyfile"]);
    try {
        retry(50, static fn () => Http::post("http://127.0.0.1:{$guard}/"), 100);
        $send = static fn (string $method, string $uri, ?string $key = 'task-key'): array => [
            ($response = Http::withHeaders(array_filter(['Authorization' => $key === null ? null : "Bearer {$key}", 'X-Api-Key' => 'other']))
                ->send($method, "http://127.0.0.1:{$guard}{$uri}"))->status(), $response->body()];

        foreach (['/v1/responses', '/v1/chat/completions', '/v1/messages'] as $path) {
            expect($send('POST', $path))->toBe([200, "auth=Bearer anchor-key api= uri={$path}"]);
        }
        // The HTTP client resolves `..` before it sends, so a dot-segment path is not among these; the matcher reads the path as sent.
        foreach ([['GET', '/v1/responses', 'task-key'], ['POST', '/v1/responses', 'wrong'], ['POST', '/v1/responses', null],
            ['POST', '/v1/responses', 'anchor-key'], ['POST', '/v0/management/config', 'task-key'], ['POST', '/v1/models', 'task-key'],
            ['POST', '/v1//responses', 'task-key'], ['POST', '/V1/Responses', 'task-key'],
            ['POST', '/v1/responses?key=x', 'task-key']] as [$method, $uri, $key]) {
            expect($send($method, $uri, $key)[0])->toBe(403, "{$method} {$uri} with ".($key ?? 'no key'));
        }
    } finally {
        $caddy->stop(2);
        Process::run(['rm', '-rf', $directory]);
    }
});
