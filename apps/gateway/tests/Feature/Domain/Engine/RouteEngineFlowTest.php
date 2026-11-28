<?php

declare(strict_types=1);

use App\Domain\Engine\EngineContent;
use App\Domain\Engine\EngineKinds;
use App\Domain\Engine\EngineRun;
use App\Domain\Engine\EngineUnsupported;
use App\Domain\Engine\MachineBus;
use App\Domain\Executor\SignedExecutorOrder;
use App\Domain\Routes\RouteEngineFlow;
use App\Domain\Routes\RouteHostSteps;
use App\Domain\Routes\RouteWebRootCommands;
use App\Models\Instance;
use App\Models\Operation;
use App\Models\OperationEngineStep;

/** The Caddy access rows RouteWebRootCommands admits for one Laravel checkout on Node 3. */
function routeAccess(): array
{
    return RouteWebRootCommands::access(3, ['user' => 'orbit', 'sites' => [
        ['checkout' => '/home/orbit/apps/shop', 'relative_root' => 'public', 'application' => '/home/orbit/apps/shop'],
    ]], (new Instance)->forceFill(['source_is_laravel' => true]));
}

/** An admitted route_create plan, in the shape NativeRouteCreatePlan builds, for a dev Route on Node 3 without Caddy access rows. */
function routePlan(array $overrides = []): array
{
    return [...[
        'route_id' => 9, 'domain' => 'shop.orbit', 'lan' => [], 'ingress_ports' => [], 'acl' => [],
        'links' => [['node_id' => 3, 'path' => '/home/orbit/apps/shop/current', 'target' => 'releases/1', 'owner' => 'orbit']],
        'php_files' => [['node_id' => 3, 'path' => '/etc/php/8.4/fpm/pool.d/orbit-scopes.conf',
            'content' => "[shop]\ngroup = __ORBIT_NODE_GROUP__\nchdir = /__ORBIT_NODE_HOME__/apps/shop\n", 'owner' => 'root']],
        'php_tests' => [['node_id' => 3, 'argv' => ['/usr/sbin/php-fpm8.4', '-t']]],
        'php_reloads' => [['node_id' => 3, 'unit' => 'php8.4-fpm.service']],
        'leaves' => [['node_id' => 3, 'scope' => 'app-instance-7', 'domain' => 'shop.orbit', 'root_hash' => str_repeat('a', 64),
            'directory' => '/etc/caddy/orbit-certificates/app-instance-7/versions/0123456789abcdef',
            'current' => '/etc/caddy/orbit-certificates/app-instance-7/current', 'target' => 'versions/0123456789abcdef', 'user' => 'orbit']],
        'caddy' => [['node_id' => 3, 'content' => "shop.orbit {\n}\n"]],
        'url_lines' => [['node_id' => 3, 'path' => '/home/orbit/apps/shop/.env', 'line' => 'APP_URL=https://shop.orbit', 'owner' => 'orbit']],
        'env_files' => [], 'probes' => [['node_id' => 3, 'address' => '10.44.0.2', 'domain' => 'shop.orbit']], 'ingress' => [], 'dns' => [], 'awake' => [],
        'leaf_indexes' => [0], 'trust' => [3], 'root_certificate' => "-----BEGIN CERTIFICATE-----\nROOT\n-----END CERTIFICATE-----\n",
        'site_nodes' => [3], 'nodes' => ['3' => ['account' => 'orbit']], 'accounts' => [3 => ['group' => 'orbit', 'home' => '/home/orbit']],
        'guards' => [3 => ['caddy' => str_repeat('c', 64), 'php:8.4' => str_repeat('p', 64)], 1 => ['records' => str_repeat('r', 64), 'catalog' => str_repeat('k', 64)]],
    ], ...$overrides];
}

function routeOperation(array $plan): Operation
{
    return (new Operation)->forceFill(['id' => 41, 'kind' => 'route.create', 'input' => ['route_create' => $plan]]);
}

/** The signed reply of sign-0: the leaf, then the root. */
function routeRun(): EngineRun
{
    return new EngineRun(41, ['sign-0' => (new OperationEngineStep)->forceFill(['name' => 'sign-0', 'status' => 'done',
        'output' => "-----BEGIN CERTIFICATE-----\nLEAF\n-----END CERTIFICATE-----\n-----BEGIN CERTIFICATE-----\nROOT\n-----END CERTIFICATE-----\n"])], 0, null);
}

