<?php

declare(strict_types=1);

use App\Domain\Engine\NodeBusAccess;
use App\Domain\Nodes\NodeExecutorInput;
use App\Domain\Nodes\RoleName;
use App\Infrastructure\Engine\GatewayBus;
use App\Infrastructure\Engine\NatsCredentials;
use App\Models\Node;
use Basis\Nats\NKeys\SecretKey;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;

uses(LazilyRefreshDatabase::class);

beforeEach(function (): void {
    $this->gateway = Node::withoutEvents(fn (): Node => Node::query()->create([
        'name' => 'gateway', 'status' => 'active', 'platform' => 'linux', 'wireguard_ip' => '10.44.0.1', 'public_ssh_host' => '10.44.0.1', 'user' => 'orbit',
    ]));
    $this->gateway->roles()->create(['role' => RoleName::Gateway, 'status' => 'active']);
    $this->node = Node::withoutEvents(fn (): Node => Node::query()->create([
        'name' => 'app-dev', 'status' => 'active', 'platform' => 'linux', 'wireguard_ip' => '10.44.0.2', 'public_ssh_host' => '10.44.0.2', 'user' => 'orbit',
    ]));
    $this->seed = NatsCredentials::newSeed();
    config(['orbit.engine.nats.seed' => $this->seed, 'orbit.engine.nats.port' => 4222]);
});

/** An authorization request as `nats-server` sends it; the callout reads its claims without the server's signature. */
function busAuthRequest(string $host, string $userKey = 'UCONNECTION', string $server = 'NSERVER'): string
{
    $part = static fn (array $value): string => rtrim(strtr(base64_encode(json_encode($value, JSON_THROW_ON_ERROR)), '+/', '-_'), '=');

    return $part(['typ' => 'JWT', 'alg' => 'ed25519-nkey']).'.'.$part(['iss' => $server, 'nats' => ['type' => 'authorization_request',
        'user_nkey' => $userKey, 'server_id' => ['id' => $server], 'client_info' => ['host' => $host]]]).'.sig';
}

/** The claims of a JWT, after checking that the issuer seed signed it. */
function busSignedClaims(string $jwt, string $seed): array
{
    [$header, $body, $signature] = explode('.', $jwt);
    $public = sodium_crypto_sign_publickey_from_secretkey(SecretKey::fromSeed($seed)->value);
    expect(sodium_crypto_sign_verify_detached(base64_decode(strtr($signature, '-_', '+/')), "{$header}.{$body}", $public))->toBeTrue();

    return (array) NatsCredentials::claims($jwt);
}

it('gives an executor no bus address while the Gateway has no engine key', function (): void {
    config(['orbit.engine.nats.seed' => null]);

    expect(NodeBusAccess::url())->toBeNull()
        ->and(NodeBusAccess::guard())->toBeNull()
        ->and(GatewayBus::config())->toBeNull();
});

it('gives an executor only the Gateway\'s address, never a credential', function (): void {
    expect(NodeBusAccess::url())->toBe('nats://10.44.0.1:4222');
});

it('sends every connection but the Gateway\'s user to the auth callout', function (): void {
    $config = (string) GatewayBus::config();
    $gateway = NatsCredentials::publicKey(GatewayBus::gatewaySeed());

    expect($config)->toContain('issuer: '.NatsCredentials::publicKey($this->seed).", account: ORBIT, auth_users: [ {$gateway} ]")
        ->and($config)->toContain("users: [ { nkey: {$gateway} } ]")
        ->and($config)->not->toContain($this->seed);
});

it('admits an active Node\'s WireGuard address with a one-hour user limited to that Node\'s subjects', function (): void {
    Carbon::setTestNow('2026-11-19 12:00:00');
    $response = busSignedClaims(NodeBusAccess::authorize(busAuthRequest('10.44.0.2')), $this->seed);
    $user = busSignedClaims($response['nats']['jwt'], $this->seed);
    $id = $this->node->id;

    expect($response['sub'])->toBe('UCONNECTION')
        ->and($response['aud'])->toBe('NSERVER')
        ->and($response['nats']['type'])->toBe('authorization_response')
        ->and($response['nats'])->not->toHaveKey('error')
        ->and($user['sub'])->toBe('UCONNECTION')
        ->and($user['aud'])->toBe('ORBIT')
        ->and($user['name'])->toBe("node-{$id}")
        ->and($user['exp'] - $user['iat'])->toBe(3600)
        ->and($user['nats']['sub']['allow'])->toBe(["_INBOX.node-{$id}.>"])
        ->and($user['nats']['pub']['allow'])->toBe(['$JS.API.INFO', '$JS.API.STREAM.INFO.ORDERS', "\$JS.API.CONSUMER.INFO.ORDERS.node-{$id}",
            "\$JS.API.CONSUMER.MSG.NEXT.ORDERS.node-{$id}", "\$JS.ACK.ORDERS.node-{$id}.>", "orbit.receipts.{$id}",
            "\$JS.API.DIRECT.GET.KV_CONTENT.\$KV.CONTENT.n{$id}.>", "\$KV.NODES.n{$id}"]);
});

it('refuses an address that is no active Node', function (string $host): void {
    $this->node->forceFill(['status' => 'removing'])->saveQuietly();
    $response = busSignedClaims(NodeBusAccess::authorize(busAuthRequest($host)), $this->seed);

    expect($response['nats'])->not->toHaveKey('jwt')
        ->and($response['nats']['error'])->toBe('peer.identity_unknown');
})->with(['removed Node' => '10.44.0.2', 'unknown address' => '10.44.0.99', 'no address' => '']);

it('answers nothing to a request it cannot read', function (string $request): void {
    expect(NodeBusAccess::authorize($request))->toBe('');
})->with(['not a JWT' => 'garbage', 'no connection key' => busAuthRequest('10.44.0.2', userKey: '')]);

it('lets only root on a Node reach the bus, with the guard loaded before every executor start', function (): void {
    expect(NodeBusAccess::guard())->toContain("destroy table inet orbit_bus_guard\n")
        ->toContain("ip daddr 10.44.0.1 tcp dport 4222 meta skuid != 0 reject with tcp reset\n")
        ->and(NodeExecutorInput::unit(busGuard: true))
        ->toContain("[Service]\nExecStartPre=/usr/sbin/nft -f /etc/orbit/executor/bus-guard.nft\nExecStart=/usr/local/libexec/orbit-executor serve\n");
});
