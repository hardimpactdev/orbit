<?php

declare(strict_types=1);

use App\Domain\Engine\EngineContent;
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
        'site_nodes' => [3], 'nodes' => ['3' => ['account' => 'orbit']],
    ], ...$overrides];
}

function routeOperation(array $plan): Operation
{
    return (new Operation)->forceFill(['id' => 41, 'kind' => 'route.create', 'input' => ['route_create' => $plan]]);
}

/** An account.read answer for a present account, as the executor records it: its primary group shares its name here. */
function routeAccount(string $user, string $home): string
{
    return json_encode(['state' => 'present', 'uid' => 1000, 'gid' => 1000, 'group' => $user, 'home' => $home], JSON_THROW_ON_ERROR);
}

/** A file.read answer for a regular file with this digest, as the executor records it. */
function routeRead(string $sha256): string
{
    return json_encode(['state' => 'present', 'type' => 'file', 'sha256' => $sha256, 'uid' => 0, 'gid' => 0, 'mode' => '0644'], JSON_THROW_ON_ERROR);
}

/** The signed reply of sign-0, the leaf then the root, and the reads of the files the writes replace. */
function routeRun(array $outputs = []): EngineRun
{
    $outputs = [...['sign-0' => "-----BEGIN CERTIFICATE-----\nLEAF\n-----END CERTIFICATE-----\n-----BEGIN CERTIFICATE-----\nROOT\n-----END CERTIFICATE-----\n",
        'site-3-account-0' => routeAccount('orbit', '/home/orbit'),
        'site-3-read-pool-0' => routeRead(str_repeat('1', 64)), 'site-3-read-caddy' => routeRead(str_repeat('c', 64)),
        'dns-0-read-records' => routeRead(str_repeat('2', 64)), 'dns-0-read-catalog' => routeRead(str_repeat('3', 64))], ...$outputs];

    return new EngineRun(41, array_map(static fn (string $name): OperationEngineStep => (new OperationEngineStep)->forceFill(['name' => $name, 'status' => 'done',
        'output' => $outputs[$name]]), array_combine(array_keys($outputs), array_keys($outputs))), 0, null);
}