it('runs a dev Route as a request, a Gateway signature, the Node\'s host steps in route.publish_site\'s order and a Gateway probe', function (): void {
    $steps = app(RouteEngineFlow::class)->steps(routeOperation(routePlan()));

    expect(array_map(static fn ($step): array => [$step->name, $step->node, $step->action, $step->after], $steps))->toBe([
        ['csr-0', 3, 'tls.ensure_csr', []],
        ['sign-0', 0, 'certificate.sign', ['csr-0']],
        ['site-3-url-0', 3, 'dotenv.ensure', ['sign-0']],
        ['site-3-link-0', 3, 'file.ensure', ['site-3-url-0']],
        ['site-3-pools', 3, 'config.apply', ['site-3-link-0']],
        ['site-3-unit-0', 3, 'systemd.unit.ensure', ['site-3-pools']],
        ['site-3-reload', 3, 'config.apply', ['site-3-unit-0']],
        ['site-3-cert-0', 3, 'file.ensure', ['site-3-reload']],
        ['site-3-current-0', 3, 'file.ensure', ['site-3-cert-0']],
        ['site-3-folder-0', 3, 'file.ensure', ['site-3-current-0']],
        ['site-3-key-0', 3, 'file.ensure', ['site-3-folder-0']],
        ['site-3-copy-0', 3, 'file.ensure', ['site-3-key-0']],
        ['site-3-copylink-0', 3, 'file.ensure', ['site-3-copy-0']],
        ['site-3-trust', 3, 'ca.trust.ensure', ['site-3-copylink-0']],
        ['site-3-caddy', 3, 'config.apply', ['site-3-trust']],
        ['probe-0', 0, 'route.probe', ['site-3-caddy']],
    ])
        ->and(array_values(array_filter(array_map(static fn ($step): ?array => $step->features === [] ? null : [$step->name, $step->features], $steps))))
        ->toBe([['csr-0', ['tls.csr_input']]]);
});

it('asks for the request with its folder owner, modes and subject, only from a Node that reports that input', function (): void {
    expect(RouteEngineFlow::csrInput(['directory' => '/etc/caddy/orbit-certificates/app-instance-7/versions/0123456789abcdef', 'domain' => 'shop.orbit']))->toBe([
        'directory' => '/etc/caddy/orbit-certificates/app-instance-7/versions/0123456789abcdef',
        'key' => '/etc/caddy/orbit-certificates/app-instance-7/versions/0123456789abcdef/key.pem', 'user' => 'root', 'group' => 'caddy',
        'directory_mode' => '0750', 'key_mode' => '0640', 'subject' => '/CN=Orbit', 'domain' => 'shop.orbit',
    ]);
});

it('sends APP_URL, links, the leaf, the account\'s copies and the root trust with every path, owner and mode', function (): void {
    $flow = app(RouteEngineFlow::class);
    $input = static fn (string $step): array => $flow->input(routeOperation(routePlan()), $step, routeRun());
    $versions = '/etc/caddy/orbit-certificates/app-instance-7/versions/0123456789abcdef';
    $copy = '/home/orbit/.orbit/certificates/app-instance-7';
    $leaf = ['blob' => ['content' => "-----BEGIN CERTIFICATE-----\nLEAF\n-----END CERTIFICATE-----\n"]];

    expect($input('site-3-url-0'))->toBe(['path' => '/home/orbit/apps/shop/.env', 'user' => 'orbit', 'values' => [['key' => 'APP_URL', 'value' => 'https://shop.orbit']]])
        ->and($input('site-3-link-0'))->toBe(['state' => 'link', 'path' => '/home/orbit/apps/shop/current', 'target' => 'releases/1', 'user' => 'orbit', 'group' => 'orbit'])
        ->and($input('site-3-cert-0'))->toBe(['state' => 'file', 'path' => $versions.'/cert.pem', 'content' => $leaf, 'owner' => ['user' => 'root', 'group' => 'caddy', 'mode' => '0644']])
        ->and($input('site-3-current-0'))->toBe(['state' => 'link', 'path' => '/etc/caddy/orbit-certificates/app-instance-7/current', 'target' => 'versions/0123456789abcdef',
            'user' => 'root', 'group' => 'root'])
        ->and($input('site-3-folder-0'))->toBe(['state' => 'directory', 'path' => $copy.'/versions/0123456789abcdef', 'owner' => ['user' => 'root', 'group' => 'root', 'mode' => '0755']])
        ->and($input('site-3-key-0'))->toBe(['state' => 'file', 'path' => $copy.'/versions/0123456789abcdef/key.pem', 'source_path' => $versions.'/key.pem',
            'owner' => ['user' => 'orbit', 'group' => 'orbit', 'mode' => '0600']])
        ->and($input('site-3-copy-0'))->toBe(['state' => 'file', 'path' => $copy.'/versions/0123456789abcdef/cert.pem', 'content' => $leaf,
            'owner' => ['user' => 'orbit', 'group' => 'orbit', 'mode' => '0600']])
        ->and($input('site-3-copylink-0'))->toBe(['state' => 'link', 'path' => $copy.'/current', 'target' => 'versions/0123456789abcdef', 'user' => 'orbit', 'group' => 'orbit'])
        ->and($input('site-3-trust'))->toBe(['state' => 'present', 'name' => 'orbit-managed-root-ca.crt', 'certificate' => ['blob' => ['content' => routePlan()['root_certificate']]],
            'owner' => ['user' => 'root', 'group' => 'root', 'mode' => '0644']]);
});

