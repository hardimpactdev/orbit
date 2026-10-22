<?php

declare(strict_types=1);

use App\Domain\Processes\ProcessRuntime;
use App\Domain\Routes\CustomProxyProcessListener;
use App\Domain\Shared\LifecycleStatus;
use App\Domain\Shared\ResourceOperationException;
use App\Models\Node;
use App\Models\Process;
use Tests\TestCase;

uses(TestCase::class);

describe(CustomProxyProcessListener::class, function (): void {
    it('resolves exactly one published loopback listener to 127.0.0.1', function (array $ports): void {
        $upstream = new CustomProxyProcessListener()->resolve(listener_process($ports));

        expect($upstream->url())->toBe('http://127.0.0.1:4788');
    })->with([
        'loopback' => [['127.0.0.1:4788:80/tcp']],
        'all interfaces' => [['0.0.0.0:4788:80/tcp']],
        'ipv6 loopback' => [['::1:4788:80/tcp']],
    ]);

    it('refuses a Process without one Node-local listener', function (ProcessRuntime $runtime, array $ports): void {
        expect(fn () => new CustomProxyProcessListener()->resolve(listener_process($ports, $runtime)))
            ->toThrow(function (ResourceOperationException $exception): void {
                expect($exception->errorCode)->toBe('route.upstream_unresolved');
            });
    })->with([
        'no ports' => [ProcessRuntime::Docker, []],
        'two loopback ports' => [ProcessRuntime::Docker, ['127.0.0.1:4788:80/tcp', '127.0.0.1:4789:81/tcp']],
        'non-loopback' => [ProcessRuntime::Docker, ['10.44.0.8:4788:80/tcp']],
        'systemd' => [ProcessRuntime::Systemd, ['127.0.0.1:4788:80/tcp']],
    ]);
});

function listener_process(array $ports, ProcessRuntime $runtime = ProcessRuntime::Docker): Process
{
    $process = new Process;
    $process->name = 'executor';
    $process->runtime = $runtime;
    $process->runtime_config = ['ports' => $ports];

    return $process;
}

function public_listener_process(array $ports): Process
{
    $node = new Node(['wireguard_ip' => '10.44.0.3', 'platform' => 'linux', 'status' => 'active']);
    $process = listener_process($ports);
    $process->owner_type = Node::class;
    $process->status = LifecycleStatus::Active;
    $process->setRelation('owner', $node);

    return $process;
}

it('resolves the public Process listener on WireGuard even when its Node has a LAN address', function (): void {
    $process = public_listener_process(['10.44.0.3:8095:80/tcp']);
    $process->owner->lan_ip = '10.0.0.6';
    expect(new CustomProxyProcessListener()->resolvePublic($process, new Node)->url())->toBe('http://10.44.0.3:8095');
});

it('refuses public Process listeners that do not identify one WireGuard TCP destination', function (array $ports): void {
    expect(fn () => new CustomProxyProcessListener()->resolvePublic(public_listener_process($ports), new Node))
        ->toThrow(fn (ResourceOperationException $e) => expect($e->errorCode)->toBe('route.upstream_unresolved'));
})->with([
    'loopback' => [['127.0.0.1:8095:80']],
    'wildcard' => [['0.0.0.0:8095:80']],
    'implicit wildcard' => [['8095:80']],
    'LAN' => [['10.0.0.6:8095:80']],
    'other WireGuard' => [['10.44.0.4:8095:80']],
    'UDP' => [['10.44.0.3:8095:80/udp']],
    'ambiguous' => [['10.44.0.3:8095:80', '10.44.0.3:8096:81']],
    'none' => [[]],
]);

it('selects LAN only for matching recorded IPv4 networks and resolves the published port there', function (?string $ingressLan, ?string $processLan, string $host): void {
    $process = public_listener_process(['10.44.0.3:8095:8080/tcp', '10.0.0.6:8095:8080/tcp']);
    $process->owner->lan_ip = $processLan;
    $ingress = new Node(['lan_ip' => $ingressLan]);
    expect(new CustomProxyProcessListener()->resolvePublic($process, $ingress)->url())->toBe('http://'.$host.':8095');
})->with([
    'same LAN' => ['10.0.0.4', '10.0.0.6', '10.0.0.6'],
    'Ingress no LAN' => [null, '10.0.0.6', '10.44.0.3'],
    'Process no LAN' => ['10.0.0.4', null, '10.44.0.3'],
    'different networks' => ['10.1.0.4', '10.0.0.6', '10.44.0.3'],
]);

it('refuses a WireGuard-only listener when the selected path is LAN', function (): void {
    $process = public_listener_process(['10.44.0.3:8095:8080/tcp']);
    $process->owner->lan_ip = '10.0.0.6';
    expect(fn () => new CustomProxyProcessListener()->resolvePublic($process, new Node(['lan_ip' => '10.0.0.4'])))
        ->toThrow(fn (ResourceOperationException $e) => expect($e->errorCode)->toBe('route.upstream_unresolved'));
});
