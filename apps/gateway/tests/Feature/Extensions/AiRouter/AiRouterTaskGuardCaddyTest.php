<?php

declare(strict_types=1);

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
it('passes only a POST of the task key from the range to the inference and Gemini routes, with the anchor key in its place', function (): void {
    if (! is_executable(trim((string) shell_exec('command -v caddy')))) {
        $this->markTestSkipped('Caddy is not installed.');
    }
    $node = Node::withoutEvents(fn (): Node => Node::query()->create(['name' => 'services', 'status' => 'active', 'platform' => 'linux',
        'architecture' => 'x86_64', 'public_ssh_host' => '10.44.0.3', 'wireguard_ip' => '10.44.0.3', 'user' => 'orbit']));
    $upstream = guardFreePort();
    $guard = guardFreePort();
    app(AiRouterState::class)->enable($node->id, 'cache', "http://10.44.0.3:{$upstream}", 'management', 'read', 'control');
    $directory = sys_get_temp_dir().'/orbit-guard-'.bin2hex(random_bytes(4));
    mkdir($directory);
    file_put_contents("{$directory}/task.key", 'task-key');
    file_put_contents("{$directory}/anchor.key", 'anchor-key');
    $caddyfile = strtr(app(AiRouterTaskGuard::class)->caddyfile($node, []), [
        'bind 10.44.0.3' => 'bind 127.0.0.1', 'remote_ip 10.44.0.128/25' => 'remote_ip 127.0.0.1/32',
        AiRouterTaskGuard::Credentials => $directory, 'http://:'.AiRouterTaskGuard::Port => "http://:{$guard}",
    ])."http://:{$upstream} {\n\tbind 127.0.0.1\n\trespond \"auth={header.Authorization} api={header.X-Api-Key} goog={header.X-Goog-Api-Key} uri={uri}\"\n}\n";
    file_put_contents("{$directory}/Caddyfile", $caddyfile);
    $caddy = Process::start(['caddy', 'run', '--adapter', 'caddyfile', '--config', "{$directory}/Caddyfile"]);
    try {
        retry(50, static function () use ($upstream, $guard): void {
            Http::post("http://127.0.0.1:{$upstream}/");
            Http::post("http://127.0.0.1:{$guard}/");
        }, 100);
        $send = static fn (string $method, string $uri, ?string $key = 'task-key'): array => [
            ($response = Http::withHeaders(array_filter(['Authorization' => $key === null ? null : "Bearer {$key}", 'X-Api-Key' => 'other']))
                ->send($method, "http://127.0.0.1:{$guard}{$uri}"))->status(), $response->body()];

        foreach (['/v1/responses', '/v1/chat/completions', '/v1/messages', '/v1/messages/count_tokens',
            '/v1/messages?beta=true', '/v1/messages/count_tokens?beta=true'] as $uri) {
            expect($send('POST', $uri))->toBe([200, "auth=Bearer anchor-key api= goog= uri={$uri}"]);
        }
        // The HTTP client resolves `..` before it sends, so a dot-segment path is not among these; the matcher reads the path as sent.
        foreach ([['GET', '/v1/responses', 'task-key'], ['POST', '/v1/responses', 'wrong'], ['POST', '/v1/responses', null],
            ['POST', '/v1/responses', 'anchor-key'], ['POST', '/v0/management/config', 'task-key'], ['POST', '/v1/models', 'task-key'],
            ['POST', '/v1//responses', 'task-key'], ['POST', '/V1/Responses', 'task-key'],
            ['POST', '/v1/responses?key=x', 'task-key'], ['POST', '/v1/responses?beta=true', 'task-key'],
            ['POST', '/v1/chat/completions?beta=true', 'task-key'], ['POST', '/v1/messages?beta=true&key=x', 'task-key'],
            ['POST', '/v1/messages?beta=false', 'task-key'], ['POST', '/v1/messages?key=x', 'task-key'],
            ['POST', '/v1/messages?beta=true', 'wrong']] as [$method, $uri, $key]) {
            expect($send($method, $uri, $key)[0])->toBe(401, "{$method} {$uri} with ".($key ?? 'no key'));
        }

        // Antigravity's Gemini routes: the task key in X-Goog-Api-Key, the anchor key upstream as Bearer, the Google key header dropped.
        $gemini = static fn (string $method, string $uri, array $headers): array => [
            ($response = Http::withHeaders($headers)->send($method, "http://127.0.0.1:{$guard}{$uri}"))->status(), $response->body()];
        foreach (['/v1beta/models/gemini-3.1-flash-lite-preview:streamGenerateContent?alt=sse', '/v1beta/models/gemini-2.5-pro:generateContent',
            '/v1beta/models/gemini_3.1-pro:countTokens'] as $uri) {
            expect($gemini('POST', $uri, ['X-Goog-Api-Key' => 'task-key']))->toBe([200, "auth=Bearer anchor-key api= goog= uri={$uri}"]);
        }
        foreach ([['GET', '/v1beta/models/gemini-2.5-pro:generateContent', ['X-Goog-Api-Key' => 'task-key']],
            ['POST', '/v1beta/models/gemini-2.5-pro:generateContent', ['X-Goog-Api-Key' => 'wrong']],
            ['POST', '/v1beta/models/gemini-2.5-pro:generateContent', ['X-Goog-Api-Key' => 'anchor-key']],
            ['POST', '/v1beta/models/gemini-2.5-pro:generateContent', ['Authorization' => 'Bearer task-key']],
            ['POST', '/v1beta/models/gemini-2.5-pro:generateContent', []],
            ['POST', '/v1/responses', ['X-Goog-Api-Key' => 'task-key']],
            ['POST', '/v1beta/models/gemini-2.5-pro:streamGenerateContent', ['X-Goog-Api-Key' => 'task-key']],
            ['POST', '/v1beta/models/gemini-2.5-pro:streamGenerateContent?alt=sse&key=x', ['X-Goog-Api-Key' => 'task-key']],
            ['POST', '/v1beta/models/gemini-2.5-pro:generateContent?alt=sse', ['X-Goog-Api-Key' => 'task-key']],
            ['POST', '/v1beta/models/gemini-2.5-pro:generateContent?key=task-key', []],
            ['POST', '/v1beta/models/gemini-2.5-pro:countTokens?key=x', ['X-Goog-Api-Key' => 'task-key']],
            ['POST', '/v1beta/models/gemini..pro:generateContent', ['X-Goog-Api-Key' => 'task-key']],
            ['POST', '/v1beta/models/:generateContent', ['X-Goog-Api-Key' => 'task-key']],
            ['POST', '/v1beta/models/a/b:generateContent', ['X-Goog-Api-Key' => 'task-key']],
            ['POST', '/v1beta//models/gemini-2.5-pro:generateContent', ['X-Goog-Api-Key' => 'task-key']],
            ['POST', '/v1beta/models/gemini-2.5-pro:generateContent/x', ['X-Goog-Api-Key' => 'task-key']],
            ['POST', '/v1beta/models/gemini-2.5-pro:embedContent', ['X-Goog-Api-Key' => 'task-key']],
            ['POST', '/v1beta/models', ['X-Goog-Api-Key' => 'task-key']],
            ['POST', '/v1/models/gemini-2.5-pro:generateContent', ['X-Goog-Api-Key' => 'task-key']]] as [$method, $uri, $headers]) {
            expect($gemini($method, $uri, $headers)[0])->toBe(401, "{$method} {$uri} with ".json_encode($headers));
        }
        $caddy->stop(2);

        // From a source outside the range, the task key gets 403.
        file_put_contents("{$directory}/Caddyfile", strtr($caddyfile, ['remote_ip 127.0.0.1/32' => 'remote_ip 10.44.0.128/25']));
        $caddy = Process::start(['caddy', 'run', '--adapter', 'caddyfile', '--config', "{$directory}/Caddyfile"]);
        retry(50, static function () use ($upstream, $guard): void {
            Http::post("http://127.0.0.1:{$upstream}/");
            Http::post("http://127.0.0.1:{$guard}/");
        }, 100);
        expect($send('POST', '/v1/responses')[0])->toBe(403)
            ->and($gemini('POST', '/v1beta/models/gemini-2.5-pro:generateContent', ['X-Goog-Api-Key' => 'task-key'])[0])->toBe(403);
    } finally {
        $caddy->stop(2);
        Process::run(['rm', '-rf', $directory]);
    }
});