it('renders the pools whole under the publication lock, starts each unit, then reloads it, and applies the Caddyfile from its rendered digest', function (): void {
    $flow = app(RouteEngineFlow::class);
    $input = static fn (string $step): array => $flow->input(routeOperation(routePlan()), $step, routeRun());
    $pool = ['path' => '/etc/php/8.4/fpm/pool.d/orbit-scopes.conf', 'content' => ['blob' => ['content' => "[shop]\ngroup = orbit\nchdir = /home/orbit/apps/shop\n"]],
        'from_sha256' => str_repeat('p', 64), 'owner' => ['user' => 'root', 'group' => 'root', 'mode' => '0644']];
    $base = ['lock' => '/etc/caddy/.orbit-publish.lock', 'recovery_dir' => '/var/lib/orbit/executor/config-recovery', 'files' => [$pool],
        'validators' => [['kind' => 'php-fpm', 'version' => '8.4']]];

    expect($input('site-3-pools'))->toBe([...$base, 'units' => []])
        ->and($input('site-3-unit-0'))->toBe(['unit' => 'php8.4-fpm.service', 'enabled' => true, 'state' => 'running', 'budget_ms' => 30_000])
        ->and($input('site-3-reload'))->toBe([...$base, 'units' => [['unit' => 'php8.4-fpm.service', 'load' => 'reload']]])
        ->and($input('site-3-caddy'))->toBe(['lock' => '/etc/caddy/.orbit-publish.lock', 'recovery_dir' => '/var/lib/orbit/executor/config-recovery',
            'files' => [['path' => '/etc/caddy/Caddyfile', 'content' => ['blob' => ['content' => "shop.orbit {\n}\n"]], 'from_sha256' => str_repeat('c', 64),
                'owner' => ['user' => 'root', 'group' => 'caddy', 'mode' => '0640']]],
            'validators' => [['kind' => 'caddy', 'config' => '/etc/caddy/Caddyfile', 'user' => 'caddy']], 'units' => [['unit' => 'caddy.service', 'load' => 'reload']]]);
});

it('gives a production pool its own configuration and ini folders, and keeps it only when its test and unit are the derived ones', function (): void {
    $production = ['php_files' => [['node_id' => 3, 'path' => '/etc/orbit/php-fpm/shop/generated/pool.conf', 'content' => "[shop]\n", 'owner' => 'root']],
        'php_tests' => [['node_id' => 3, 'argv' => ['/usr/bin/env', 'PHP_INI_SCAN_DIR=/etc/php/8.4/fpm/conf.d:/etc/orbit/php-fpm/shop/generated',
            '/usr/sbin/php-fpm8.4', '-t', '--fpm-config', '/etc/orbit/php-fpm/shop/generated/php-fpm.conf']]],
        'php_reloads' => [['node_id' => 3, 'unit' => 'orbit-shop-php8.4-fpm.service']]];
    $input = app(RouteEngineFlow::class)->input(routeOperation(routePlan($production)), 'site-3-reload', routeRun());

    expect($input['files'])->toBe([['path' => '/etc/orbit/php-fpm/shop/generated/pool.conf', 'content' => ['blob' => ['content' => "[shop]\n"]],
        'owner' => ['user' => 'root', 'group' => 'root', 'mode' => '0644']]])
        ->and($input['validators'])->toBe([['kind' => 'php-fpm', 'version' => '8.4', 'fpm_config' => '/etc/orbit/php-fpm/shop/generated/php-fpm.conf',
            'ini_scan_dir' => ['/etc/php/8.4/fpm/conf.d', '/etc/orbit/php-fpm/shop/generated']]])
        ->and($input['units'])->toBe([['unit' => 'orbit-shop-php8.4-fpm.service', 'load' => 'reload']]);

    $production['php_tests'][0]['argv'][] = '--extra';
    expect(fn () => app(RouteEngineFlow::class)->steps(routeOperation(routePlan($production))))->toThrow(EngineUnsupported::class);
});

