<?php

declare(strict_types=1);

use App\Domain\AppDev\DevelopmentServerEndpoint;
use App\Domain\Hibernation\RuntimeHibernation;
use App\Infrastructure\AppDev\DevelopmentCaddyConfigRenderer;
use App\Infrastructure\AppDev\DevelopmentSite;
use App\Infrastructure\Caddy\CaddyGlobalOptions;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;
use Tests\Support\TestToolchain;

it('proxies the reserved development-server path to loopback on a development site', function (): void {
    $configuration = new DevelopmentCaddyConfigRenderer()->render(collect([
        development_server_site('tasks.commander.test', '/home/orbit/apps/tasks'),
    ]));

    $socket = 'php_fastcgi unix//run/php/orbit-app-instance-6.sock';

    expect($configuration)
        ->toContain('https://tasks.commander.test {')
        ->toContain('path /__orbit/vite /__orbit/vite/*')
        ->toContain('reverse_proxy 127.0.0.1:5173')
        ->not->toContain('uri strip_prefix /__orbit/vite')
        ->toContain($socket)
        ->toContain('root /dev/shm/orbit/hibernation')
        ->toContain('try_files /app-instance-6.awake')
        ->toContain('handle @orbit_asleep')
        ->toContain('uri /api/v1/runtime-activations/app-instance/6')
        ->toContain('output file /data/caddy/orbit/hibernation/app-instance-6.log')
        ->toContain('tls_trusted_ca_certs /usr/local/share/ca-certificates/orbit-managed-root-ca.crt')
        ->not->toContain('tls_trust_pool')
        ->not->toContain('not file /dev/shm/orbit/hibernation/app-instance-6.awake')
        ->not->toContain('/etc/caddy/orbit-certificates/app-instance-6/current/root.pem')
        ->not->toContain('reverse_proxy https://');
    expect(mb_strpos($configuration, 'handle @orbit_asleep'))
        ->toBeInt()
        ->toBeLessThan((int) mb_strpos($configuration, 'handle @orbit_vite'))
        ->and(mb_strpos($configuration, 'handle @orbit_vite'))
        ->toBeInt()
        ->toBeLessThan((int) mb_strpos($configuration, $socket));
});

it('shows the Orbit wake page before Caddy proxies a sleeping site', function (): void {
    $configuration = new DevelopmentCaddyConfigRenderer()->render(collect([
        development_server_site('tasks.commander.test', '/home/orbit/apps/tasks'),
    ]));

    expect($configuration)
        ->toContain("not file {\n        root /dev/shm/orbit/hibernation\n        try_files /app-instance-6.awake")
        ->toContain('handle @orbit_asleep')
        ->toContain('forward_auth https://gateway.orbit')
        ->toContain('tls_trusted_ca_certs /usr/local/share/ca-certificates/orbit-managed-root-ca.crt')
        ->not->toContain('tls_trust_pool')
        ->not->toContain('not file /dev/shm/orbit/hibernation/app-instance-6.awake');
    expect(mb_strpos($configuration, 'handle @orbit_asleep'))
        ->toBeInt()
        ->toBeLessThan((int) mb_strpos($configuration, 'handle @orbit_vite'));
});

it('adapts hibernation wake with a nested awake-marker matcher', function (): void {
    $configuration = new DevelopmentCaddyConfigRenderer()->render(collect([
        development_server_site('tasks.commander.test', '/home/orbit/apps/tasks'),
    ]));

    $result = caddy_adapt($configuration);

    expect($result->succeeded())
        ->toBeTrue()
        ->and($result->stdout)
        ->toContain('/dev/shm/orbit/hibernation')
        ->toContain('/app-instance-6.awake')
        ->toContain('/usr/local/share/ca-certificates/orbit-managed-root-ca.crt')
        ->not->toContain('tls_trust_pool');
});

