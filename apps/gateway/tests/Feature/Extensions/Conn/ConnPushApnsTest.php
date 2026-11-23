<?php

declare(strict_types=1);

use App\Domain\Extensions\ExtensionStore;
use App\Models\Activity;
use App\Models\Node;
use App\Models\NodeRole;
use Illuminate\Contracts\Console\Kernel as ConsoleKernel;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Orbit\Extensions\Conn\Domain\Push\ApnsClient;
use Orbit\Extensions\Conn\Domain\Push\ApnsJwtProvider;
use Orbit\Extensions\Conn\Domain\Push\ConnPush;
use Orbit\Extensions\Conn\Domain\Push\ConnPushCredentials;
use Orbit\Extensions\Conn\Domain\Push\ConnPushCredentialsStore;
use Orbit\Extensions\Conn\Infrastructure\HttpApnsClient;
use Orbit\Extensions\Conn\Jobs\SendConnPushJob;
use Orbit\Extensions\Conn\Models\ConnAttentionEvent;
use Orbit\Extensions\Conn\Models\ConnEnvironment;
use Orbit\Extensions\Conn\Models\ConnPushDevice;
use Orbit\Extensions\Conn\Models\ConnPushSend;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;

uses(LazilyRefreshDatabase::class);

/**
 * Generates a throwaway EC P-256 keypair in memory for tests; no real APNs key is ever committed.
 *
 * @return array{private: string, public: string}
 */
function apns_test_ec_keypair(): array
{
    $key = openssl_pkey_new([
        'private_key_type' => OPENSSL_KEYTYPE_EC,
        'curve_name' => 'prime256v1',
    ]);
    assert($key !== false);

    $privatePem = '';
    $exported = openssl_pkey_export($key, $privatePem);
    expect($exported)->toBeTrue();
    $details = openssl_pkey_get_details($key);
    assert(is_array($details) && is_string($details['key'] ?? null));

    return ['private' => $privatePem, 'public' => $details['key']];
}

/** Converts an RFC 7518 64-byte raw R||S ES256 signature back to ASN.1 DER for openssl_verify(). */
function apns_raw_to_der(string $raw): string
{
    assert(strlen($raw) === 64);
    $encodeInt = static function (string $bytes): string {
        $bytes = ltrim($bytes, "\x00");
        if ($bytes === '' || (ord($bytes[0]) & 0x80) !== 0) {
            $bytes = "\x00".$bytes;
        }

        return "\x02".chr(strlen($bytes)).$bytes;
    };

    $r = $encodeInt(substr($raw, 0, 32));
    $s = $encodeInt(substr($raw, 32, 32));

    return "\x30".chr(strlen($r.$s)).$r.$s;
}

function apns_base64url_decode(string $value): string
{
    $padded = str_pad(strtr($value, '-_', '+/'), (int) (ceil(strlen($value) / 4) * 4), '=');
    $decoded = base64_decode($padded, true);
    assert(is_string($decoded));

    return $decoded;
}

/**
 * Runs a Gateway Artisan command with an explicit stdin stream (no real STDIN/TTY touched).
 *
 * @param  array<string, mixed>  $parameters
 * @return array{exit: int, output: string}
 */
function run_artisan_with_stdin(string $command, array $parameters, string $stdin): array
{
    $stream = fopen('php://memory', 'r+');
    assert(is_resource($stream));
    fwrite($stream, $stdin);
    rewind($stream);

    $input = new ArrayInput(['command' => $command, ...$parameters]);
    $input->setStream($stream);
    $output = new BufferedOutput;

    $exit = app(ConsoleKernel::class)->handle($input, $output);
    fclose($stream);

    return ['exit' => $exit, 'output' => $output->fetch()];
}

function apns_node(string $name, string $ip): Node
{
    return Node::withoutEvents(fn (): Node => Node::query()->create([
        'name' => $name,
        'status' => 'active',
        'platform' => 'linux',
        'public_ssh_host' => $ip,
        'wireguard_ip' => $ip,
        'user' => 'orbit',
    ]));
}