it('keeps each config.apply input byte-stable across runs, because that input is the order\'s identity', function (): void {
    $flow = app(RouteEngineFlow::class);
    $later = new EngineRun(41, ['sign-0' => (new OperationEngineStep)->forceFill(['name' => 'sign-0', 'status' => 'done',
        'output' => "-----BEGIN CERTIFICATE-----\nOTHER\n"])], 99, null);

    foreach (['site-3-pools', 'site-3-reload', 'site-3-caddy'] as $step) {
        expect(json_encode($flow->input(routeOperation(routePlan()), $step, $later)))->toBe(json_encode($flow->input(routeOperation(routePlan()), $step, routeRun())));
    }
});

it('publishes private DNS on the VPN Node under its lock after the signature, beside the site, and probes after the site alone', function (): void {
    $plan = routePlan(['dns' => [['node_id' => 1, 'records' => "address=/.test/10.44.0.7\n", 'catalog' => "{}\n"]]]);
    $steps = app(RouteEngineFlow::class)->steps(routeOperation($plan));
    $dns = array_values(array_filter($steps, static fn ($step): bool => $step->name === 'dns-0'))[0];

    expect([$dns->node, $dns->action, $dns->after])->toBe([1, 'config.apply', ['sign-0']])
        ->and(end($steps)->after)->toBe(['site-3-caddy'])
        ->and(app(RouteEngineFlow::class)->input(routeOperation($plan), 'dns-0', routeRun()))->toBe([
            'lock' => '/run/lock/orbit-dnsmasq.lock', 'recovery_dir' => '/var/lib/orbit/executor/config-recovery', 'files' => [
                ['path' => '/etc/dnsmasq.d/orbit-records.conf', 'content' => ['blob' => ['content' => "address=/.test/10.44.0.7\n"]], 'from_sha256' => str_repeat('r', 64),
                    'owner' => ['user' => 'root', 'group' => 'root', 'mode' => '0644']],
                ['path' => '/var/lib/orbit/private-dns/catalog.json', 'content' => ['blob' => ['content' => "{}\n"]], 'from_sha256' => str_repeat('k', 64),
                    'owner' => ['user' => 'root', 'group' => 'root', 'mode' => '0644']],
            ],
            'validators' => [['kind' => 'dnsmasq', 'conf_file' => '/etc/dnsmasq.conf', 'conf_dir' => '/etc/dnsmasq.d', 'skip_suffixes' => ['.dpkg-dist', '.dpkg-old', '.dpkg-new']]],
            'units' => [['unit' => 'dnsmasq.service', 'load' => 'restart'], ['unit' => 'orbit-private-dns.service', 'load' => 'restart']],
        ]);
});