it('keeps two development sites isolated on the same loopback port', function (): void {
    $configuration = new DevelopmentCaddyConfigRenderer()->render(collect([
        development_server_site('alpha.example.test', '/home/orbit/apps/alpha', nodeId: 12, scope: 'app-instance-6'),
        development_server_site('beta.example.test', '/home/orbit/apps/beta', nodeId: 13, scope: 'app-instance-7'),
    ]));

    expect(mb_substr_count($configuration, 'https://alpha.example.test {'))
        ->toBe(1)
        ->and(mb_substr_count($configuration, 'https://beta.example.test {'))
        ->toBe(1)
        ->and(mb_substr_count($configuration, 'reverse_proxy 127.0.0.1:5173'))
        ->toBe(2)
        ->and($configuration)
        ->toContain('root * /home/orbit/apps/alpha/public')
        ->toContain('root * /home/orbit/apps/beta/public')
        ->toContain('try_files /app-instance-6.awake')
        ->toContain('try_files /app-instance-7.awake')
        ->toContain('tls_trusted_ca_certs /usr/local/share/ca-certificates/orbit-managed-root-ca.crt')
        ->not->toContain('tls_trust_pool')
        ->not->toContain('current/root.pem')
        ->not->toContain('reverse_proxy https://');
});

it('binds a public Ingress proxy without an https prefix and preserves forwarded identity', function (): void {
    $configuration = new DevelopmentCaddyConfigRenderer()->render(collect([
        new DevelopmentSite(
            nodeId: 3,
            nodeAddress: '10.44.0.3',
            scope: 'route-9-ingress',
            checkoutPath: '',
            documentRoot: '',
            phpVersion: null,
            domain: 'shop.example.test',
            upstreamAddresses: ['10.10.0.20'],
            certificateScope: 'route-9-ingress',
            publicListener: true,
            preserveForwardedIdentity: true,
        ),
    ]));

    expect($configuration)
        ->toContain("shop.example.test {\n    bind 0.0.0.0\n    tls force_automate\n")
        ->toContain('reverse_proxy https://10.10.0.20')
        ->toContain('header_up Host shop.example.test')
        ->toContain('header_up X-Forwarded-Proto https')
        ->toContain('header_up X-Forwarded-For {remote_host}')
        ->toContain('header_up X-Forwarded-Host shop.example.test')
        ->toContain('tls_trusted_ca_certs /usr/local/share/ca-certificates/orbit-managed-root-ca.crt')
        ->not->toContain('https://shop.example.test {')
        ->not->toContain('cert.pem')
        ->not->toContain('key.pem');
});

it('automates the public Ingress certificate alone under the Node-wide certificate default', function (): void {
    $sites = new DevelopmentCaddyConfigRenderer()->render(collect([
        new DevelopmentSite(
            nodeId: 3,
            nodeAddress: '10.44.0.3',
            scope: 'route-9-ingress',
            checkoutPath: '',
            documentRoot: '',
            phpVersion: null,
            domain: 'shop.example.com',
            upstreamAddresses: ['10.10.0.20'],
            certificateScope: 'route-9-ingress',
            publicListener: true,
            preserveForwardedIdentity: true,
        ),
        new DevelopmentSite(
            nodeId: 3,
            nodeAddress: '10.44.0.3',
            scope: 'route-10',
            checkoutPath: '',
            documentRoot: '',
            phpVersion: null,
            domain: 'shop.example.org',
            upstreamAddresses: ['10.10.0.20'],
            certificateScope: 'route-10',
        ),
    ]));

    $adapted = caddy_adapt(CaddyGlobalOptions::render().$sites);
    expect($adapted->succeeded())->toBeTrue();

    /** @var array{apps: array{http: array{servers: array<string, array{automatic_https?: array{disable_certificates?: bool}}>}, tls: array{certificates: array{automate?: list<string>}}}} $json */
    $json = json_decode($adapted->stdout, true, flags: JSON_THROW_ON_ERROR);
    $server = array_values($json['apps']['http']['servers'])[0];

    // Caddy skips automatic HTTPS certificates for the whole server, and manages only the names on
    // the automate loader. A private site with a public-looking name never reaches a public CA.
    expect($server['automatic_https']['disable_certificates'] ?? false)->toBeTrue()
        ->and($json['apps']['tls']['certificates']['automate'] ?? [])->toBe(['shop.example.com']);
});