function apns_device(Node $node, string $token, string $apnsEnvironment = 'sandbox', array $preferences = []): ConnPushDevice
{
    return ConnPushDevice::query()->create([
        'node_id' => $node->id,
        'device_id' => (string) Str::uuid(),
        'apns_token' => $token,
        'apns_environment' => $apnsEnvironment,
        'bundle_id' => 'dev.conn.ios',
        'app_version' => '1.2.3',
        'ios_version' => '18.4',
        'locale' => 'en_US',
        'preferences' => array_merge([
            'approval' => true,
            'input' => true,
            'turn_finished' => true,
            'turn_failed' => true,
            'hide_titles' => false,
        ], $preferences),
    ]);
}

function apns_event(ConnEnvironment $env, array $overrides = []): ConnAttentionEvent
{
    return ConnAttentionEvent::query()->create(array_merge([
        'event_id' => (string) Str::ulid(),
        'conn_environment_id' => $env->id,
        'environment_id' => $env->environment_id,
        'thread_id' => 'thread-1',
        'type' => 'attention',
        'kind' => 'approval',
        'thread_title' => 'Approve production deploy',
        'occurred_at' => Carbon::now(),
    ], $overrides));
}

beforeEach(function (): void {
    Carbon::setTestNow(Carbon::parse('2026-11-23T10:00:00Z'));
    app(ExtensionStore::class)->set('conn', true);
    config(['orbit.conn.push' => true]);

    $this->keypair = apns_test_ec_keypair();
    $this->keyId = 'AB12CD34EF';
    $this->teamId = 'TEAM987654';

    app(ConnPushCredentialsStore::class)->put(
        new ConnPushCredentials($this->keyId, $this->teamId, $this->keypair['private']),
    );
    app(ApnsJwtProvider::class)->clear();

    // Bind the real HttpApnsClient with zero-delay sleeper so retry tests stay fast.
    $this->sleeps = [];
    app()->singleton(ApnsClient::class, fn (): HttpApnsClient => new HttpApnsClient(
        jwt: app(ApnsJwtProvider::class),
        backoffMs: [200, 500],
        sleeper: function (int $ms): void {
            $this->sleeps[] = $ms;
        },
    ));

    $this->gateway = apns_node('gateway', '10.44.0.2');
    NodeRole::withoutEvents(fn () => $this->gateway->roles()->create(['role' => 'gateway', 'status' => 'active']));
    $this->serving = apns_node('mac', '10.44.0.10');
    $this->phone = apns_node('iphone', '10.44.0.20');
    $this->phone->accessibleNodes()->attach($this->serving->id);

    $this->environmentId = '6f1c2e9a-3b4d-4c5e-8f60-718293a4b5c6';
    $this->environment = ConnEnvironment::query()->create([
        'environment_id' => $this->environmentId,
        'label' => 'mac-dev',
        'url' => 'http://10.44.0.10:3773',
        'node_id' => $this->serving->id,
        'admin_session' => 'admin-token',
        'admin_session_expires_at' => Carbon::now()->addDays(30),
        'registered_at' => Carbon::now(),
    ]);
});

afterEach(function (): void {
    Carbon::setTestNow();
});

describe('ConnPush ES256 JWT', function (): void {
    it('signs a valid RFC 7518 ES256 JWT with kid and iss and renews every 40 minutes', function (): void {
        $provider = app(ApnsJwtProvider::class);

        $token1 = $provider->token();
        expect($token1)->toBeString();
        $parts = explode('.', (string) $token1);
        expect($parts)->toHaveCount(3);

        $header = json_decode(apns_base64url_decode($parts[0]), true, flags: JSON_THROW_ON_ERROR);
        $claims = json_decode(apns_base64url_decode($parts[1]), true, flags: JSON_THROW_ON_ERROR);
        $rawSig = apns_base64url_decode($parts[2]);

        expect($header)->toBe(['alg' => 'ES256', 'kid' => $this->keyId])
            ->and($claims)->toBe(['iss' => $this->teamId, 'iat' => Carbon::now()->getTimestamp()])
            ->and(strlen($rawSig))->toBe(64);

        // Verify cryptographic validity against the throwaway EC public key.
        $verified = openssl_verify(
            "{$parts[0]}.{$parts[1]}",
            apns_raw_to_der($rawSig),
            $this->keypair['public'],
            OPENSSL_ALGO_SHA256,
        );
        expect($verified)->toBe(1);

        // Cached within 40 minutes.
        Carbon::setTestNow(Carbon::now()->addMinutes(39)->addSeconds(59));
        expect($provider->token())->toBe($token1);

        // Renewed at 40 minutes.
        Carbon::setTestNow(Carbon::now()->addSecond());
        $token2 = $provider->token();
        expect($token2)->not->toBe($token1);

        $claims2 = json_decode(apns_base64url_decode(explode('.', (string) $token2)[1]), true, flags: JSON_THROW_ON_ERROR);
        expect($claims2['iat'])->toBe(Carbon::now()->getTimestamp());
    });
});