it('records the chart\'s failed step and main\'s error code for a failed host step', function (string $step, string $code, string $chart, string $recorded): void {
    expect(RouteEngineFlow::chartStep($step))->toBe($chart)
        ->and(RouteEngineFlow::code($step, $code))->toBe($recorded);
})->with([
    'a site write' => ['site-3-cert-0', 'file.ensure_failed', 'Install cert, publish site-3', 'route.publish_failed'],
    'a pool test' => ['site-3-pools', 'config.validate_failed', 'Install cert, publish site-3', 'route.publish_failed'],
    'a pool unit' => ['site-3-unit-0', 'unit.ensure_failed', 'Install cert, publish site-3', 'route.publish_failed'],
    'a trust update' => ['site-3-trust', 'trust.not_converged', 'Install cert, publish site-3', 'route.publish_failed'],
    'a site input' => ['site-3-url-0', 'host.input_invalid', 'Install cert, publish site-3', 'route.input_invalid'],
    'a site blob' => ['site-3-caddy', 'host.content_missing', 'Install cert, publish site-3', 'route.content_missing'],
    'a guarded Caddyfile' => ['site-3-caddy', 'owned_revision_conflict', 'Install cert, publish site-3', 'owned_revision_conflict'],
    'a dnsmasq check' => ['dns-0', 'config.validate_failed', 'Publish private DNS-0', 'dns.check_failed'],
    'a DNS restart' => ['dns-0', 'config.load_failed', 'Publish private DNS-0', 'dns.restart_failed'],
    'a DNS write' => ['dns-0', 'config.write_failed', 'Publish private DNS-0', 'dns.write_failed'],
    'a held DNS lock' => ['dns-0', 'host.lock_busy', 'Publish private DNS-0', 'dns.publish_busy'],
    'a guarded record' => ['dns-0', 'owned_revision_conflict', 'Publish private DNS-0', 'owned_revision_conflict'],
    'a request' => ['csr-0', 'app-dev.certificate_request_failed', 'Make a CSR on the Node-0', 'app-dev.certificate_request_failed'],
    'a signature' => ['sign-1', 'certificate.sign_failed', 'Sign the CSR-1', 'certificate.sign_failed'],
    'a probe' => ['probe-0', 'route.probe_failed', 'Probe the site over TLS-0', 'route.probe_failed'],
]);

it('leaves a Route with Caddy access to Swamp until posix.acl.ensure takes a tree scope', function (): void {
    app(RouteEngineFlow::class)->steps(routeOperation(routePlan(['acl' => routeAccess()])));
})->throws(EngineUnsupported::class, 'tree scope');

it('leaves a Route that needs a part the engine does not run to Swamp', function (string $list, array $row): void {
    app(RouteEngineFlow::class)->steps(routeOperation(routePlan([$list => [$row]])));
})->throws(EngineUnsupported::class)->with([
    'the Ingress edge' => ['ingress', ['node_id' => 6]],
    'Ingress ports' => ['ingress_ports', ['node_id' => 6, 'port' => 443, 'ensure' => 'present']],
    'a LAN rule' => ['lan', ['node_id' => 3]],
    'a production .env' => ['env_files', ['node_id' => 3]],
    'awake markers' => ['awake', ['node_id' => 3]],
    'an access command of another shape' => ['acl', ['node_id' => 3, 'argv' => ['/usr/bin/chmod', '777', '/home/orbit']]],
]);

it('sends route.update\'s Node typed access kinds that rebuild the admitted commands, and its content as blobs', function (): void {
    $input = app(RouteEngineFlow::class)->siteInput(routePlan(['acl' => routeAccess(), 'links' => []]), 3, static fn (): string => routeRun()->output('sign-0') ?? '');

    expect($input['account'])->toBe('orbit')
        ->and(array_column($input['acl'], 'kind'))->toBe(['deny_defaults', 'deny', 'passage', 'grant_defaults', 'grant'])
        ->and($input['acl'][2])->toMatchArray(['user' => null])
        ->and($input['acl'][4]['optional_paths'])->toBe(['/home/orbit/apps/shop/storage/app/public'])
        ->and($input['url_lines'])->toBe([['path' => '/home/orbit/apps/shop/.env', 'url' => 'https://shop.orbit', 'owner' => 'orbit']])
        ->and($input['leaves'])->toBe([['scope' => 'app-instance-7', 'version' => '0123456789abcdef',
            'certificate' => ['blob' => ['content' => "-----BEGIN CERTIFICATE-----\nLEAF\n-----END CERTIFICATE-----\n"]]]]);
});

