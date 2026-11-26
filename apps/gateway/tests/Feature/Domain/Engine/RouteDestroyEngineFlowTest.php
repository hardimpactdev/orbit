<?php

declare(strict_types=1);

use App\Domain\Engine\EngineKinds;
use App\Domain\Engine\EngineRun;
use App\Domain\Engine\EngineUnsupported;
use App\Domain\Engine\MachineBus;
use App\Domain\Executor\SignedExecutorOrder;
use App\Domain\Routes\RouteDestroyEngineFlow;
use App\Models\Operation;
use App\Models\OperationEngineStep;

const DestroyBefore = "shop.orbit {\n}\nother.orbit {\n}\n";

/** An admitted route.destroy, in the shape NativeRouteRemovalAdmission records, for a dev Route on Node 3 with DNS on VPN Node 1. */
function routeDestroyOperation(array $plan = [], array $input = []): Operation
{
    $empty = ['acl' => [], 'url_lines' => [], 'env_files' => [], 'links' => [], 'lan' => []];
    $plan = [...[
        'offline' => false, 'nodes' => ['3' => ['account' => 'orbit'], '1' => ['account' => 'orbit']], 'answer_nodes' => [],
        'dns' => [['node_id' => 1, 'records' => "address=/other.example/10.44.0.8\n", 'catalog' => "{}\n"]], 'grace' => [['seconds' => 31]],
        'retire' => [...$empty, 'caddy' => [['node_id' => 3, 'content' => "other.orbit {\n}\n"]], 'site_nodes' => [3]],
        'cleanup' => [['node_id' => 3, 'leaf' => ['user' => 'orbit', 'scope' => 'app-instance-7']]], 'lan_absent' => [], 'retire_nodes' => [3],
        'pools' => [...$empty, 'php_files' => [['node_id' => 3, 'path' => '/etc/php/8.4/fpm/pool.d/orbit-scopes.conf', 'content' => "[orbit]\n", 'owner' => 'root']],
            'php_tests' => [['node_id' => 3, 'argv' => ['/usr/sbin/php-fpm8.4', '-t']]], 'php_reloads' => [['node_id' => 3, 'unit' => 'php8.4-fpm.service']], 'site_nodes' => [3]],
        'lan_remove' => [], 'ingress_close' => [], 'firewall_nodes' => [], 'check_nodes' => [3],
    ], ...$plan];

    return (new Operation)->forceFill(['id' => 51, 'kind' => 'route.destroy', 'node_id' => 3, 'input' => [...[
        'route_id' => 9, 'offline' => false, 'route_private_development' => true, 'route_removal' => ['routes' => [['route_id' => 9, 'domain' => 'shop.orbit', 'replacement_of' => null]]],
        'route_lifecycle' => ['plan' => $plan],
        'route_guards' => ['3' => ['caddy' => hash('sha256', DestroyBefore), 'php_versions' => '8.4'],
            '1' => ['records' => hash('sha256', "address=/shop.example/10.44.0.7\naddress=/other.example/10.44.0.8\n"), 'catalog' => hash('sha256', "{\"shop\":1}\n")]],
    ], ...$input]]);
}

/** @param list<array{0: string, 1: int, 2: string, 3: list<string>}> $expected */
function destroySteps(Operation $operation): array
{
    return array_map(static fn ($step): array => [$step->name, $step->node, $step->action, $step->after, $step->features], app(RouteDestroyEngineFlow::class)->steps($operation));
}

it('withdraws DNS on the VPN Node, waits the grace, then withdraws the site and probes a sibling', function (): void {
    expect(destroySteps(routeDestroyOperation()))->toBe([
        ['dns-0', 1, 'dns.publish_records', [], ['dns.guarded_records']],
        ['grace', 0, 'route.dns_grace', ['dns-0'], []],
        ['withdraw-3', 3, 'route.withdraw_site', ['grace'], []],
        ['sibling-3', 0, 'route.probe', ['withdraw-3'], []],
    ]);
});

it('withdraws the site at once when the Route has no private DNS answers', function (): void {
    expect(destroySteps(routeDestroyOperation(['dns' => [], 'grace' => []])))->toBe([
        ['withdraw-3', 3, 'route.withdraw_site', [], []],
        ['sibling-3', 0, 'route.probe', ['withdraw-3'], []],
    ]);
});