describe('ConnPush HTTP/2 APNs client', function (): void {
    it('routes sandbox and production devices to their respective APNs hosts with exact headers and payloads', function (): void {
        $sandboxToken = str_repeat('aa', 32);
        $prodToken = str_repeat('bb', 32);
        $sandboxDevice = apns_device($this->phone, $sandboxToken, 'sandbox', ['hide_titles' => false]);
        $prodDevice = apns_device($this->phone, $prodToken, 'production', ['hide_titles' => true]);

        $captured = [];
        Http::fake(function (HttpRequest $request) use (&$captured) {
            $captured[] = $request;

            return Http::response('', 200, ['apns-id' => 'apns-uuid-'.count($captured)]);
        });

        $event = apns_event($this->environment, [
            'thread_id' => 'thread-99',
            'kind' => 'approval',
            'thread_title' => 'Top secret title',
        ]);

        app()->call([new SendConnPushJob($event->id), 'handle']);

        expect($captured)->toHaveCount(2);

        $expectedCollapse = hash('sha256', "{$this->environmentId}:thread-99");
        $expectedExpiry = (string) (Carbon::now()->getTimestamp() + 3600);

        // Sandbox device: sandbox host, HTTP/2, priority 10, title visible, exact userInfo.
        $r1 = $captured[0];
        expect($r1->url())->toBe("https://api.sandbox.push.apple.com/3/device/{$sandboxToken}")
            ->and($r1->toPsrRequest()->getProtocolVersion())->toBe('2.0')
            ->and($r1->header('authorization')[0])->toStartWith('bearer ')
            ->and($r1->header('apns-push-type')[0])->toBe('alert')
            ->and($r1->header('apns-topic')[0])->toBe('dev.conn.ios')
            ->and($r1->header('apns-priority')[0])->toBe('10')
            ->and($r1->header('apns-expiration')[0])->toBe($expectedExpiry)
            ->and($r1->header('apns-collapse-id')[0])->toBe($expectedCollapse)
            ->and($r1->data())->toBe([
                'aps' => [
                    'alert' => ['title' => 'Top secret title', 'body' => 'Approval needed'],
                    'sound' => 'default',
                ],
                'environment_id' => $this->environmentId,
                'thread_id' => 'thread-99',
                'kind' => 'approval',
            ]);

        // Production device: production host, same JWT, hide_titles respected (no title leaked).
        $r2 = $captured[1];
        expect($r2->url())->toBe("https://api.push.apple.com/3/device/{$prodToken}")
            ->and($r2->header('authorization')[0])->toBe($r1->header('authorization')[0])
            ->and($r2->data())->toBe([
                'aps' => [
                    'alert' => ['title' => 'Conn', 'body' => 'Approval needed'],
                    'sound' => 'default',
                ],
                'environment_id' => $this->environmentId,
                'thread_id' => 'thread-99',
                'kind' => 'approval',
            ]);

        // Send records stored without tokens or titles.
        $sends = ConnPushSend::query()->orderBy('id')->get();
        expect($sends)->toHaveCount(2)
            ->and($sends[0]->event_id)->toBe($event->event_id)
            ->and($sends[0]->conn_push_device_id)->toBe($sandboxDevice->id)
            ->and($sends[0]->apns_status)->toBe(200)
            ->and($sends[0]->apns_id)->toBe('apns-uuid-1')
            ->and($sends[1]->conn_push_device_id)->toBe($prodDevice->id)
            ->and($sends[1]->apns_id)->toBe('apns-uuid-2');
        expect(json_encode($sends->toArray()))
            ->not->toContain($sandboxToken)
            ->not->toContain($prodToken)
            ->not->toContain('Top secret title');
    });

    it('sends background push with content-available and priority 5 for attention_cleared', function (): void {
        apns_device($this->phone, str_repeat('cc', 32), 'sandbox');

        $captured = [];
        Http::fake(function (HttpRequest $request) use (&$captured) {
            $captured[] = $request;

            return Http::response('', 200, ['apns-id' => 'apns-bg-1']);
        });

        $cleared = apns_event($this->environment, [
            'type' => 'attention_cleared',
            'kind' => null,
            'thread_title' => null,
        ]);

        app()->call([new SendConnPushJob($cleared->id), 'handle']);

        expect($captured)->toHaveCount(1)
            ->and($captured[0]->header('apns-push-type')[0])->toBe('background')
            ->and($captured[0]->header('apns-priority')[0])->toBe('5')
            ->and($captured[0]->data())->toBe([
                'aps' => ['content-available' => 1],
                'environment_id' => $this->environmentId,
                'thread_id' => 'thread-1',
                'kind' => null,
            ]);
    });

    it('retries 429/500/503 with backoff up to 3 tries and succeeds on retry', function (): void {
        apns_device($this->phone, str_repeat('dd', 32), 'sandbox');

        Http::fakeSequence()
            ->push(['reason' => 'TooManyRequests'], 429)
            ->push(['reason' => 'InternalServerError'], 500)
            ->push('', 200, ['apns-id' => 'apns-retry-ok']);

        $event = apns_event($this->environment, ['thread_id' => 'thread-r1']);
        app()->call([new SendConnPushJob($event->id), 'handle']);

        expect($this->sleeps)->toBe([200, 500]);
        $send = ConnPushSend::query()->where('event_id', $event->event_id)->firstOrFail();
        expect($send->apns_status)->toBe(200)->and($send->apns_id)->toBe('apns-retry-ok');
    });

    it('stops retrying 503 after 3 tries and records the failure', function (): void {
        apns_device($this->phone, str_repeat('d2', 32), 'sandbox');

        Http::fakeSequence()
            ->push(['reason' => 'ServiceUnavailable'], 503)
            ->push(['reason' => 'ServiceUnavailable'], 503)
            ->push(['reason' => 'ServiceUnavailable'], 503)
            ->push('', 200, ['apns-id' => 'should-not-be-called']);

        $event = apns_event($this->environment, ['thread_id' => 'thread-r2']);
        app()->call([new SendConnPushJob($event->id), 'handle']);

        expect($this->sleeps)->toBe([200, 500]);
        $send = ConnPushSend::query()->where('event_id', $event->event_id)->firstOrFail();
        expect($send->apns_status)->toBe(503)->and($send->apns_reason)->toBe('ServiceUnavailable');
    });

    it('renews the JWT once on 403 ExpiredProviderToken and succeeds', function (): void {
        apns_device($this->phone, str_repeat('ee', 32), 'sandbox');

        $authHeaders = [];
        Http::fake(function (HttpRequest $request) use (&$authHeaders) {
            $authHeaders[] = $request->header('authorization')[0];
            if (count($authHeaders) === 1) {
                Carbon::setTestNow(Carbon::now()->addSeconds(5));

                return Http::response(['reason' => 'ExpiredProviderToken'], 403);
            }

            return Http::response('', 200, ['apns-id' => 'apns-refreshed']);
        });

        $event = apns_event($this->environment, ['thread_id' => 'thread-jwt']);
        app()->call([new SendConnPushJob($event->id), 'handle']);

        expect($authHeaders)->toHaveCount(2)
            ->and($authHeaders[1])->not->toBe($authHeaders[0]);
        expect(ConnPushSend::query()->where('event_id', $event->event_id)->firstOrFail()->apns_status)->toBe(200);
    });

    it('does not loop when the refreshed JWT also gets 403 ExpiredProviderToken', function (): void {
        apns_device($this->phone, str_repeat('e2', 32), 'sandbox');

        $attempts = 0;
        Http::fake(function () use (&$attempts) {
            $attempts++;

            return Http::response(['reason' => 'ExpiredProviderToken'], 403);
        });

        $event = apns_event($this->environment, ['thread_id' => 'thread-jwt-2']);
        app()->call([new SendConnPushJob($event->id), 'handle']);

        expect($attempts)->toBe(2);
        expect(ConnPushSend::query()->where('event_id', $event->event_id)->firstOrFail()->apns_status)->toBe(403);
    });

    it('deletes the device row and records why on 410 Unregistered, 400 BadDeviceToken, and 400 DeviceTokenNotForTopic', function (int $status, string $reason, bool $shouldDelete): void {
        $device = apns_device($this->phone, str_repeat('f1', 32), 'sandbox');

        Http::fake(['*' => Http::response(['reason' => $reason], $status, ['apns-id' => 'apns-err-1'])]);

        $event = apns_event($this->environment);
        app()->call([new SendConnPushJob($event->id), 'handle']);

        expect(ConnPushDevice::query()->whereKey($device->id)->exists())->toBe(! $shouldDelete);

        $send = ConnPushSend::query()->where('event_id', $event->event_id)->firstOrFail();
        expect($send->conn_push_device_id)->toBe($device->id)
            ->and($send->apns_status)->toBe($status)
            ->and($send->apns_reason)->toBe($reason)
            ->and($send->device_deleted_reason)->toBe($shouldDelete ? $reason : null);
    })->with([
        '410 Unregistered' => [410, 'Unregistered', true],
        '400 BadDeviceToken' => [400, 'BadDeviceToken', true],
        '400 DeviceTokenNotForTopic' => [400, 'DeviceTokenNotForTopic', true],
        '400 PayloadTooLarge kept' => [400, 'PayloadTooLarge', false],
    ]);

    it('sends through the real Guzzle curl handler to a local listener without curl option errors', function (): void {
        $script = <<<'PHP'
$server = stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
if ($server === false) { exit(1); }
$name = stream_socket_get_name($server, false);
echo $name . "\n";
flush();
$conn = @stream_socket_accept($server, 5);
if ($conn !== false) {
    stream_set_timeout($conn, 2);
    $raw = '';
    while (! str_contains($raw, "\r\n\r\n") && ! feof($conn)) {
        $chunk = fread($conn, 4096);
        if ($chunk === false || $chunk === '') { break; }
        $raw .= $chunk;
    }
    fwrite($conn, "HTTP/1.1 200 OK\r\napns-id: real-curl-apns-id\r\nContent-Length: 0\r\nConnection: close\r\n\r\n");
    fclose($conn);
}
fclose($server);
PHP;

        $proc = proc_open(
            [PHP_BINARY, '-r', $script],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
        );
        assert(is_resource($proc));
        fclose($pipes[0]);
        $address = trim((string) fgets($pipes[1]));
        expect($address)->toStartWith('127.0.0.1:');

        try {
            $device = apns_device($this->phone, str_repeat('4a', 32), 'sandbox');
            $event = apns_event($this->environment, ['thread_id' => 'thread-real-curl']);

            app()->singleton(ApnsClient::class, fn (): HttpApnsClient => new HttpApnsClient(
                jwt: app(ApnsJwtProvider::class),
                backoffMs: [0, 0],
                hostOverride: 'http://'.$address,
            ));

            app()->call([new SendConnPushJob($event->id), 'handle']);

            $send = ConnPushSend::query()->where('event_id', $event->event_id)->firstOrFail();
            expect($send->conn_push_device_id)->toBe($device->id)
                ->and($send->apns_status)->toBe(200)
                ->and($send->apns_id)->toBe('real-curl-apns-id');
        } finally {
            fclose($pipes[1]);
            fclose($pipes[2]);
            proc_terminate($proc);
            proc_close($proc);
        }
    });
});