it('measures a development site without waking it or counting the request as activity', function (): void {
    $configuration = new DevelopmentCaddyConfigRenderer()->render(collect([
        new DevelopmentSite(
            nodeId: 3,
            nodeAddress: '10.44.0.7',
            scope: 'app-instance-28',
            checkoutPath: '/fast/apps/dlf/best-practices',
            documentRoot: 'public',
            phpVersion: '8.5',
            domain: 'best-practices.dlf.test',
        ),
    ]));

    // Two rules, and losing either one breaks hibernation silently: a probe that reaches
    // forward_auth starts the Processes the sweep halted, and a probe that reaches the access
    // log keeps resetting the mtime the sweep reads as activity.
    expect($configuration)
        ->toContain('@orbit_probe header '.RuntimeHibernation::ProbeHeader.' 1')
        ->toContain('log_skip @orbit_probe')
        ->toContain('not header '.RuntimeHibernation::ProbeHeader.' 1');

    $asleep = Str::between($configuration, '@orbit_asleep {', '}');

    expect($asleep)->toContain('not header '.RuntimeHibernation::ProbeHeader.' 1');
});

it('does not attach the development-server handle to production, proxy, or unavailable sites', function (): void {
    $production = new DevelopmentSite(
        nodeId: 1,
        nodeAddress: '10.44.0.10',
        scope: 'app-instance-1',
        checkoutPath: '/var/www/acme/current',
        documentRoot: 'public',
        phpVersion: '8.5',
        domain: 'acme.example.test',
        environment: 'production',
        productionPhpSocket: '/run/php/orbit-acme.sock',
    );
    $proxy = new DevelopmentSite(
        nodeId: 9,
        nodeAddress: '10.44.0.7',
        scope: 'route-4-router',
        checkoutPath: '',
        documentRoot: '',
        phpVersion: null,
        domain: 'tasks.commander.test',
        upstreamAddresses: ['10.44.0.10'],
    );
    $unavailable = new DevelopmentSite(
        nodeId: 9,
        nodeAddress: '10.44.0.7',
        scope: 'route-4-router',
        checkoutPath: '',
        documentRoot: '',
        phpVersion: null,
        domain: 'gone.commander.test',
        unavailable: true,
    );

    $renderer = new DevelopmentCaddyConfigRenderer;
    $productionConfig = $renderer->render(new Collection([$production]));
    $proxyConfig = $renderer->render(new Collection([$proxy]));
    $unavailableConfig = $renderer->render(new Collection([$unavailable]));

    expect($productionConfig)
        ->not->toContain('forward_auth')
        ->not->toContain('orbit_asleep');
    expect($proxyConfig)
        ->not->toContain('forward_auth');
    expect($unavailableConfig)
        ->not->toContain('forward_auth');

    expect($productionConfig)
        ->not->toContain('/__orbit/vite')
        ->not->toContain('reverse_proxy 127.0.0.1:5173')
        ->and($proxyConfig)
        ->toContain('reverse_proxy https://10.44.0.10')
        ->not->toContain('reverse_proxy 127.0.0.1:5173')
        ->not->toContain('/__orbit/vite')
        ->and($unavailableConfig)
        ->toContain('Orbit Route unavailable')
        ->not->toContain('/__orbit/vite')
        ->not->toContain('reverse_proxy');
});

