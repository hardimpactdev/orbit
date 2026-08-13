<?php

declare(strict_types=1);

use App\Data\RemoteShell\RemoteShellResult;
use App\Services\Proxy\ProxyRouteFileProbeContract;

it('keeps a verified missing route distinct from an unavailable observation', function (): void {
    $contract = new ProxyRouteFileProbeContract;

    $missing = $contract->parse(new RemoteShellResult(
        exitCode: 0,
        stdout: "observed\t0\t\t\t\t0\t0\t\t\t0\t\n",
        stderr: '',
        durationMs: 1,
    ));
    $unavailable = $contract->parse(new RemoteShellResult(
        exitCode: 0,
        stdout: "runtime_unavailable\t\t\t\t\t\t\t\t\t\t\n",
        stderr: '',
        durationMs: 1,
    ));

    expect($missing)
        ->toMatchArray([
            'route_probe_ok' => true,
            'route_probe_status' => 'observed',
            'route_exists' => false,
        ])
        ->and($unavailable)
        ->toMatchArray([
            'route_probe_ok' => false,
            'route_probe_status' => 'runtime_unavailable',
        ])
        ->and($unavailable['route_probe_error'] ?? null)
        ->toBeString();
});

it('marks malformed route evidence as unavailable', function (): void {
    $observation = new ProxyRouteFileProbeContract()->parse(new RemoteShellResult(
        exitCode: 0,
        stdout: "not-a-route-observation\n",
        stderr: '',
        durationMs: 1,
    ));

    expect($observation)
        ->toMatchArray([
            'route_probe_ok' => false,
            'route_probe_status' => 'invalid_reply',
        ])
        ->and($observation['route_probe_error'] ?? null)
        ->toBe('Proxy route probe returned an invalid reply.');
});

it('preserves valid route reality fields', function (): void {
    $observation = new ProxyRouteFileProbeContract()->parse(new RemoteShellResult(
        exitCode: 0,
        stdout: "observed\t1\tabc123\t/etc/caddy/cert.crt\t/etc/caddy/cert.key\t1\t1\t0\t"
        .base64_encode('connection refused')
        ."\t1\t"
        .base64_encode('certificate')
        ."\n",
        stderr: '',
        durationMs: 1,
    ));

    expect($observation)->toMatchArray([
        'route_probe_ok' => true,
        'route_probe_status' => 'observed',
        'route_exists' => true,
        'route_hash' => 'abc123',
        'cert_path' => '/etc/caddy/cert.crt',
        'key_path' => '/etc/caddy/cert.key',
        'cert_exists' => true,
        'key_exists' => true,
        'cert_validity_observed' => true,
        'runtime_upstream_reachable' => false,
        'runtime_probe_error' => 'connection refused',
    ]);
});

it('renders an explicit runtime-unavailable state instead of a missing route', function (): void {
    $script = new ProxyRouteFileProbeContract()->render(
        domain: 'docs.example.test',
        routeSuffix: '',
        runtimeUpstream: null,
    );

    expect($script)->toContain('runtime_unavailable');
    expect($script)->toContain('observed');
    expect($script)->toContain('ORBIT_PROXY_DOMAIN');
    expect(str_contains($script, "printf '0\\t\\t\\t\\t0\\t0\\t\\t0\\t\\n'"))->toBeFalse();
});
