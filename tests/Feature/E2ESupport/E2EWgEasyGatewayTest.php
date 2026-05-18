<?php

declare(strict_types=1);

use App\E2E\Support\E2EInstance;
use App\E2E\Support\E2EWgEasyGateway;
use Illuminate\Contracts\Process\ProcessResult;
use Mockery as m;

afterEach(function (): void {
    m::close();
});

function e2eWgEasyGatewayResult(bool $successful = true, string $output = '', string $errorOutput = ''): ProcessResult
{
    $result = m::mock(ProcessResult::class);
    $result->shouldReceive('successful')->andReturn($successful);
    $result->shouldReceive('output')->andReturn($output);
    $result->shouldReceive('errorOutput')->andReturn($errorOutput);

    return $result;
}

it('starts wg-easy as the only WireGuard server on host UDP 51820', function (): void {
    $commands = [];
    $instance = m::mock(E2EInstance::class);
    $instance->shouldReceive('name')->andReturn('gateway');
    $instance->shouldReceive('exec')
        ->once()
        ->andReturnUsing(function (string $command) use (&$commands): ProcessResult {
            $commands[] = $command;

            return e2eWgEasyGatewayResult();
        });

    (new E2EWgEasyGateway)->start($instance, '10.231.0.11');

    expect($commands[0])->toContain('docker run -d')
        ->and($commands[0])->toContain('--name wg-easy')
        ->and($commands[0])->toContain('-p 51820:51820/udp')
        ->and($commands[0])->not->toContain('51822')
        ->and($commands[0])->not->toContain('wg-quick down wg-orbit')
        ->and($commands[0])->toContain('-p 127.0.0.1:51821:51821/tcp')
        ->and($commands[0])->toContain('--cap-add NET_ADMIN')
        ->and($commands[0])->toContain('--cap-add SYS_MODULE')
        ->and($commands[0])->toContain('-v /lib/modules:/lib/modules:ro')
        ->and($commands[0])->toContain('ghcr.io/wg-easy/wg-easy:15')
        ->and($commands[0])->toContain('docker exec wg-easy ip addr replace 10.6.0.1/24 dev wg0')
        ->and($commands[0])->toContain('docker exec wg-easy ip route replace 10.6.0.0/24 dev wg0')
        ->and($commands[0])->toContain("UPDATE interfaces_table SET ipv4_cidr = '10.6.0.0/24'")
        ->and($commands[0])->toContain('INIT_HOST=10.231.0.11')
        ->and($commands[0])->toContain('INIT_PASSWORD=orbit-e2e-bootstrap-password')
        ->and($commands[0])->toContain('INSECURE=true');
});

it('persists and activates topology peers on wg-easy wg0', function (): void {
    $commands = [];
    $instance = m::mock(E2EInstance::class);
    $instance->shouldReceive('name')->andReturn('gateway');
    $instance->shouldReceive('exec')
        ->once()
        ->andReturnUsing(function (string $command) use (&$commands): ProcessResult {
            $commands[] = $command;

            return e2eWgEasyGatewayResult();
        });

    (new E2EWgEasyGateway)->configurePeers($instance, [
        ['name' => 'gateway', 'private_key' => 'gateway-host-private', 'public_key' => 'gateway-host-public', 'pre_shared_key' => 'gateway-psk', 'address' => '10.6.0.2'],
        ['name' => 'control', 'private_key' => 'control-private', 'public_key' => 'control-public', 'pre_shared_key' => 'control-psk', 'address' => '10.6.0.3'],
        ['name' => 'dev', 'private_key' => 'dev-private', 'public_key' => 'dev-public', 'pre_shared_key' => 'dev-psk', 'address' => '10.6.0.4'],
    ]);

    expect($commands[0])->toContain('sqlite3 /home/orbit/.wg-easy/wg-easy.db')
        ->and($commands[0])->toContain('clients_table')
        ->and($commands[0])->toContain('gateway-host-public')
        ->and($commands[0])->toContain('gateway-psk')
        ->and($commands[0])->not->toContain("'',")
        ->and($commands[0])->toContain('10.6.0.2/32')
        ->and($commands[0])->toContain('wg set wg0 peer')
        ->and($commands[0])->toContain('gateway-host-public')
        ->and($commands[0])->toContain('preshared-key')
        ->and($commands[0])->toContain('10.6.0.2/32')
        ->and($commands[0])->toContain('control-public')
        ->and($commands[0])->toContain('10.6.0.3/32')
        ->and($commands[0])->toContain('dev-public')
        ->and($commands[0])->toContain('10.6.0.4/32')
        ->and($commands[0])->not->toContain('ListenPort = 51820');
});