it('publishes a production pool with round-robin, no replay, a 10s cooldown, and 503 on connection failure', function (): void {
    $configuration = new DevelopmentCaddyConfigRenderer()->render(collect([
        new DevelopmentSite(
            nodeId: 9,
            nodeAddress: '10.44.0.7',
            scope: 'route-4-router',
            checkoutPath: '',
            documentRoot: '',
            phpVersion: null,
            domain: 'pool.example.test',
            upstreamAddresses: ['10.10.0.61', '10.10.0.62'],
        ),
    ]));

    expect($configuration)
        ->toContain('reverse_proxy https://10.10.0.61 https://10.10.0.62')
        ->toContain('lb_policy round_robin')
        ->toContain('lb_retries 0')
        ->toContain('fail_duration 10s')
        ->toContain('tls_server_name pool.example.test')
        ->toContain('tls_trusted_ca_certs /usr/local/share/ca-certificates/orbit-managed-root-ca.crt')
        ->toContain('@orbit_unavailable `{err.status_code} == 502`')
        ->toContain('respond "Orbit Route unavailable\n" 503')
        ->toContain('https://pool.example.test {')
        ->not->toContain('least_conn')
        ->not->toContain('{err.status_code} == 500');
});

it('composes a Router-local unix upstream with a remote HTTPS target without a self-proxy loop', function (): void {
    $configuration = new DevelopmentCaddyConfigRenderer()->render(collect([
        new DevelopmentSite(
            nodeId: 9,
            nodeAddress: '10.44.0.7',
            scope: 'route-4-router',
            checkoutPath: '/var/www/acme/current',
            documentRoot: 'public',
            phpVersion: '8.5',
            domain: 'pool.example.test',
            upstreamAddresses: ['10.10.0.62'],
            environment: 'production',
            productionUser: 'orbit-acme',
            productionHome: '/var/www/acme',
            projectSlug: 'acme',
            productionPhpSocket: '/run/php/orbit-acme.sock',
            localUnixUpstream: 'unix//run/orbit/route-4-local.sock',
        ),
    ]));

    expect($configuration)
        ->toContain('http://unix//run/orbit/route-4-local.sock {')
        ->toContain('bind unix//run/orbit/route-4-local.sock')
        ->toContain('reverse_proxy unix//run/orbit/route-4-local.sock https://10.10.0.62')
        ->toContain('lb_policy round_robin')
        ->toContain('lb_retries 0')
        ->toContain('fail_duration 10s')
        ->not->toContain('https://unix/')
        ->not->toContain('reverse_proxy https://127.0.0.1')
        ->not->toContain('https://10.44.0.7');
});

it('does not fall back to port 5173 when a development site has no assigned Vite port', function (): void {
    $configuration = new DevelopmentCaddyConfigRenderer()->render(collect([
        development_server_site('tasks.commander.test', '/home/orbit/apps/tasks', vitePort: null),
    ]));

    expect($configuration)
        ->not->toContain('reverse_proxy 127.0.0.1:5173')
        ->not->toContain('/__orbit/vite')
        ->not->toContain('uri strip_prefix');
});

it('exposes one origin URL and loopback upstream for frontend configuration', function (): void {
    expect(DevelopmentServerEndpoint::origin('tasks.commander.test'))
        ->toBe('https://tasks.commander.test/__orbit/vite')
        ->and(DevelopmentServerEndpoint::upstream(5173))
        ->toBe('127.0.0.1:5173')
        ->and(DevelopmentServerEndpoint::PATH)
        ->toBe('/__orbit/vite');
});

function development_server_site(
    string $domain,
    string $checkoutPath,
    int $nodeId = 12,
    string $scope = 'app-instance-6',
    ?int $vitePort = 5173,
): DevelopmentSite {
    return new DevelopmentSite(
        nodeId: $nodeId,
        nodeAddress: '10.44.0.10',
        scope: $scope,
        checkoutPath: $checkoutPath,
        documentRoot: 'public',
        phpVersion: '8.5',
        domain: $domain,
        vitePort: $vitePort,
    );
}

