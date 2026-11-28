<?php

declare(strict_types=1);

use App\Domain\Engine\EngineContent;
use App\Domain\Engine\EngineKinds;
use App\Domain\Engine\EngineRun;
use App\Domain\Engine\EngineStepRefusal;
use App\Domain\Engine\EngineUnsupported;
use App\Domain\Engine\MachineBus;
use App\Domain\Executor\SignedExecutorOrder;
use App\Domain\Nodes\RoleName;
use App\Domain\Routes\RouteDestroyEngineFlow;
use App\Models\Node;
use App\Models\Operation;
use App\Models\OperationEngineStep;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;

uses(LazilyRefreshDatabase::class);

const DestroyBefore = "shop.orbit {\n}\nother.orbit {\n}\n";
const DestroyAfter = "other.orbit {\n}\n";

beforeEach(function (): void {
    foreach ([1 => RoleName::Vpn, 3 => RoleName::AppDev] as $id => $role) {
        Node::withoutEvents(fn (): Node => Node::query()->forceCreate(['id' => $id, 'name' => "node-{$id}", 'status' => 'active', 'platform' => 'linux',
            'wireguard_ip' => "10.44.0.{$id}", 'public_ssh_host' => "10.44.0.{$id}", 'user' => 'orbit']))->roles()->create(['role' => $role, 'status' => 'active']);
    }
});

/** An admitted route.destroy, in the shape NativeRouteRemovalAdmission records, for a dev Route on Node 3 with DNS on VPN Node 1. */
function routeDestroyOperation(array $plan = [], array $input = []): Operation
{
    $empty = ['acl' => [], 'url_lines' => [], 'env_files' => [], 'links' => [], 'lan' => []];
    $plan = [...[
        'offline' => false, 'nodes' => ['3' => ['account' => 'orbit'], '1' => ['account' => 'orbit']], 'answer_nodes' => [],
        'dns' => [['node_id' => 1, 'records' => "address=/other.example/10.44.0.8\n", 'catalog' => "{}\n"]], 'grace' => [['seconds' => 31]],
        'retire' => [...$empty, 'caddy' => [['node_id' => 3, 'content' => DestroyAfter]], 'site_nodes' => [3]],
        'cleanup' => [], 'lan_absent' => [], 'retire_nodes' => [3],
        'pools' => [...$empty, 'php_files' => [], 'php_tests' => [], 'php_reloads' => [], 'site_nodes' => []],
        'lan_remove' => [], 'ingress_close' => [], 'firewall_nodes' => [], 'check_nodes' => [3],
    ], ...$plan];

    return (new Operation)->forceFill(['id' => 51, 'kind' => 'route.destroy', 'node_id' => 3, 'input' => [...[
        'route_id' => 9, 'offline' => false, 'route_private_development' => true,
        'route_removal' => ['routes' => [['route_id' => 9, 'domain' => 'shop.orbit', 'replacement_of' => null]], 'dns_node_ids' => [1],
            'nodes' => [['node_id' => 3, 'production_instance_ids' => []]]],
        'route_lifecycle' => ['plan' => $plan],
        'route_guards' => ['3' => ['caddy' => hash('sha256', DestroyBefore), 'php_versions' => '8.4', 'php_versions_after' => '8.4'],
            '1' => ['records' => hash('sha256', "address=/shop.example/10.44.0.7\naddress=/other.example/10.44.0.8\n"), 'catalog' => hash('sha256', "{\"shop\":1}\n"), 'catalog_file' => hash('sha256', "{\"shop\":1}\n")]],
    ], ...$input]]);
}

function destroySteps(Operation $operation): array
{
    return array_map(static fn ($step): array => [$step->name, $step->node, $step->action, $step->after], app(RouteDestroyEngineFlow::class)->steps($operation));
}

/** A run whose reads answered: each step name to the file.read output, or to a receipt time. */
function destroyRun(array $reads, array $done = []): EngineRun
{
    $steps = [];
    foreach ($reads as $name => $read) {
        $steps[$name] = (new OperationEngineStep)->forceFill(['name' => $name, 'status' => 'done', 'output' => json_encode($read)]);
    }
    foreach ($done as $name => $ms) {
        $steps[$name] = (new OperationEngineStep)->forceFill(['name' => $name, 'status' => 'done', 'done_ms' => $ms]);
    }

    return new EngineRun(51, $steps, 0, null);
}

function plainFile(string $content): array
{
    return ['state' => 'present', 'type' => 'file', 'sha256' => hash('sha256', $content), 'uid' => 0, 'gid' => 0, 'mode' => '0644'];
}