it('puts step content into the Node\'s content keys and leaves only its hash in the order', function (): void {
    $bus = new class implements MachineBus
    {
        /** @var list<array{int, string}> */
        public array $puts = [];

        public function publishOrder(int $node, SignedExecutorOrder $order, ?Closure $claim = null): void {}

        public function publishStart(int $operation): void {}

        public function scheduleDeadline(int $operation, string $step, int $sends, int $atMs): void {}

        public function nudge(int $operation, string $id): void {}

        public function putContent(int $node, string $content): array
        {
            $this->puts[] = [$node, $content];

            return ['sha256' => hash('sha256', $content)];
        }

        public function nodeActions(int $node): array
        {
            return [];
        }

        public function nodeFeatures(int $node): array
        {
            return [];
        }

        public function nodeTargetActions(int $node): array
        {
            return [];
        }

        public function nodeClock(int $node): ?bool
        {
            return null;
        }
    };

    $input = EngineContent::upload($bus, 3, ['account' => 'orbit', 'caddy' => ['blob' => ['content' => 'site']], 'leaves' => [['certificate' => ['blob' => ['content' => 'pem']]]]]);

    expect($input)->toBe(['account' => 'orbit', 'caddy' => ['blob' => ['sha256' => hash('sha256', 'site')]],
        'leaves' => [['certificate' => ['blob' => ['sha256' => hash('sha256', 'pem')]]]]])
        ->and($bus->puts)->toBe([[3, 'site'], [3, 'pem']]);
});

it('leaves a Route to Swamp when a leaf or account is outside the executor\'s shape', function (array $overrides): void {
    app(RouteEngineFlow::class)->steps(routeOperation(routePlan($overrides)));
})->throws(EngineUnsupported::class)->with([
    'a domain the executor refuses' => [['leaves' => [[...routePlan()['leaves'][0], 'domain' => 'Shop Orbit']]]],
    'an account the executor refuses' => [['nodes' => ['3' => ['account' => 'Root!']]]],
    'a pool without its unit' => [['php_reloads' => []]],
]);

it('runs route.create on the engine only without access rows, on Nodes with the host family and the request input', function (array $plan, array $actions, array $features, bool $engine): void {
    config(['orbit.engine.kinds' => ['route.create']]);
    $bus = Mockery::mock(MachineBus::class);
    $bus->shouldReceive('nodeActions')->andReturn($actions);
    $bus->shouldReceive('nodeFeatures')->andReturn($features);
    $this->app->instance(MachineBus::class, $bus);

    expect(app(EngineKinds::class)->routes(routeOperation(routePlan($plan))))->toBe($engine);
})->with([
    'every condition' => [[], ['tls.ensure_csr', 'dotenv.ensure', 'file.ensure', 'config.apply', 'systemd.unit.ensure', 'ca.trust.ensure'],
        ['tls.csr_input'], true],
    'Caddy access rows' => [['acl' => routeAccess()], ['tls.ensure_csr', 'dotenv.ensure', 'file.ensure', 'config.apply', 'systemd.unit.ensure', 'ca.trust.ensure', 'posix.acl.ensure'],
        ['tls.csr_input'], false],
    'no host family' => [[], ['tls.ensure_csr', 'route.publish_site', 'dns.publish_records'], ['tls.csr_input'], false],
    'no request input' => [[], ['tls.ensure_csr', 'dotenv.ensure', 'file.ensure', 'config.apply', 'systemd.unit.ensure', 'ca.trust.ensure'], [], false],
    'one host primitive missing' => [[], ['tls.ensure_csr', 'dotenv.ensure', 'file.ensure', 'config.apply', 'systemd.unit.ensure'], ['tls.csr_input'], false],
]);

it('refuses a VPN Node without the host family in the kind\'s own admission', function (): void {
    $bus = Mockery::mock(MachineBus::class);
    $bus->shouldReceive('nodeActions')->with(3)->andReturn(['tls.ensure_csr', 'dotenv.ensure', 'file.ensure', 'config.apply', 'systemd.unit.ensure', 'ca.trust.ensure']);
    $bus->shouldReceive('nodeActions')->with(1)->andReturn(['dns.publish_records']);
    $bus->shouldReceive('nodeFeatures')->andReturn(['tls.csr_input']);
    $this->app->instance(MachineBus::class, $bus);

    app(RouteEngineFlow::class)->admit(routeOperation(routePlan(['dns' => [['node_id' => 1, 'records' => "r\n", 'catalog' => "{}\n"]]])));
})->throws(EngineUnsupported::class, 'Node 1 lacks the host family');

it('renders a shared pool with the account\'s group and home in place of the render\'s placeholders', function (): void {
    expect(RouteHostSteps::render("group = __ORBIT_NODE_GROUP__\nchdir = /__ORBIT_NODE_HOME__/app-1\n", 'orbit', '/home/orbit'))
        ->toBe("group = orbit\nchdir = /home/orbit/app-1\n")
        ->and(RouteHostSteps::home('root'))->toBe('/root')
        ->and(RouteHostSteps::home('orbit'))->toBe('/home/orbit');
});
