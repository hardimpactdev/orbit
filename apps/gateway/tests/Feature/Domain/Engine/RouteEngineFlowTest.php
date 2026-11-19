<?php

declare(strict_types=1);

use App\Domain\Engine\EngineContent;
use App\Domain\Engine\EngineRun;
use App\Domain\Engine\EngineUnsupported;
use App\Domain\Engine\MachineBus;
use App\Domain\Executor\SignedExecutorOrder;
use App\Domain\Routes\RouteEngineFlow;
use App\Domain\Routes\RouteWebRootCommands;
use App\Models\Instance;
use App\Models\Operation;
use App\Models\OperationEngineStep;

/** An admitted route_create plan, in the shape NativeRouteCreatePlan builds, for a dev Route on Node 3. */
function routePlan(array $overrides = []): array
{
    $instance = (new Instance)->forceFill(['source_is_laravel' => true]);
    $acl = RouteWebRootCommands::access(3, ['user' => 'orbit', 'sites' => [
        ['checkout' => '/home/orbit/apps/shop', 'relative_root' => 'public', 'application' => '/home/orbit/apps/shop'],
    ]], $instance);

    return [...[
        'route_id' => 9, 'domain' => 'shop.orbit', 'lan' => [], 'ingress_ports' => [], 'acl' => $acl, 'links' => [],
        'php_files' => [['node_id' => 3, 'path' => '/etc/php/8.4/fpm/pool.d/orbit-scopes.conf', 'content' => "[shop]\n", 'owner' => 'root']],
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

it('runs a dev Route as a request, a Gateway signature, the Node\'s site and a Gateway probe, in that order', function (): void {
    $steps = app(RouteEngineFlow::class)->steps(routeOperation(routePlan()));

    expect(array_map(static fn ($step): array => [$step->name, $step->node, $step->action, $step->after], $steps))->toBe([
        ['csr-0', 3, 'tls.ensure_csr', []],
        ['sign-0', 0, 'certificate.sign', ['csr-0']],
        ['site-3', 3, 'route.publish_site', ['sign-0']],
        ['probe-0', 0, 'route.probe', ['site-3']],
    ]);
});

it('leaves a Route that needs a part the engine does not run to Swamp', function (string $list, array $row): void {
    app(RouteEngineFlow::class)->steps(routeOperation(routePlan([$list => [$row]])));
})->throws(EngineUnsupported::class)->with([
    'private DNS' => ['dns', ['node_id' => 5]],
    'the Ingress edge' => ['ingress', ['node_id' => 6]],
    'a LAN rule' => ['lan', ['node_id' => 3]],
    'a production .env' => ['env_files', ['node_id' => 3]],
    'awake markers' => ['awake', ['node_id' => 3]],
    'an access command of another shape' => ['acl', ['node_id' => 3, 'argv' => ['/usr/bin/chmod', '777', '/home/orbit']]],
]);

it('sends the Node typed access kinds that rebuild the admitted commands, and its content as blobs', function (): void {
    $operation = routeOperation(routePlan());
    $signed = (new OperationEngineStep)->forceFill(['name' => 'sign-0', 'status' => 'done',
        'output' => "-----BEGIN CERTIFICATE-----\nLEAF\n-----END CERTIFICATE-----\n-----BEGIN CERTIFICATE-----\nROOT\n-----END CERTIFICATE-----\n"]);
    $run = new EngineRun(41, ['sign-0' => $signed], 0, null);

    $input = app(RouteEngineFlow::class)->input($operation, 'site-3', $run);

    expect($input['account'])->toBe('orbit')
        ->and(array_column($input['acl'], 'kind'))->toBe(['deny_defaults', 'deny', 'passage', 'grant_defaults', 'grant'])
        ->and($input['acl'][2])->toMatchArray(['user' => null])
        ->and($input['acl'][4]['optional_paths'])->toBe(['/home/orbit/apps/shop/storage/app/public'])
        ->and($input['url_lines'])->toBe([['path' => '/home/orbit/apps/shop/.env', 'url' => 'https://shop.orbit']])
        ->and($input['php'])->toBe([['version' => '8.4', 'user' => null, 'content' => ['blob' => ['content' => "[shop]\n"]]]])
        ->and($input['leaves'])->toBe([['scope' => 'app-instance-7', 'version' => '0123456789abcdef',
            'certificate' => ['blob' => ['content' => "-----BEGIN CERTIFICATE-----\nLEAF\n-----END CERTIFICATE-----\n"]]]])
        ->and($input['root_certificate'])->toBe(['blob' => ['content' => routePlan()['root_certificate']]])
        ->and($input['caddy'])->toBe(['blob' => ['content' => "shop.orbit {\n}\n"]]);
});

it('keeps a production pool only when its test and unit are the ones the executor derives', function (): void {
    $production = ['php_files' => [['node_id' => 3, 'path' => '/etc/orbit/php-fpm/shop/generated/pool.conf', 'content' => "[shop]\n", 'owner' => 'root']],
        'php_tests' => [['node_id' => 3, 'argv' => ['/usr/bin/env', 'PHP_INI_SCAN_DIR=/etc/php/8.4/fpm/conf.d:/etc/orbit/php-fpm/shop/generated',
            '/usr/sbin/php-fpm8.4', '-t', '--fpm-config', '/etc/orbit/php-fpm/shop/generated/php-fpm.conf']]],
        'php_reloads' => [['node_id' => 3, 'unit' => 'orbit-shop-php8.4-fpm.service']]];
    $run = new EngineRun(41, ['sign-0' => (new OperationEngineStep)->forceFill(['output' => "-----BEGIN CERTIFICATE-----\nX\n"])], 0, null);

    $input = app(RouteEngineFlow::class)->input(routeOperation(routePlan($production)), 'site-3', $run);
    expect($input['php'][0])->toMatchArray(['version' => '8.4', 'user' => 'shop']);

    $production['php_tests'][0]['argv'][] = '--extra';
    expect(fn () => app(RouteEngineFlow::class)->steps(routeOperation(routePlan($production))))->toThrow(EngineUnsupported::class);
});

it('names a failed step as the chart\'s iteration, so the Route records the chart\'s error code', function (): void {
    expect(RouteEngineFlow::chartStep('csr-0'))->toBe('Make a CSR on the Node-0')
        ->and(RouteEngineFlow::chartStep('sign-1'))->toBe('Sign the CSR-1')
        ->and(RouteEngineFlow::chartStep('site-3'))->toBe('Install cert, publish site-3')
        ->and(RouteEngineFlow::chartStep('probe-0'))->toBe('Probe the site over TLS-0');
});

it('puts step content into the Node\'s content keys and leaves only its hash in the order', function (): void {
    $bus = new class implements MachineBus
    {
        /** @var list<array{int, string}> */
        public array $puts = [];

        public function publishOrder(int $node, SignedExecutorOrder $order): void {}

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
    };

    $input = EngineContent::upload($bus, 3, ['account' => 'orbit', 'caddy' => ['blob' => ['content' => 'site']], 'leaves' => [['certificate' => ['blob' => ['content' => 'pem']]]]]);

    expect($input)->toBe(['account' => 'orbit', 'caddy' => ['blob' => ['sha256' => hash('sha256', 'site')]],
        'leaves' => [['certificate' => ['blob' => ['sha256' => hash('sha256', 'pem')]]]]])
        ->and($bus->puts)->toBe([[3, 'site'], [3, 'pem']]);
});