it('guards each DNS file and the Caddyfile with the digest of the bytes the Gateway rendered from', function (): void {
    $flow = app(RouteDestroyEngineFlow::class);
    $run = new EngineRun(51, [], 0, null);

    expect($flow->input(routeDestroyOperation(), 'dns-0', $run))->toBe([
        'records' => ['blob' => ['content' => "address=/other.example/10.44.0.8\n"], 'from_sha256' => hash('sha256', "address=/shop.example/10.44.0.7\naddress=/other.example/10.44.0.8\n")],
        'catalog' => ['blob' => ['content' => "{}\n"], 'from_sha256' => hash('sha256', "{\"shop\":1}\n")],
    ])->and($flow->input(routeDestroyOperation(), 'withdraw-3', $run))->toBe([
        'account' => 'orbit', 'domains' => ['shop.orbit'], 'leaves' => ['app-instance-7'],
        'php' => [['version' => '8.4', 'user' => null, 'content' => ['blob' => ['content' => "[orbit]\n"]]]],
        'caddy' => ['from_sha256' => hash('sha256', DestroyBefore), 'content' => ['blob' => ['content' => "other.orbit {\n}\n"]]],
    ]);
});

it('leaves a removal the engine cannot reproduce exactly to Swamp', function (array $plan, array $input): void {
    app(RouteDestroyEngineFlow::class)->steps(routeDestroyOperation($plan, $input));
})->throws(EngineUnsupported::class)->with([
    '--offline' => [['offline' => true], ['offline' => true]],
    'a LAN rule' => [['lan_remove' => [['node_id' => 3, 'port' => 8080, 'protocol' => 'tcp', 'source' => '192.168.1.0/24']]], []],
    'the Ingress edge' => [['ingress_close' => [['node_id' => 6, 'port' => 443]]], []],
    'an APP_URL write for a surviving Route' => [['retire' => ['acl' => [], 'links' => [], 'lan' => [], 'env_files' => [], 'site_nodes' => [3],
        'caddy' => [['node_id' => 3, 'content' => "x\n"]], 'url_lines' => [['node_id' => 3, 'path' => '/home/orbit/apps/shop/.env', 'line' => 'APP_URL=https://x.orbit', 'owner' => 'orbit']]]], []],
    'a never-activated replacement' => [[], ['route_removal' => ['routes' => [['route_id' => 9, 'domain' => 'new.orbit', 'replacement_of' => 4]]]]],
    'no captured file digests' => [[], ['route_guards' => []]],
    'a public Route or a Process proxy behind the Ingress' => [[], ['route_private_development' => false]],
    'a PHP version whose last pool leaves' => [['pools' => ['php_files' => [], 'php_tests' => [], 'php_reloads' => [], 'site_nodes' => []]], []],
    'an order over the signer\'s limit' => [['cleanup' => array_map(static fn (int $i): array => ['node_id' => 3, 'leaf' => ['user' => 'orbit', 'scope' => str_repeat('s', 60).$i]], range(1, 80))], []],
    'a pool on a Node the Route does not leave' => [['pools' => ['php_files' => [], 'php_tests' => [], 'php_reloads' => [], 'site_nodes' => [5]]], []],
]);

it('starts on the engine only when the Route Node reports the withdrawal and each VPN Node the guarded DNS input', function (array $actions, array $features, bool $engine): void {
    config(['orbit.engine.kinds' => ['route.destroy']]);
    $bus = new class($actions, $features) implements MachineBus
    {
        public function __construct(private array $actions, private array $features) {}

        public function publishOrder(int $node, SignedExecutorOrder $order, ?Closure $claim = null): void {}

        public function publishStart(int $operation): void {}

        public function scheduleDeadline(int $operation, string $step, int $sends, int $atMs): void {}

        public function nudge(int $operation, string $id): void {}

        public function putContent(int $node, string $content): array
        {
            return ['sha256' => hash('sha256', $content)];
        }

        public function nodeActions(int $node): array
        {
            return $this->actions[$node] ?? [];
        }

        public function nodeFeatures(int $node): array
        {
            return $this->features[$node] ?? [];
        }

        public function nodeTargetActions(int $node): array
        {
            return [];
        }

        public function nodeClock(int $node): ?bool
        {
            return true;
        }
    };
    $this->app->instance(MachineBus::class, $bus);

    expect(app(EngineKinds::class)->routes(routeDestroyOperation()))->toBe($engine);
})->with([
    'both report' => [[1 => ['dns.publish_records'], 3 => ['route.withdraw_site']], [1 => ['dns.guarded_records']], true],
    'an older VPN executor' => [[1 => ['dns.publish_records'], 3 => ['route.withdraw_site']], [], false],
    'a Route Node without the withdrawal' => [[1 => ['dns.publish_records'], 3 => ['route.publish_site']], [1 => ['dns.guarded_records']], false],
]);