it('reads and withdraws DNS on the VPN Node, waits the grace, then reads, guards, withdraws, polls and probes a sibling', function (): void {
    expect(destroySteps(routeDestroyOperation()))->toBe([
        ['dnsread-0-records', 1, 'file.read', []],
        ['dnsunlink-0-records', 1, 'file.ensure', ['dnsread-0-records']],
        ['dnsread-0-catalog', 1, 'file.read', []],
        ['dnsunlink-0-catalog', 1, 'file.ensure', ['dnsread-0-catalog']],
        ['dns-0', 1, 'config.apply', ['dnsunlink-0-records', 'dnsunlink-0-catalog']],
        ['grace', 0, 'route.dns_grace', ['dns-0']],
        ['caddyread-3', 3, 'file.read', ['grace']],
        ['guard-3', 0, 'route.guard', ['caddyread-3']],
        ['caddyunlink-3', 3, 'file.ensure', ['guard-3']],
        ['withdraw-3', 3, 'config.apply', ['caddyunlink-3']],
        ['poll-3', 3, 'http.probe', ['withdraw-3']],
        ['sibling-3', 0, 'route.probe', ['poll-3']],
    ]);
});

it('withdraws the site at once when the Route has no private DNS answers', function (): void {
    expect(array_column(destroySteps(routeDestroyOperation(['dns' => [], 'grace' => []], ['route_removal' => ['routes' => [['route_id' => 9, 'domain' => 'shop.orbit', 'replacement_of' => null]], 'dns_node_ids' => []]])), 0))
        ->toBe(['caddyread-3', 'guard-3', 'caddyunlink-3', 'withdraw-3', 'poll-3', 'sibling-3']);
});

it('sends every path, lock, owner, validator and unit, guarded by the digests the Gateway rendered from', function (): void {
    $flow = app(RouteDestroyEngineFlow::class);
    $records = "address=/shop.example/10.44.0.7\naddress=/other.example/10.44.0.8\n";
    $run = destroyRun(['dnsread-0-records' => plainFile($records), 'dnsread-0-catalog' => ['state' => 'absent'], 'caddyread-3' => plainFile(DestroyBefore)]);

    expect($flow->input(routeDestroyOperation(), 'dnsread-0-records', $run))->toBe(['path' => '/etc/dnsmasq.d/orbit-records.conf'])
        ->and($flow->input(routeDestroyOperation(), 'dnsread-0-catalog', $run))->toBe(['path' => '/var/lib/orbit/private-dns/catalog.json'])
        ->and($flow->input(routeDestroyOperation(), 'dns-0', $run))->toBe([
            'lock' => '/run/lock/orbit-dnsmasq.lock', 'recovery_dir' => '/var/lib/orbit/executor/recovery/operation-51/dns-0',
            'files' => [
                ['path' => '/etc/dnsmasq.d/orbit-records.conf', 'content' => ['blob' => ['content' => "address=/other.example/10.44.0.8\n"]], 'from_sha256' => hash('sha256', $records), 'owner' => ['user' => 'root', 'group' => 'root', 'mode' => '0644']],
                ['path' => '/var/lib/orbit/private-dns/catalog.json', 'content' => ['blob' => ['content' => "{}\n"]], 'from_sha256' => hash('sha256', "{\"shop\":1}\n"), 'owner' => ['user' => 'root', 'group' => 'root', 'mode' => '0644']],
            ],
            'validators' => [['kind' => 'dnsmasq', 'conf_file' => '/etc/dnsmasq.conf', 'conf_dir' => '/etc/dnsmasq.d', 'skip_suffixes' => ['.dpkg-dist', '.dpkg-old', '.dpkg-new']]],
            'units' => [['unit' => 'dnsmasq.service', 'load' => 'restart'], ['unit' => 'orbit-private-dns.service', 'load' => 'restart']],
        ])
        ->and($flow->input(routeDestroyOperation(), 'withdraw-3', $run))->toBe([
            'lock' => '/etc/caddy/.orbit-publish.lock', 'recovery_dir' => '/var/lib/orbit/executor/recovery/operation-51/withdraw-3',
            'files' => [['path' => '/etc/caddy/Caddyfile', 'content' => ['blob' => ['content' => DestroyAfter]], 'from_sha256' => hash('sha256', DestroyBefore), 'owner' => ['user' => 'root', 'group' => 'caddy', 'mode' => '0640']]],
            'validators' => [['kind' => 'caddy', 'config' => '/etc/caddy/Caddyfile', 'user' => 'caddy']],
            'units' => [['unit' => 'caddy.service', 'load' => 'reload']],
        ])
        ->and($flow->input(routeDestroyOperation(), 'poll-3', $run))->toBe(['url' => 'http://127.0.0.1:2019/config/', 'budget_ms' => 5000, 'lacks' => ['"shop.orbit"']]);
});