it('proxies a local HTTP custom proxy site without Host rewrite or an https hop', function (): void {
    $configuration = new DevelopmentCaddyConfigRenderer()->render(collect([
        new DevelopmentSite(
            nodeId: 4,
            nodeAddress: '10.44.0.4',
            scope: 'route-11',
            checkoutPath: '',
            documentRoot: '',
            phpVersion: null,
            domain: 'executor.orbit',
            certificateScope: 'route-11',
            localHttpUpstream: '127.0.0.1:4788',
        ),
    ]));

    expect($configuration)
        ->toContain('https://executor.orbit {')
        ->toContain('reverse_proxy 127.0.0.1:4788 {')
        ->toContain('flush_interval -1')
        ->not->toContain('header_up Host')
        ->not->toContain('reverse_proxy https://')
        ->not->toContain('tls_server_name')
        ->not->toContain('tls_trusted_ca_certs');
});

it('keeps assigned Vite endpoints separate and preserves their base path', function (): void {
    $sites = collect([
        new DevelopmentSite(nodeId: 1, nodeAddress: '10.44.0.2', scope: 'app-instance-10', checkoutPath: '/apps/first', documentRoot: 'public', phpVersion: null, domain: 'first.test', vitePort: 5174),
        new DevelopmentSite(nodeId: 1, nodeAddress: '10.44.0.2', scope: 'app-instance-11', checkoutPath: '/apps/second', documentRoot: 'public', phpVersion: null, domain: 'second.test', vitePort: 5210),
    ]);
    $config = new DevelopmentCaddyConfigRenderer()->render($sites);
    expect($config)->toContain('reverse_proxy 127.0.0.1:5174')->toContain('reverse_proxy 127.0.0.1:5210')->not->toContain('uri strip_prefix');
    expect(caddy_adapt($config)->succeeded())->toBeTrue();
});