it('waits what is left of the 31 s grace after the DNS receipt, and fails without one', function (): void {
    $flow = app(RouteDestroyEngineFlow::class);
    $done = (new OperationEngineStep)->forceFill(['name' => 'dns-0', 'status' => 'done', 'done_ms' => (int) floor(microtime(true) * 1000) - 31_500]);

    expect($flow->local(routeDestroyOperation(), 'grace', new EngineRun(51, ['dns-0' => $done], 0, null)))->toBe('waited 0 ms')
        ->and(fn () => $flow->local(routeDestroyOperation(), 'grace', new EngineRun(51, [], 0, null)))->toThrow(RuntimeException::class, 'route.dns_grace_failed');
});

it('names a failed step as the Swamp chart\'s iteration, so the Route records the same error code', function (): void {
    expect(RouteDestroyEngineFlow::chartStep('dns-0'))->toBe('Remove private DNS-0')
        ->and(RouteDestroyEngineFlow::chartStep('grace'))->toBe('Wait the DNS grace-0')
        ->and(RouteDestroyEngineFlow::chartStep('withdraw-3'))->toBe('Withdraw site, remove cert-3')
        ->and(RouteDestroyEngineFlow::chartStep('sibling-3'))->toBe('Check nothing is left-3');
});

it('signs the guarded DNS and withdrawal orders in the wire shape the executor decodes', function (): void {
    $bus = new class implements MachineBus
    {
        public function publishOrder(int $node, SignedExecutorOrder $order, ?Closure $claim = null): void {}

        public function publishStart(int $operation): void {}

        public function scheduleDeadline(int $operation, string $step, int $sends, int $atMs): void {}

        public function nudge(int $operation, string $id): void {}

        public function putContent(int $node, string $content): array
        {
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
            return true;
        }
    };
    $flow = app(RouteDestroyEngineFlow::class);
    $run = new EngineRun(51, [], 0, null);
    $shape = static function (mixed $value) use (&$shape): mixed {
        return is_array($value) ? array_map($shape, array_is_list($value) ? array_slice($value, 0, 1) : $value) : gettype($value);
    };
    $fixture = static fn (string $file): array => json_decode((string) file_get_contents(base_path('../agent/contract/fixtures/'.$file)), true)['fields']['input'];

    $dns = App\Domain\Engine\EngineContent::upload($bus, 1, $flow->input(routeDestroyOperation(), 'dns-0', $run));
    $withdraw = App\Domain\Engine\EngineContent::upload($bus, 3, $flow->input(routeDestroyOperation(), 'withdraw-3', $run));

    expect($dns['records'])->toBe(['blob' => ['sha256' => hash('sha256', "address=/other.example/10.44.0.8\n")],
        'from_sha256' => hash('sha256', "address=/shop.example/10.44.0.7\naddress=/other.example/10.44.0.8\n")])
        ->and($shape($dns))->toBe($shape($fixture('executor-dns-guarded-records.json')))
        ->and(array_keys($withdraw))->toBe(array_keys($fixture('executor-route-withdraw-site.json')))
        ->and($withdraw['caddy']['content'])->toBe(['blob' => ['sha256' => hash('sha256', "other.orbit {\n}\n")]])
        ->and($withdraw['php'][0]['content'])->toBe(['blob' => ['sha256' => hash('sha256', "[orbit]\n")]]);
});