it('sends the same guard on a retry, from the read its step recorded', function (): void {
    $flow = app(RouteDestroyEngineFlow::class);
    $run = destroyRun(['caddyread-3' => plainFile(DestroyBefore)]);

    expect(json_encode($flow->input(routeDestroyOperation(), 'withdraw-3', $run)))->toBe(json_encode($flow->input(routeDestroyOperation(), 'withdraw-3', $run)));
});

it('refuses a Caddyfile changed outside the Route as owned_revision_conflict, and passes the rendered-from or desired one', function (): void {
    $flow = app(RouteDestroyEngineFlow::class);
    $guard = fn (array $read) => $flow->local(routeDestroyOperation(), 'guard-3', destroyRun(['caddyread-3' => $read]));

    $conflict = fn (EngineStepRefusal $refusal) => expect($refusal->errorCode)->toBe('owned_revision_conflict');

    expect($guard(plainFile(DestroyBefore)))->toBeString()
        ->and($guard(plainFile(DestroyAfter)))->toBeString()
        // Another publisher (a role converge, another Route) changed the Caddyfile after admission: refused, never overwritten.
        ->and(fn () => $guard(plainFile("other.orbit {\n}\nadded.orbit {\n}\n")))->toThrow($conflict)
        ->and(fn () => $guard(['state' => 'absent']))->toThrow($conflict)
        ->and(fn () => $guard(['state' => 'present', 'type' => 'directory', 'uid' => 0, 'gid' => 0, 'mode' => '0755']))->toThrow($conflict)
        ->and($guard(['state' => 'present', 'type' => 'symlink', 'uid' => 0, 'gid' => 0, 'mode' => '0777']))->toContain('link');
});

it('keeps main\'s DNS refusal: a file changed after admission, such as a sibling added since, is guarded by the admission digest', function (): void {
    $flow = app(RouteDestroyEngineFlow::class);
    $admitted = hash('sha256', "address=/shop.example/10.44.0.7\naddress=/other.example/10.44.0.8\n");
    $sibling = "address=/shop.example/10.44.0.7\naddress=/other.example/10.44.0.8\naddress=/new.example/10.44.0.9\n";
    $files = fn (array $records, array $catalog): array => $flow->input(routeDestroyOperation(), 'dns-0',
        destroyRun(['dnsread-0-records' => $records, 'dnsread-0-catalog' => $catalog]))['files'];

    // config.apply refuses the write (owned_revision_conflict) because the live bytes are not the admission render.
    expect(array_column($files(plainFile($sibling), plainFile("{\"shop\":1,\"new\":1}\n")), 'from_sha256'))->toBe([$admitted, hash('sha256', "{\"shop\":1}\n")])
        // A retry that finds the desired bytes already written keeps them.
        ->and(array_column($files(plainFile("address=/other.example/10.44.0.8\n"), plainFile("{}\n")), 'from_sha256'))
        ->toBe([hash('sha256', "address=/other.example/10.44.0.8\n"), hash('sha256', "{}\n")]);
});

it('removes a link in a file\'s place without following it, then writes the file without a guard', function (): void {
    $flow = app(RouteDestroyEngineFlow::class);
    $link = ['state' => 'present', 'type' => 'symlink', 'uid' => 0, 'gid' => 0, 'mode' => '0777'];
    $run = destroyRun(['dnsread-0-records' => $link, 'dnsread-0-catalog' => plainFile("{\"shop\":1}\n"), 'caddyread-3' => $link]);

    expect($flow->needed(routeDestroyOperation(), 'dnsunlink-0-records', $run))->toBeTrue()
        ->and($flow->needed(routeDestroyOperation(), 'dnsunlink-0-catalog', $run))->toBeFalse()
        ->and($flow->needed(routeDestroyOperation(), 'caddyunlink-3', $run))->toBeTrue()
        ->and($flow->needed(routeDestroyOperation(), 'dns-0', $run))->toBeTrue()
        ->and($flow->input(routeDestroyOperation(), 'dnsunlink-0-records', $run))->toBe(['state' => 'absent', 'path' => '/etc/dnsmasq.d/orbit-records.conf'])
        ->and($flow->input(routeDestroyOperation(), 'caddyunlink-3', $run))->toBe(['state' => 'absent', 'path' => '/etc/caddy/Caddyfile'])
        ->and(array_column($flow->input(routeDestroyOperation(), 'dns-0', $run)['files'], 'from_sha256'))->toBe([null, hash('sha256', "{\"shop\":1}\n")])
        ->and($flow->input(routeDestroyOperation(), 'withdraw-3', $run)['files'][0]['from_sha256'])->toBeNull();
});