describe('analytics tracking site', function (): void {
    it('proxies only the script and event paths on a Router behind a separate Ingress', function (): void {
        $configuration = new DevelopmentCaddyConfigRenderer()->render(collect([
            new DevelopmentSite(
                nodeId: 3,
                nodeAddress: '10.45.0.20',
                scope: 'route-91-router',
                checkoutPath: '',
                documentRoot: '',
                phpVersion: null,
                domain: 'analytics.shop.example.com',
                analyticsUpstream: '10.44.0.40:8000',
                analyticsTrustedProxies: ['10.10.0.30', '10.44.0.30'],
            ),
        ]));

        expect($configuration)->toBe(<<<'CADDY'
            https://analytics.shop.example.com {
                bind 0.0.0.0
                tls /etc/caddy/orbit-certificates/route-91-router/current/cert.pem /etc/caddy/orbit-certificates/route-91-router/current/key.pem
                handle /js/* {
                    reverse_proxy http://10.44.0.40:8000 {
                        trusted_proxies 10.10.0.30 10.44.0.30
                    }
                }
                handle /api/event {
                    reverse_proxy http://10.44.0.40:8000 {
                        trusted_proxies 10.10.0.30 10.44.0.30
                    }
                }
                respond 404
            }

            CADDY);

        $adapted = caddy_adapt($configuration);

        expect($adapted->succeeded())->toBeTrue()
            ->and($adapted->stdout)
            ->toContain('"/js/*"')
            ->toContain('"/api/event"')
            ->toContain('"status_code":404')
            ->toContain('"trusted_proxies":["10.10.0.30","10.44.0.30"]');
    });

    it('serves the public listener itself when the Router is also the Ingress', function (): void {
        $configuration = new DevelopmentCaddyConfigRenderer()->render(collect([
            new DevelopmentSite(
                nodeId: 3,
                nodeAddress: '10.45.0.20',
                scope: 'route-91-ingress',
                checkoutPath: '',
                documentRoot: '',
                phpVersion: null,
                domain: 'analytics.shop.example.com',
                certificateScope: 'route-91-ingress',
                publicListener: true,
                analyticsUpstream: '10.44.0.40:8000',
            ),
        ]));

        expect($configuration)->toBe(<<<'CADDY'
            analytics.shop.example.com {
                bind 0.0.0.0
                tls force_automate
                handle /js/* {
                    reverse_proxy http://10.44.0.40:8000
                }
                handle /api/event {
                    reverse_proxy http://10.44.0.40:8000
                }
                respond 404
            }

            CADDY)
            ->and(caddy_adapt($configuration)->succeeded())->toBeTrue();
    });
});

it('routes only the selected Pusher paths before the existing application handler in real Caddy', function (bool $php): void {
    $site = new DevelopmentSite(
        nodeId: 4, nodeAddress: '10.44.0.4', scope: 'app-instance-72',
        checkoutPath: $php ? '/home/orbit-mealou/current' : '', documentRoot: $php ? 'public' : '', phpVersion: $php ? '8.5' : null, domain: 'mealou.app',
        upstreamAddresses: $php ? [] : ['10.44.0.20:443', '10.44.0.21:443'], environment: 'production', publicListener: true,
        websocketKey: 'mealou-key', websocketAppId: 'mealou-id',
    );
    $configuration = new DevelopmentCaddyConfigRenderer()->render(collect([$site]));
    $adapted = caddy_adapt(CaddyGlobalOptions::render().$configuration);
    expect($adapted->exitCode)->toBe(0, $adapted->stderr);
    expect($configuration)->not->toContain('@orbit_path_proxy', 'strip_prefix', '/powersync', 'Orbit path proxy unavailable');
    $json = json_decode($adapted->stdout, true, flags: JSON_THROW_ON_ERROR);
    expect($adapted->stdout)->toContain('reverb.orbit:443')->toContain('reverb.orbit')
        ->toContain(DevelopmentCaddyConfigRenderer::ORBIT_ROOT_CA_PATH)->not->toContain('insecure_skip_verify');

    // Keep Caddy's adapted route ordering and matchers. Replace only the destinations with
    // distinct responses, so this local test needs neither fleet certificates nor a Reverb server.
    $replace = function (array $value) use (&$replace): array {
        if (($value['handler'] ?? null) === 'file_server') {
            return ['handler' => 'static_response', 'status_code' => 200, 'body' => 'application'];
        }
        if (($value['handler'] ?? null) === 'reverse_proxy') {
            return ['handler' => 'static_response', 'status_code' => 200,
                'body' => $value['upstreams'][0]['dial'] === 'reverb.orbit:443' ? 'reverb' : 'application'];
        }
        foreach ($value as $key => $part) {
            if ($key === 'encodings') {
                $value[$key] = array_map(static fn (array $encoding): object => (object) $encoding, $part);
            } elseif (is_array($part)) {
                $value[$key] = $replace($part);
            }
        }

        return $value;
    };
    $json = $replace($json);
    $listener = stream_socket_server('tcp://127.0.0.1:0', $error, $message);
    expect($listener)->toBeResource();
    $address = stream_socket_get_name($listener, false);
    fclose($listener);
    $server = array_key_first($json['apps']['http']['servers']);
    $json['admin'] = ['disabled' => true];
    $json['apps']['http']['servers'][$server]['listen'] = [$address];
    $json['apps']['http']['servers'][$server]['automatic_https'] = ['disable' => true];
    unset($json['logging'], $json['apps']['tls'], $json['apps']['http']['servers'][$server]['tls_connection_policies'], $json['apps']['http']['servers'][$server]['logs']);
    $path = tempnam(sys_get_temp_dir(), 'orbit-public-reverb-');
    file_put_contents($path, json_encode($json, JSON_THROW_ON_ERROR));
    $process = new Process([TestToolchain::require('caddy', 'install caddy'), 'run', '--config', $path]);
    try {
        $process->start();
        $process->waitUntil(static fn (string $type, string $output): bool => str_contains($output, 'serving initial configuration'));
        $signed = '?auth_key=mealou-key&auth_signature=signature&auth_timestamp=123&auth_version=1.0';
        foreach ([
            ['GET', '/app/mealou-key?protocol=7', true, 'reverb'],
            ['GET', '/app/mealou%2Dkey?protocol=7', true, 'reverb'],
            ['GET', '/app/other-key/../mealou-key', true, '404'],
            ['GET', '/app/other-key/%2e%2e/mealou-key', true, '404'],
            ['GET', '/app/other-key%2f..%2fmealou-key', true, '404'],
            ['GET', '//app/mealou-key', true, '404'],
            ['GET', '/APP/mealou-key', true, '404'],
            ['GET', '/app/MEALOU-KEY', true, '404'],
            ['GET', '/app/mealou-key', false, '404'],
            ['POST', '/app/mealou-key', true, '404'],
            ['GET', '/app/hauzer-key', true, '404'],
            ['GET', '/app/mealou-key/more', true, '404'],
            ['POST', '/apps/mealou-id/events'.$signed, false, 'reverb'],
            ['POST', '/apps/other-id/../mealou-id/events'.$signed, false, '404'],
            ['POST', '/apps/other-id/%2e%2e/mealou-id/events'.$signed, false, '404'],
            ['GET', '/apps/other-id/../mealou-id/channels'.$signed, false, '404'],
            ['GET', '/apps/other-id/%2e%2e/mealou-id/channels'.$signed, false, '404'],
            ['POST', '/apps/mealou-id/events'.$signed.'&auth_key=other-key', false, '404'],
            ['POST', '/apps/mealou-id/events?auth_key=other-key&'.substr($signed, 1), false, '404'],
            ['POST', '/apps/mealou-id/events'.$signed.'&auth_key=mealou-key', false, '404'],
            ['GET', '/apps/mealou-id/channels'.$signed.'&auth_key=other-key', false, '404'],
            ['GET', '/apps/mealou-id/channels?auth_key=other-key&'.substr($signed, 1), false, '404'],
            ['GET', '/apps/mealou-id/channels'.$signed.'&auth_key=mealou-key', false, '404'],
            ['POST', '/apps/mealou-id/batch_events'.$signed, false, 'reverb'],
            ['GET', '/apps/mealou-id/channels/private-room/users'.$signed, false, 'reverb'],
            ['POST', '/apps/mealou-id/users/123/terminate_connections'.$signed, false, 'reverb'],
            ['POST', '/apps/hauzer-id/events'.$signed, false, '404'],
            ['POST', '/apps/mealou-id/events'.str_replace('mealou-key', 'hauzer-key', $signed), false, '404'],
            ['POST', '/apps/mealou-id/events', false, '404'],
            ['GET', '/apps/mealou-id/events'.$signed, false, '404'],
            ['POST', '/apps/mealou-id/internal'.$signed, false, '404'],
            ['GET', '/apps', false, '404'],
            ['GET', '/app', false, '404'],
            ['GET', '/internal/registry', false, 'application'],
            ['GET', '/dashboard', false, 'application'],
            ['GET', '/powersync/sync/stream', false, 'application'],
            ['POST', '/powersync/write-checkpoint2.json', false, 'application'],
            ['POST', '/broadcasting/auth', false, 'application'],
        ] as [$method, $uri, $upgrade, $expected]) {
            $socket = stream_socket_client('tcp://'.$address, $error, $message, 3);
            expect($socket)->toBeResource($process->getErrorOutput());
            stream_set_timeout($socket, 3);
            fwrite($socket, $method.' '.$uri." HTTP/1.1\r\nHost: mealou.app\r\nConnection: ".($upgrade ? 'keep-alive, Upgrade' : 'close')."\r\n".($upgrade ? "Upgrade: websocket\r\n" : '')."Content-Length: 0\r\n\r\n");
            $response = '';
            while (! str_contains($response, "\r\n\r\n")) {
                $line = fgets($socket);
                if ($line === false) {
                    break;
                }
                $response .= $line;
            }
            if (preg_match('/Content-Length: (\d+)/i', $response, $length) === 1 && (int) $length[1] > 0) {
                $response .= fread($socket, (int) $length[1]);
            }
            fclose($socket);
            expect($response)->toContain($expected);
        }
    } finally {
        $process->stop();
        unlink($path);
    }
})->with(['proxy pool' => false, 'production PHP' => true]);