it('runs a dev Route as a request, a Gateway signature, the Node\'s host steps in route.publish_site\'s order and a Gateway probe', function (): void {
    $steps = app(RouteEngineFlow::class)->steps(routeOperation(routePlan()));

    expect(array_map(static fn ($step): array => [$step->name, $step->node, $step->action, $step->after], $steps))->toBe([
        ['csr-0', 3, 'tls.ensure_csr', []],
        ['sign-0', 0, 'certificate.sign', ['csr-0']],
        ['site-3-account-0', 3, 'account.read', ['sign-0']],
        ['site-3-url-0', 3, 'dotenv.ensure', ['site-3-account-0']],
        ['site-3-link-0', 3, 'file.ensure', ['site-3-url-0']],
        ['site-3-read-pool-0', 3, 'file.read', ['site-3-link-0']],
        ['site-3-unlink-pool-0', 3, 'file.ensure', ['site-3-read-pool-0']],
        ['site-3-pools', 3, 'config.apply', ['site-3-unlink-pool-0']],
        ['site-3-unit-0', 3, 'systemd.unit.ensure', ['site-3-pools']],
        ['site-3-reload', 3, 'config.apply', ['site-3-unit-0']],
        ['site-3-cert-0', 3, 'file.ensure', ['site-3-reload']],
        ['site-3-current-0', 3, 'file.ensure', ['site-3-cert-0']],
        ['site-3-folder-0', 3, 'file.ensure', ['site-3-current-0']],
        ['site-3-key-0', 3, 'file.ensure', ['site-3-folder-0']],
        ['site-3-copy-0', 3, 'file.ensure', ['site-3-key-0']],
        ['site-3-copylink-0', 3, 'file.ensure', ['site-3-copy-0']],
        ['site-3-trust', 3, 'ca.trust.ensure', ['site-3-copylink-0']],
        ['site-3-read-caddy', 3, 'file.read', ['site-3-trust']],
        ['site-3-unlink-caddy', 3, 'file.ensure', ['site-3-read-caddy']],
        ['site-3-caddy', 3, 'config.apply', ['site-3-unlink-caddy']],
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
        ->and($input('site-3-trust'))->toBe(['state' => 'present', 'name' => RouteHostSteps::trustName(routePlan()['root_certificate']), 'certificate' => ['blob' => ['content' => routePlan()['root_certificate']]],
            'owner' => ['user' => 'root', 'group' => 'root', 'mode' => '0644']]);
});

it('renders the pools whole under the publication lock, starts each unit, then reloads it, and applies the Caddyfile from its read digest', function (): void {
    $flow = app(RouteEngineFlow::class);
    $input = static fn (string $step): array => $flow->input(routeOperation(routePlan()), $step, routeRun());
    $pool = ['path' => '/etc/php/8.4/fpm/pool.d/orbit-scopes.conf', 'content' => ['blob' => ['content' => "[shop]\ngroup = orbit\nchdir = /home/orbit/apps/shop\n"]],
        'from_sha256' => str_repeat('1', 64), 'owner' => ['user' => 'root', 'group' => 'root', 'mode' => '0644']];
    $base = ['lock' => '/etc/caddy/.orbit-publish.lock', 'recovery_dir' => '/var/lib/orbit/executor/config-recovery/pool-install', 'files' => [$pool],
        'validators' => [['kind' => 'php-fpm', 'version' => '8.4']]];
    // The reload's guard is the bytes the install left, so it rewrites nothing and refuses a change made since.
    $installed = [...$pool, 'from_sha256' => hash('sha256', "[shop]\ngroup = orbit\nchdir = /home/orbit/apps/shop\n")];

    expect($input('site-3-pools'))->toBe([...$base, 'units' => []])
        ->and($input('site-3-unit-0'))->toBe(['unit' => 'php8.4-fpm.service', 'enabled' => true, 'state' => 'running', 'budget_ms' => 30_000])
        ->and($input('site-3-reload'))->toBe([...$base, 'recovery_dir' => '/var/lib/orbit/executor/config-recovery/pool-reload', 'files' => [$installed],
            'units' => [['unit' => 'php8.4-fpm.service', 'load' => 'reload']]])
        ->and($input('site-3-caddy'))->toBe(['lock' => '/etc/caddy/.orbit-publish.lock', 'recovery_dir' => '/var/lib/orbit/executor/config-recovery/caddy',
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
        'from_sha256' => hash('sha256', "[shop]\n"), 'owner' => ['user' => 'root', 'group' => 'root', 'mode' => '0644']]])
        ->and($input['validators'])->toBe([['kind' => 'php-fpm', 'version' => '8.4', 'fpm_config' => '/etc/orbit/php-fpm/shop/generated/php-fpm.conf',
            'ini_scan_dir' => ['/etc/php/8.4/fpm/conf.d', '/etc/orbit/php-fpm/shop/generated']]])
        ->and($input['units'])->toBe([['unit' => 'orbit-shop-php8.4-fpm.service', 'load' => 'reload']]);

    $production['php_tests'][0]['argv'][] = '--extra';
    expect(fn () => app(RouteEngineFlow::class)->steps(routeOperation(routePlan($production))))->toThrow(EngineUnsupported::class);
});

it('reads each file its write replaces, and sends the recorded live digest, or none for an absent file', function (): void {
    $flow = app(RouteEngineFlow::class);
    $absent = routeRun(['site-3-read-caddy' => '{"state":"absent"}', 'site-3-read-pool-0' => routeRead(str_repeat('e', 64))]);

    expect($flow->input(routeOperation(routePlan()), 'site-3-read-caddy', routeRun()))->toBe(['path' => '/etc/caddy/Caddyfile'])
        ->and($flow->input(routeOperation(routePlan()), 'site-3-read-pool-0', routeRun()))->toBe(['path' => '/etc/php/8.4/fpm/pool.d/orbit-scopes.conf'])
        ->and($flow->input(routeOperation(routePlan()), 'site-3-caddy', $absent)['files'][0])->not->toHaveKey('from_sha256')
        ->and($flow->input(routeOperation(routePlan()), 'site-3-pools', $absent)['files'][0]['from_sha256'])->toBe(str_repeat('e', 64))
        ->and(RouteHostSteps::digest(json_encode(['state' => 'present', 'type' => 'symlink', 'uid' => 0, 'gid' => 0, 'mode' => '0777'])))->toBeNull();
});

it('sends a retried write byte-identical input, because its reads are recorded once and config.apply\'s identity is its input', function (): void {
    $flow = app(RouteEngineFlow::class);
    // A retry runs later, against the same recorded reads: the live files may have moved on, but the recorded answers did not.
    $retry = new EngineRun(41, routeRun()->steps, 99_000, null);

    foreach (['site-3-pools', 'site-3-reload', 'site-3-caddy', 'dns-0'] as $step) {
        $plan = routePlan(['dns' => [['node_id' => 1, 'records' => "address=/.test/10.44.0.7\n", 'catalog' => "{}\n"]]]);
        expect(json_encode($flow->input(routeOperation($plan), $step, $retry)))->toBe(json_encode($flow->input(routeOperation($plan), $step, routeRun())));
    }
});

it('publishes private DNS on the VPN Node under its lock after the signature, beside the site, and probes after the site alone', function (): void {
    $plan = routePlan(['dns' => [['node_id' => 1, 'records' => "address=/.test/10.44.0.7\n", 'catalog' => "{}\n"]]]);
    $steps = app(RouteEngineFlow::class)->steps(routeOperation($plan));

    expect(array_values(array_map(static fn ($step): array => [$step->name, $step->node, $step->action, $step->after],
        array_filter($steps, static fn ($step): bool => str_starts_with($step->name, 'dns-')))))->toBe([
            ['dns-0-read-records', 1, 'file.read', ['sign-0']],
            ['dns-0-read-catalog', 1, 'file.read', ['dns-0-read-records']],
            ['dns-0-unlink-records', 1, 'file.ensure', ['dns-0-read-catalog']],
            ['dns-0-unlink-catalog', 1, 'file.ensure', ['dns-0-unlink-records']],
            ['dns-0', 1, 'config.apply', ['dns-0-unlink-catalog']],
        ])
        ->and(app(RouteEngineFlow::class)->input(routeOperation($plan), 'dns-0-read-catalog', routeRun()))->toBe(['path' => '/var/lib/orbit/private-dns/catalog.json'])
        ->and(end($steps)->after)->toBe(['site-3-caddy'])
        ->and(app(RouteEngineFlow::class)->input(routeOperation($plan), 'dns-0', routeRun()))->toBe([
            'lock' => '/run/lock/orbit-dnsmasq.lock', 'recovery_dir' => '/var/lib/orbit/executor/config-recovery/dns', 'files' => [
                ['path' => '/etc/dnsmasq.d/orbit-records.conf', 'content' => ['blob' => ['content' => "address=/.test/10.44.0.7\n"]], 'from_sha256' => str_repeat('2', 64),
                    'owner' => ['user' => 'root', 'group' => 'root', 'mode' => '0644']],
                ['path' => '/var/lib/orbit/private-dns/catalog.json', 'content' => ['blob' => ['content' => "{}\n"]], 'from_sha256' => str_repeat('3', 64),
                    'owner' => ['user' => 'root', 'group' => 'root', 'mode' => '0644']],
            ],
            'validators' => [['kind' => 'dnsmasq', 'conf_file' => '/etc/dnsmasq.conf', 'conf_dir' => '/etc/dnsmasq.d', 'skip_suffixes' => ['.dpkg-dist', '.dpkg-old', '.dpkg-new']]],
            'units' => [['unit' => 'dnsmasq.service', 'load' => 'restart'], ['unit' => 'orbit-private-dns.service', 'load' => 'restart']],
        ]);
});

it('names every site step of a Node as the chart\'s iteration of its Node', function (string $step, string $chart): void {
    expect(RouteEngineFlow::chartStep($step))->toBe($chart);
})->with([
    ['site-3-cert-0', 'Install cert, publish site-3'], ['site-3-read-caddy', 'Install cert, publish site-3'], ['dns-0', 'Publish private DNS-0'],
    ['dns-0-read-catalog', 'Publish private DNS-0'], ['csr-0', 'Make a CSR on the Node-0'], ['sign-1', 'Sign the CSR-1'], ['probe-0', 'Probe the site over TLS-0'],
]);

it('sends Caddy access as one posix.acl.ensure entry per row and path, with scope tree for the recursive rows, only to a Node that takes it', function (): void {
    $flow = app(RouteEngineFlow::class);
    $plan = routePlan(['acl' => routeAccess()]);
    $acl = array_values(array_filter($flow->steps(routeOperation($plan)), static fn ($step): bool => $step->action === 'posix.acl.ensure'));
    $input = static fn (int $index): array => $flow->input(routeOperation($plan), "site-3-acl-{$index}", routeRun());

    expect(array_map(static fn ($step): array => [$step->name, $step->features], $acl))->toBe(array_map(static fn (int $i): array => ["site-3-acl-{$i}", ['posix.acl.tree']], array_keys($acl)))
        ->and($acl[0]->after)->toBe(['site-3-account-0'])
        ->and($input(0))->toBe(['path' => '/home/orbit/apps/shop', 'user' => 'caddy', 'perms' => '---', 'default' => true, 'scope' => 'tree', 'run_as' => 'orbit', 'optional' => false])
        ->and($input(1))->toBe(['path' => '/home/orbit/apps/shop', 'user' => 'caddy', 'perms' => '---', 'default' => false, 'scope' => 'tree', 'run_as' => 'orbit', 'optional' => false]);
    $passage = array_values(array_filter(array_map($input, array_keys($acl)), static fn (array $order): bool => $order['perms'] === '--x'));
    $grants = array_values(array_filter(array_map($input, array_keys($acl)), static fn (array $order): bool => $order['perms'] === 'r-X'));

    // The passage through the folders above a served root is one path's access entry, run as root; a grant is recursive.
    expect($passage)->not->toBeEmpty()
        ->and(array_column($passage, 'scope'))->toBe([])->and(array_column($passage, 'run_as'))->toBe([])
        ->and(array_unique(array_column($grants, 'scope')))->toBe(['tree'])
        ->and(array_column(array_filter($grants, static fn (array $order): bool => $order['optional']), 'path'))->toContain('/home/orbit/apps/shop/storage/app/public');
});

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

it('renders a shared pool with the account\'s group and home in place of the render\'s placeholders', function (): void {
    expect(RouteHostSteps::render("group = __ORBIT_NODE_GROUP__\nchdir = /__ORBIT_NODE_HOME__/app-1\n", 'orbit', '/home/orbit'))
        ->toBe("group = orbit\nchdir = /home/orbit/app-1\n");
});

it('removes a link found in place of each file it writes, without following it, then writes it with no guard', function (string $read, string $unlink, string $write, string $path): void {
    $flow = app(RouteEngineFlow::class);
    $plan = routePlan(['dns' => [['node_id' => 1, 'records' => "address=/.test/10.44.0.7\n", 'catalog' => "{}\n"]]]);
    $link = json_encode(['state' => 'present', 'type' => 'symlink', 'uid' => 0, 'gid' => 0, 'mode' => '0777']);
    $linked = routeRun([$read => $link]);
    $files = $flow->input(routeOperation($plan), $write, $linked)['files'];

    expect($flow->skips(routeOperation($plan), $unlink, $linked))->toBeFalse()
        ->and($flow->input(routeOperation($plan), $unlink, $linked))->toBe(['state' => 'absent', 'path' => $path])
        ->and(array_values(array_filter($files, static fn (array $file): bool => $file['path'] === $path))[0])->not->toHaveKey('from_sha256')
        // A plain file is never removed: its unlink step is done without an order, and its write keeps the read digest.
        ->and($flow->skips(routeOperation($plan), $unlink, routeRun()))->toBeTrue();
})->with([
    'the Caddyfile' => ['site-3-read-caddy', 'site-3-unlink-caddy', 'site-3-caddy', '/etc/caddy/Caddyfile'],
    'a pool' => ['site-3-read-pool-0', 'site-3-unlink-pool-0', 'site-3-pools', '/etc/php/8.4/fpm/pool.d/orbit-scopes.conf'],
    'the records' => ['dns-0-read-records', 'dns-0-unlink-records', 'dns-0', '/etc/dnsmasq.d/orbit-records.conf'],
    'the catalog' => ['dns-0-read-catalog', 'dns-0-unlink-catalog', 'dns-0', '/var/lib/orbit/private-dns/catalog.json'],
]);

it('gives each config.apply step its own recovery folder, so a pending record blocks only a replay of its own order', function (): void {
    $flow = app(RouteEngineFlow::class);
    $plan = routePlan(['dns' => [['node_id' => 1, 'records' => "r\n", 'catalog' => "{}\n"]]]);
    $folders = array_map(static fn (string $step): string => $flow->input(routeOperation($plan), $step, routeRun())['recovery_dir'], ['site-3-pools', 'site-3-reload', 'site-3-caddy', 'dns-0']);

    expect($folders)->toBe(['/var/lib/orbit/executor/config-recovery/pool-install', '/var/lib/orbit/executor/config-recovery/pool-reload',
        '/var/lib/orbit/executor/config-recovery/caddy', '/var/lib/orbit/executor/config-recovery/dns'])
        // An ordinary retry after a failed reload sends the reload byte for byte, so the executor replays its recorded order.
        ->and(json_encode($flow->input(routeOperation($plan), 'site-3-reload', new EngineRun(41, routeRun()->steps, 99_000, null))))
        ->toBe(json_encode($flow->input(routeOperation($plan), 'site-3-reload', routeRun())));
});

it('names the trust source after the root\'s fingerprint, so a new root gets its own name and hash link', function (): void {
    $old = "-----BEGIN CERTIFICATE-----\nT0xE\n-----END CERTIFICATE-----\n";
    $new = "-----BEGIN CERTIFICATE-----\nTkVX\n-----END CERTIFICATE-----\n";
    $input = app(RouteEngineFlow::class)->input(routeOperation(routePlan(['root_certificate' => $new])), 'site-3-trust', routeRun());

    expect(RouteHostSteps::trustName($new))->not->toBe(RouteHostSteps::trustName($old))
        ->and(RouteHostSteps::trustName($new))->toBe('orbit-managed-root-ca-'.substr(hash('sha256', 'NEW'), 0, 16).'.crt')
        ->and($input['name'])->toBe(RouteHostSteps::trustName($new));
});

it('sizes each order with real 64-hex guards: every admitted pool count fits at run time, and an order over the signer limit runs on Swamp', function (): void {
    $pools = static fn (int $count): array => ['php_files' => array_map(static fn (int $i): array => ['node_id' => 3, 'path' => "/etc/orbit/php-fpm/shop{$i}/generated/pool.conf",
        'content' => "[shop{$i}]\n", 'owner' => 'root'], range(1, $count)),
        'php_tests' => array_map(static fn (int $i): array => ['node_id' => 3, 'argv' => ['/usr/bin/env', "PHP_INI_SCAN_DIR=/etc/php/8.4/fpm/conf.d:/etc/orbit/php-fpm/shop{$i}/generated",
            '/usr/sbin/php-fpm8.4', '-t', '--fpm-config', "/etc/orbit/php-fpm/shop{$i}/generated/php-fpm.conf"]], range(1, $count)),
        'php_reloads' => array_map(static fn (int $i): array => ['node_id' => 3, 'unit' => "orbit-shop{$i}-php8.4-fpm.service"], range(1, $count))];
    $admitted = 0;
    foreach (range(1, 8) as $count) {
        try {
            app(RouteEngineFlow::class)->steps(routeOperation(routePlan($pools($count))));
        } catch (EngineUnsupported) {
            continue;
        }
        $admitted = $count;
        $reads = [];
        foreach (range(0, $count - 1) as $i) {
            $reads["site-3-read-pool-{$i}"] = routeRead(str_repeat('f', 64));
        }
        // Whatever admission lets through stays under the limit with the real guards it carries at run time.
        foreach (['site-3-pools', 'site-3-reload'] as $step) {
            RouteEngineFlow::fits(app(RouteEngineFlow::class)->input(routeOperation(routePlan($pools($count))), $step, routeRun($reads)));
        }
    }

    expect($admitted)->toBeGreaterThan(0)
        ->and(fn () => app(RouteEngineFlow::class)->steps(routeOperation(routePlan($pools(16)))))->toThrow(EngineUnsupported::class);
});

it('reads each account the Node\'s steps write for and renders home and group from its recorded answer', function (): void {
    $flow = app(RouteEngineFlow::class);
    $plan = routePlan(['links' => [['node_id' => 3, 'path' => '/srv/shop/current', 'target' => 'releases/1', 'owner' => 'shop']]]);
    // A home outside /home/<name> and a primary group with another name: both come from the Node, never from the user's name.
    $run = routeRun(['site-3-account-0' => json_encode(['state' => 'present', 'uid' => 1000, 'gid' => 2000, 'group' => 'staff', 'home' => '/srv/orbit']),
        'site-3-account-1' => json_encode(['state' => 'present', 'uid' => 1001, 'gid' => 1001, 'group' => 'shopgrp', 'home' => '/srv/shop'])]);
    $steps = array_map(static fn ($step): string => $step->name, array_filter($flow->steps(routeOperation($plan)), static fn ($step): bool => $step->action === 'account.read'));

    expect(array_values($steps))->toBe(['site-3-account-0', 'site-3-account-1'])
        ->and($flow->input(routeOperation($plan), 'site-3-account-0', $run))->toBe(['user' => 'orbit'])
        ->and($flow->input(routeOperation($plan), 'site-3-account-1', $run))->toBe(['user' => 'shop'])
        ->and($flow->input(routeOperation($plan), 'site-3-link-0', $run))->toBe(['state' => 'link', 'path' => '/srv/shop/current', 'target' => 'releases/1', 'user' => 'shop', 'group' => 'shopgrp'])
        ->and($flow->input(routeOperation($plan), 'site-3-key-0', $run))->toMatchArray(['path' => '/srv/orbit/.orbit/certificates/app-instance-7/versions/0123456789abcdef/key.pem',
            'owner' => ['user' => 'orbit', 'group' => 'staff', 'mode' => '0600']])
        ->and($flow->input(routeOperation($plan), 'site-3-pools', $run)['files'][0]['content']['blob']['content'])->toBe("[shop]\ngroup = staff\nchdir = /srv/orbit/apps/shop\n")
        // A retry reads the same recorded answer, so it sends the same bytes.
        ->and(json_encode($flow->input(routeOperation($plan), 'site-3-copy-0', new EngineRun(41, $run->steps, 99_000, null))))
        ->toBe(json_encode($flow->input(routeOperation($plan), 'site-3-copy-0', $run)));
});

it('fails a step that needs an absent or unreadable account, which records main\'s route.publish_failed', function (?string $answer, string $code): void {
    app(RouteEngineFlow::class)->input(routeOperation(routePlan()), 'site-3-copy-0', routeRun(['site-3-account-0' => $answer]));
})->with([
    'an absent account' => ['{"state":"absent"}', 'route.account_missing'],
    'no recorded answer' => [null, 'route.account_missing'],
    'a relative home' => ['{"state":"present","uid":1000,"gid":1000,"group":"orbit","home":"orbit"}', 'route.account_invalid'],
])->throws(RuntimeException::class);