it('refuses any other kind in a DNS file\'s place', function (): void {
    $flow = app(RouteDestroyEngineFlow::class);
    $run = destroyRun(['dnsread-0-records' => ['state' => 'present', 'type' => 'directory', 'uid' => 0, 'gid' => 0, 'mode' => '0755']]);

    expect($flow->needed(routeDestroyOperation(), 'dnsunlink-0-records', $run))->toBeTrue()
        ->and(fn () => $flow->input(routeDestroyOperation(), 'dnsunlink-0-records', $run))->toThrow(EngineStepRefusal::class);
});

it('leaves a removal the engine cannot reproduce exactly to Swamp', function (array $plan, array $input): void {
    app(RouteDestroyEngineFlow::class)->steps(routeDestroyOperation($plan, $input));
})->throws(EngineUnsupported::class)->with([
    '--offline' => [['offline' => true], ['offline' => true]],
    'a LAN rule' => [['lan_remove' => [['node_id' => 3, 'port' => 8080, 'protocol' => 'tcp', 'source' => '192.168.1.0/24']]], []],
    'the Ingress edge' => [['ingress_close' => [['node_id' => 6, 'port' => 443]]], []],
    'an APP_URL write for a surviving Route' => [['retire' => ['acl' => [], 'links' => [], 'lan' => [], 'env_files' => [], 'site_nodes' => [3],
        'caddy' => [['node_id' => 3, 'content' => "x\n"]], 'url_lines' => [['node_id' => 3, 'path' => '/home/orbit/apps/shop/.env', 'line' => 'APP_URL=https://x.orbit', 'owner' => 'orbit']]]], []],
    'access rows' => [['retire' => ['acl' => [['node_id' => 3, 'path' => '/home/orbit/apps/shop']], 'links' => [], 'lan' => [], 'env_files' => [], 'url_lines' => [], 'site_nodes' => [3],
        'caddy' => [['node_id' => 3, 'content' => "x\n"]]]], []],
    'a leaf folder, which needs the account home' => [['cleanup' => [['node_id' => 3, 'leaf' => ['user' => 'orbit', 'scope' => 'app-instance-7']]]], []],
    'a pool, which needs the account group' => [['pools' => ['acl' => [], 'url_lines' => [], 'env_files' => [], 'links' => [], 'lan' => [], 'site_nodes' => [3],
        'php_files' => [['node_id' => 3, 'path' => '/etc/php/8.4/fpm/pool.d/orbit-scopes.conf', 'content' => "[orbit]\n", 'owner' => 'root']], 'php_tests' => [], 'php_reloads' => []]], []],
    'a never-activated replacement' => [[], ['route_removal' => ['routes' => [['route_id' => 9, 'domain' => 'new.orbit', 'replacement_of' => 4]], 'dns_node_ids' => [1]]]],
    'no captured file digests' => [[], ['route_guards' => []]],
    'a public Route or a Process proxy behind the Ingress' => [[], ['route_private_development' => false]],
    'a production release' => [[], ['production_control' => ['instance_id' => 4, 'release' => 'current']]],
    'a production Instance on the Node' => [[], ['route_removal' => ['routes' => [['route_id' => 9, 'domain' => 'shop.orbit', 'replacement_of' => null]], 'dns_node_ids' => [1],
        'nodes' => [['node_id' => 3, 'production_instance_ids' => [4]]]]]],
    'a PHP version whose last pool leaves' => [[], ['route_guards' => ['3' => ['caddy' => hash('sha256', DestroyBefore), 'php_versions' => '8.4,8.5', 'php_versions_after' => '8.4'],
        '1' => ['records' => hash('sha256', 'x'), 'catalog' => hash('sha256', 'y')]]]],
]);

it('leaves a removal to Swamp when a Node with work is no development Node', function (string $node): void {
    Node::query()->find($node === 'serving' ? 3 : 1)->roles()->update(['role' => RoleName::AppProd]);

    app(RouteDestroyEngineFlow::class)->steps(routeDestroyOperation());
})->throws(EngineUnsupported::class)->with(['serving', 'vpn']);