describe('ConnPush rate limits and stale drop', function (): void {
    it('drops events older than 1 hour at delivery', function (): void {
        apns_device($this->phone, str_repeat('12', 32), 'sandbox');

        $calls = 0;
        Http::fake(function () use (&$calls) {
            $calls++;

            return Http::response('', 200, ['apns-id' => 'nope']);
        });

        $stale = apns_event($this->environment, [
            'occurred_at' => Carbon::now()->subHour()->subSecond(),
        ]);

        app()->call([new SendConnPushJob($stale->id), 'handle']);

        expect($calls)->toBe(0)
            ->and(ConnPushSend::query()->count())->toBe(0);
    });

    it('limits alerts to one per thread per 30 seconds while allowing other threads and attention_cleared', function (): void {
        apns_device($this->phone, str_repeat('34', 32), 'sandbox');

        $sent = [];
        Http::fake(function (HttpRequest $request) use (&$sent) {
            $sent[] = $request->data();

            return Http::response('', 200, ['apns-id' => 'apns-'.count($sent)]);
        });

        // 1st alert for thread-A -> sent.
        app()->call([new SendConnPushJob(apns_event($this->environment, ['thread_id' => 'thread-A'])->id), 'handle']);
        // 2nd alert for thread-A 10s later -> suppressed.
        Carbon::setTestNow(Carbon::now()->addSeconds(10));
        app()->call([new SendConnPushJob(apns_event($this->environment, ['thread_id' => 'thread-A'])->id), 'handle']);
        // Alert for thread-B -> sent.
        app()->call([new SendConnPushJob(apns_event($this->environment, ['thread_id' => 'thread-B'])->id), 'handle']);
        // attention_cleared for thread-A within 30s -> still sent (background push).
        app()->call([new SendConnPushJob(apns_event($this->environment, ['thread_id' => 'thread-A', 'type' => 'attention_cleared', 'kind' => null, 'thread_title' => null])->id), 'handle']);
        // 31s after 1st alert for thread-A -> sent again.
        Carbon::setTestNow(Carbon::now()->addSeconds(21));
        app()->call([new SendConnPushJob(apns_event($this->environment, ['thread_id' => 'thread-A'])->id), 'handle']);

        expect($sent)->toHaveCount(4)
            ->and($sent[0]['thread_id'])->toBe('thread-A')
            ->and($sent[1]['thread_id'])->toBe('thread-B')
            ->and($sent[2]['aps'])->toBe(['content-available' => 1])
            ->and($sent[3]['thread_id'])->toBe('thread-A');
    });

    it('limits alerts to 30 per device per hour then sends one summary alert and suppresses further alerts until the next hour', function (): void {
        $device = apns_device($this->phone, str_repeat('56', 32), 'sandbox');

        $sent = [];
        Http::fake(function (HttpRequest $request) use (&$sent) {
            $sent[] = [
                'priority' => $request->header('apns-priority')[0],
                'data' => $request->data(),
            ];

            return Http::response('', 200, ['apns-id' => 'apns-h-'.count($sent)]);
        });

        // Seed 30 successful non-summary alerts in the last hour.
        for ($i = 1; $i <= ConnPush::MaxAlertsPerDevicePerHour; $i++) {
            ConnPushSend::query()->create([
                'event_id' => (string) Str::ulid(),
                'conn_push_device_id' => $device->id,
                'environment_id' => $this->environmentId,
                'thread_id' => 'seeded-thread-'.$i,
                'apns_environment' => 'sandbox',
                'push_type' => 'alert',
                'is_summary' => false,
                'apns_status' => 200,
                'apns_id' => 'seeded-'.$i,
                'created_at' => Carbon::now()->subMinutes(10),
            ]);
        }

        // 31st alert -> triggers the single summary alert (priority 5, no thread title).
        $e31 = apns_event($this->environment, ['thread_id' => 'thread-31', 'thread_title' => 'Private title 31']);
        app()->call([new SendConnPushJob($e31->id), 'handle']);

        expect($sent)->toHaveCount(1)
            ->and($sent[0]['priority'])->toBe('5')
            ->and($sent[0]['data']['aps']['alert'])->toBe([
                'title' => 'Conn',
                'body' => 'Multiple threads need attention',
            ]);
        expect(ConnPushSend::query()->where('event_id', $e31->event_id)->firstOrFail()->is_summary)->toBeTrue();

        // 32nd alert -> suppressed because summary was already sent this hour.
        $e32 = apns_event($this->environment, ['thread_id' => 'thread-32']);
        app()->call([new SendConnPushJob($e32->id), 'handle']);
        expect($sent)->toHaveCount(1);

        // After the 1-hour window rolls over, normal alerts resume.
        Carbon::setTestNow(Carbon::now()->addMinutes(51));
        $e33 = apns_event($this->environment, ['thread_id' => 'thread-33', 'thread_title' => 'Fresh alert']);
        app()->call([new SendConnPushJob($e33->id), 'handle']);

        expect($sent)->toHaveCount(2)
            ->and($sent[1]['priority'])->toBe('10')
            ->and($sent[1]['data']['aps']['alert']['title'])->toBe('Fresh alert');
    });

    it('prunes conn_push_sends older than 7 days alongside attention events', function (): void {
        $device = apns_device($this->phone, str_repeat('78', 32), 'sandbox');

        $old = ConnPushSend::query()->create([
            'event_id' => (string) Str::ulid(),
            'conn_push_device_id' => $device->id,
            'environment_id' => $this->environmentId,
            'thread_id' => 'old-thread',
            'apns_environment' => 'sandbox',
            'push_type' => 'alert',
            'is_summary' => false,
            'apns_status' => 200,
            'apns_id' => 'old-apns-id',
            'created_at' => Carbon::now()->subDays(8),
        ]);
        $recent = ConnPushSend::query()->create([
            'event_id' => (string) Str::ulid(),
            'conn_push_device_id' => $device->id,
            'environment_id' => $this->environmentId,
            'thread_id' => 'recent-thread',
            'apns_environment' => 'sandbox',
            'push_type' => 'alert',
            'is_summary' => false,
            'apns_status' => 200,
            'apns_id' => 'recent-apns-id',
            'created_at' => Carbon::now()->subDays(2),
        ]);

        $this->artisan('conn:push:prune-events')->assertExitCode(0);

        expect(ConnPushSend::query()->pluck('id')->all())->toBe([$recent->id])
            ->and(ConnPushSend::query()->whereKey($old->id)->exists())->toBeFalse();
    });
});

describe('ConnPush credentials commands, API, and secret redaction', function (): void {
    it('stores the .p8 key from stdin encrypted and shows only configured, key_id and team_id', function (): void {
        DB::table('settings')->where('key', 'like', 'conn.push.%')->delete();
        $freshKeypair = apns_test_ec_keypair();
        $pem = $freshKeypair['private'];

        // Show before set: unconfigured.
        $before = run_artisan_with_stdin('conn:push-credentials:show', ['--json' => true], '');
        expect($before['exit'])->toBe(0)
            ->and(json_decode($before['output'], true, flags: JSON_THROW_ON_ERROR))->toBe([
                'configured' => false,
                'key_id' => null,
                'team_id' => null,
            ]);

        // Set reads .p8 from stdin, encrypts it in settings, and never prints it.
        $set = run_artisan_with_stdin('conn:push-credentials:set', [
            '--key-id' => 'NEWKEY1234',
            '--team-id' => 'NEWTEAM567',
        ], $pem);
        expect($set['exit'])->toBe(0)
            ->and($set['output'])->toContain('Configured: yes')
            ->and($set['output'])->toContain('Key ID: NEWKEY1234')
            ->and($set['output'])->toContain('Team ID: NEWTEAM567')
            ->and($set['output'])->not->toContain('PRIVATE KEY')
            ->and($set['output'])->not->toContain($pem);

        $rawStored = DB::table('settings')->where('key', ConnPushCredentialsStore::PrivateKeySetting)->value('value');
        expect($rawStored)->toBeString()->not->toContain('PRIVATE KEY')->not->toBe($pem);
        expect(app(ConnPushCredentialsStore::class)->get()?->privateKey)->toBe(trim($pem));

        // Show after set returns only configured, key_id and team_id.
        $after = run_artisan_with_stdin('conn:push-credentials:show', ['--json' => true], '');
        expect($after['exit'])->toBe(0)
            ->and(json_decode($after['output'], true, flags: JSON_THROW_ON_ERROR))->toBe([
                'configured' => true,
                'key_id' => 'NEWKEY1234',
                'team_id' => 'NEWTEAM567',
            ])
            ->and($after['output'])->not->toContain('PRIVATE KEY');

        // Invalid PEM is rejected without echoing the input.
        $bad = run_artisan_with_stdin('conn:push-credentials:set', [
            '--key-id' => 'NEWKEY1234',
            '--team-id' => 'NEWTEAM567',
        ], 'not-a-valid-secret-pem-sentinel');
        expect($bad['exit'])->not->toBe(0)
            ->and($bad['output'])->not->toContain('not-a-valid-secret-pem-sentinel');
    });

    it('enforces Gateway access on credentials HTTP routes and never leaks key in response or activity', function (): void {
        $freshKeypair = apns_test_ec_keypair();
        $pem = $freshKeypair['private'];

        // Non-gateway peer is forbidden.
        $this->withServerVariables(['REMOTE_ADDR' => $this->phone->wireguard_ip])
            ->getJson('/api/v1/conn/push-credentials')
            ->assertForbidden();

        // Gateway peer can PUT and GET without ever seeing the private key.
        $put = $this->withServerVariables(['REMOTE_ADDR' => $this->gateway->wireguard_ip])
            ->putJson('/api/v1/conn/push-credentials', [
                'key_id' => 'APIKEY1234',
                'team_id' => 'APITEAM567',
                'private_key' => $pem,
            ]);
        $put->assertOk()->assertJsonPath('data', [
            'configured' => true,
            'key_id' => 'APIKEY1234',
            'team_id' => 'APITEAM567',
        ]);
        expect($put->getContent())->not->toContain('PRIVATE KEY')->not->toContain($pem);

        $get = $this->withServerVariables(['REMOTE_ADDR' => $this->gateway->wireguard_ip])
            ->getJson('/api/v1/conn/push-credentials');
        $get->assertOk()->assertJsonPath('data', [
            'configured' => true,
            'key_id' => 'APIKEY1234',
            'team_id' => 'APITEAM567',
        ]);
        expect($get->getContent())->not->toContain('PRIVATE KEY')->not->toContain($pem);

        // Activity must redact private_key.
        $activity = Activity::query()->where('command', 'conn:push-credentials:set')->latest('id')->first();
        expect($activity)->not->toBeNull();
        expect(json_encode($activity->input))->not->toContain('PRIVATE KEY')->not->toContain($pem);
    });

    it('never leaks private key, JWT, device token, or thread title to logs even on connection failure', function (): void {
        $deviceToken = str_repeat('9a', 32);
        $secretTitle = 'Confidential M&A thread';
        apns_device($this->phone, $deviceToken, 'production');

        $jwt = (string) app(ApnsJwtProvider::class)->token();
        $logs = [];
        Log::listen(function (MessageLogged $entry) use (&$logs): void {
            $logs[] = $entry->message.' '.json_encode($entry->context);
        });

        Http::fake(function (HttpRequest $request): never {
            throw new ConnectionException('cURL error 28: Operation timed out for '.$request->url());
        });

        $event = apns_event($this->environment, ['thread_title' => $secretTitle]);
        app()->call([new SendConnPushJob($event->id), 'handle']);

        $allLogs = implode("\n", $logs);
        expect($allLogs)->toContain('conn.push_connection_failed')
            ->and($allLogs)->not->toContain($deviceToken)
            ->and($allLogs)->not->toContain($jwt)
            ->and($allLogs)->not->toContain('PRIVATE KEY')
            ->and($allLogs)->not->toContain($secretTitle);
    });
});