it('starts on the engine only when every Node with work reports its primitives', function (array $actions, bool $engine): void {
    config(['orbit.engine.kinds' => ['route.destroy']]);
    $bus = new class($actions) implements MachineBus
    {
        public function __construct(private array $actions) {}

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
    $this->app->instance(MachineBus::class, $bus);

    expect(app(EngineKinds::class)->routes(routeDestroyOperation()))->toBe($engine);
})->with([
    'both report' => [[1 => ['file.read', 'file.ensure', 'config.apply'], 3 => ['file.read', 'file.ensure', 'config.apply', 'http.probe']], true],
    'a VPN Node before agent 0.36.0' => [[1 => ['dns.publish_records'], 3 => ['file.read', 'file.ensure', 'config.apply', 'http.probe']], false],
    'a Route Node without http.probe' => [[1 => ['file.read', 'file.ensure', 'config.apply'], 3 => ['file.read', 'file.ensure', 'config.apply']], false],
]);

it('ends the grace 31 s after the last DNS receipt, and fails it without one', function (): void {
    $this->freezeTime();
    $flow = app(RouteDestroyEngineFlow::class);
    $at = static fn (string $name, int $ms): OperationEngineStep => (new OperationEngineStep)->forceFill(['name' => $name, 'status' => 'done', 'done_ms' => $ms]);
    $now = (int) now()->getTimestampMs();
    $run = new EngineRun(51, ['dns-0' => $at('dns-0', $now - 40_000), 'dns-1' => $at('dns-1', $now - 31_500)], 0, null);

    expect($flow->waitUntilMs(routeDestroyOperation(), 'grace', $run))->toBe($now - 500)
        ->and($flow->waitUntilMs(routeDestroyOperation(), 'withdraw-3', $run))->toBeNull()
        ->and($flow->waitUntilMs(routeDestroyOperation(), 'grace', new EngineRun(51, [], 0, null)))->toBeNull()
        ->and($flow->local(routeDestroyOperation(), 'grace', $run))->toBe('waited 31500 ms after the DNS receipt')
        ->and(fn () => $flow->local(routeDestroyOperation(), 'grace', new EngineRun(51, [], 0, null)))->toThrow(RuntimeException::class, 'route.dns_grace_failed');
});

it('names a failed step as the Swamp chart\'s iteration, so the Route records the same error code', function (): void {
    expect(RouteDestroyEngineFlow::chartStep('dns-0'))->toBe('Remove private DNS-0')
        ->and(RouteDestroyEngineFlow::chartStep('dnsread-0-catalog'))->toBe('Remove private DNS-0')
        ->and(RouteDestroyEngineFlow::chartStep('dnsunlink-0-records'))->toBe('Remove private DNS-0')
        ->and(RouteDestroyEngineFlow::chartStep('caddyunlink-3'))->toBe('Withdraw site, remove cert-3')
        ->and(RouteDestroyEngineFlow::chartStep('grace'))->toBe('Wait the DNS grace-0')
        ->and(RouteDestroyEngineFlow::chartStep('caddyread-3'))->toBe('Withdraw site, remove cert-3')
        ->and(RouteDestroyEngineFlow::chartStep('guard-3'))->toBe('Withdraw site, remove cert-3')
        ->and(RouteDestroyEngineFlow::chartStep('withdraw-3'))->toBe('Withdraw site, remove cert-3')
        ->and(RouteDestroyEngineFlow::chartStep('poll-3'))->toBe('Withdraw site, remove cert-3')
        ->and(RouteDestroyEngineFlow::chartStep('sibling-3'))->toBe('Check nothing is left-3');
});

it('signs every order in the wire shape the host primitives decode', function (): void {
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
    $run = destroyRun(['dnsread-0-records' => ['state' => 'absent'], 'dnsread-0-catalog' => ['state' => 'absent'], 'caddyread-3' => plainFile(DestroyBefore)]);
    $withdraw = EngineContent::upload($bus, 3, $flow->input(routeDestroyOperation(), 'withdraw-3', $run));

    expect($withdraw['files'][0]['content'])->toBe(['blob' => ['sha256' => hash('sha256', DestroyAfter)]])
        ->and(EngineContent::upload($bus, 1, $flow->input(routeDestroyOperation(), 'dns-0', $run))['files'][1]['content'])->toBe(['blob' => ['sha256' => hash('sha256', "{}\n")]]);
});
