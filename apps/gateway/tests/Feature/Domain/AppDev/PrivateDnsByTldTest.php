<?php

declare(strict_types=1);

use App\Domain\AppDev\DnsQuestion;
use App\Domain\AppDev\DnsRecordType;
use App\Domain\AppDev\DnsRequester;
use App\Domain\Clusters\ClusterState;
use App\Domain\Nodes\RoleName;
use App\Domain\Routes\NativeRoutePublicationArtifacts;
use App\Domain\Routes\NativeRoutePublicationInput;
use App\Domain\Routes\RoutePublicationSites;
use App\Domain\Shared\LifecycleStatus;
use App\Infrastructure\AppDev\DevelopmentDnsConfigRenderer;
use App\Infrastructure\AppDev\DevelopmentSite;
use App\Models\Cluster;
use App\Models\Node;
use App\Models\NodeRole;
use App\Models\Project;
use App\Models\Route;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;

uses(LazilyRefreshDatabase::class);

/*
 * Private DNS answers by TLD (ADR 0234, docs/reference/private-dns.md#records-a-tld-already-answers): a record that a TLD rule
 * already answers with the same address, for every requester, is not written. The fleet is shaped as the live one on 2026-11-15:
 * Cluster `dev` with TLD `test`, Router beast with a LAN address, members sabre and shark (and quiet without a LAN address), the vpn Node, the Gateway, and a WireGuard peer outside
 * the Cluster. The golden file holds every requester x name answer of the renderer before this change.
 */

const PRIVATE_DNS_GOLDEN = __DIR__.'/../../../Fixtures/private-dns-by-tld-answers.json';

/** @return array{beast: Node, sabre: Node, shark: Node, cluster: Cluster, project: Project} */
function liveShapedDnsFleet(): array
{
    $cluster = Cluster::query()->create(['name' => 'dev', 'tld' => 'test']);
    $cluster->forceFill(['state' => ClusterState::Active])->save();
    $node = static fn (string $name, string $wireguard, ?string $lan, ?int $clusterId, array $roles): Node => tap(
        Node::withoutEvents(fn (): Node => Node::query()->create(['name' => $name, 'status' => 'active', 'platform' => 'linux', 'architecture' => 'x86_64',
            'public_ssh_host' => $wireguard, 'wireguard_ip' => $wireguard, 'lan_ip' => $lan, 'user' => 'orbit', 'cluster_id' => $clusterId,
            'wireguard_public_key' => $name.'-key', 'tld' => $name === 'beast' ? 'test' : null])),
        static function (Node $created) use ($roles, $clusterId): void {
            foreach ($roles as $role) {
                NodeRole::query()->create(['node_id' => $created->id, 'role' => $role, 'status' => LifecycleStatus::Active,
                    'cluster_id' => $role === RoleName::Router ? $clusterId : null]);
            }
        },
    );
    $gateway = $node('gateway', '10.44.0.2', null, null, [RoleName::Gateway]);
    // The vpn role on its own Node, as live (Node 1).
    $node('vpn', '10.44.0.1', null, null, [RoleName::Vpn]);
    $beast = $node('beast', '10.44.0.7', '192.168.6.20', $cluster->id, [RoleName::Router, RoleName::AppDev]);
    $sabre = $node('sabre', '10.44.0.10', '192.168.6.21', $cluster->id, [RoleName::AppDev]);
    $shark = $node('shark', '10.44.0.11', '192.168.6.22', $cluster->id, [RoleName::AppDev]);
    $node('mini', '10.44.0.9', null, null, []);
    // A member without a LAN address: it gets the Router's WireGuard address, as every requester outside the Cluster.
    $node('quiet', '10.44.0.12', null, $cluster->id, [RoleName::AppDev]);
    $project = Project::query()->create(['name' => 'proof', 'slug' => 'ihj-proof-h42', 'repository_url' => 'https://github.com/laravel/laravel.git', 'default_branch' => 'main', 'root' => 'public']);

    return ['beast' => $beast, 'sabre' => $sabre, 'shark' => $shark, 'cluster' => $cluster, 'project' => $project, 'gateway' => $gateway];
}

/**
 * The sites of the live shape: ten clone Routes of the Cluster served by the Router, a Node-scope Route on beast and one on sabre
 * inside the TLD, and an explicit domain outside every TLD.
 *
 * @return list<DevelopmentSite>
 */
function liveShapedDnsSites(array $fleet): array
{
    $site = static fn (Node $node, string $domain): DevelopmentSite => new DevelopmentSite($node->id, (string) $node->wireguard_ip, str_replace('.', '-', $domain),
        '/srv/'.$domain, '/srv/'.$domain.'/public', null, $domain);
    $sites = [];
    foreach (range(1, 10) as $index) {
        $domain = "r{$index}.ihj-proof-h42.test";
        Route::query()->create(['kind' => 'app', 'project_id' => $fleet['project']->id, 'cluster_id' => $fleet['cluster']->id, 'provenance' => 'explicit',
            'publication' => 'private', 'domain' => $domain, 'status' => 'pending']);
        $sites[] = $site($fleet['beast'], $domain);
    }
    foreach ([['beast', 'node-beast.ihj-proof-h42.test'], ['sabre', 'node-sabre.ihj-proof-h42.test'], ['beast', 'shop.example.com']] as [$name, $domain]) {
        Route::query()->create(['kind' => 'app', 'project_id' => $fleet['project']->id, 'node_id' => $fleet[$name]->id, 'provenance' => 'explicit',
            'publication' => 'private', 'domain' => $domain, 'status' => 'pending']);
        $sites[] = $site($fleet[$name], $domain);
    }

    return $sites;
}

/** @return array{records: string, answers: array<string, array<string, ?string>>} */
function privateDnsMatrix(array $fleet, array $sites, array $clusterOverrides = []): array
{
    return app(RoutePublicationSites::class)->render(collect($sites), static function () use ($sites, $clusterOverrides): array {
        $renderer = app(DevelopmentDnsConfigRenderer::class);
        $catalog = $renderer->catalog(null, [], $clusterOverrides);
        $requesters = ['node:beast' => DnsRequester::registered(Node::query()->where('name', 'beast')->value('id'), '10.44.0.7'),
            'node:sabre' => DnsRequester::registered(Node::query()->where('name', 'sabre')->value('id'), '10.44.0.10'),
            'node:shark' => DnsRequester::registered(Node::query()->where('name', 'shark')->value('id'), '10.44.0.11'),
            'node:mini' => DnsRequester::registered(Node::query()->where('name', 'mini')->value('id'), '10.44.0.9'),
            'node:gateway' => DnsRequester::registered(Node::query()->where('name', 'gateway')->value('id'), '10.44.0.2'),
            'node:vpn' => DnsRequester::registered(Node::query()->where('name', 'vpn')->value('id'), '10.44.0.1'),
            'node:quiet' => DnsRequester::registered(Node::query()->where('name', 'quiet')->value('id'), '10.44.0.12'),
            'unidentified' => DnsRequester::unidentified('10.44.0.200')];
        $names = [...array_map(static fn (DevelopmentSite $site): string => $site->domain, $sites), 'test', 'unknown.ihj-proof-h42.test', 'gateway.orbit', 'metrics.orbit', 'nothing.example.org'];
        $answers = [];
        foreach ($requesters as $label => $requester) {
            foreach ($names as $name) {
                $answers[$label][$name] = $catalog->addressFor(new DnsQuestion($name, DnsRecordType::A), $requester);
            }
        }

        return ['records' => $renderer->render(null, [], $clusterOverrides), 'answers' => $answers];
    }, complete: true);
}

it('answers every requester and name exactly as before, also while a Router change renders another Router', function (): void {
    $fleet = liveShapedDnsFleet();
    $sites = liveShapedDnsSites($fleet);
    $answers = ['current' => privateDnsMatrix($fleet, $sites)['answers'],
        'router-change' => privateDnsMatrix($fleet, $sites, [$fleet['cluster']->id => ['router_node_id' => $fleet['sabre']->id]])['answers']];
    if (getenv('ORBIT_WRITE_DNS_GOLDEN') === '1') {
        file_put_contents(PRIVATE_DNS_GOLDEN, json_encode($answers, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n");
    }

    expect($answers)->toBe(json_decode((string) file_get_contents(PRIVATE_DNS_GOLDEN), true));
});

it('writes no record a TLD already answers, and keeps every other one', function (): void {
    $fleet = liveShapedDnsFleet();
    $records = privateDnsMatrix($fleet, liveShapedDnsSites($fleet))['records'];

    // The Cluster's Routes are answered by `address=/.test/` with the Router's address: no line each.
    expect($records)->toContain('address=/.test/10.44.0.7', 'local=/test/')
        ->and($records)->not->toContain('host-record=r1.ihj-proof-h42.test')
        // A member asks for a Node Route on the Router: without the record its LAN override would answer, so the record stays.
        ->and($records)->toContain('host-record=node-beast.ihj-proof-h42.test,10.44.0.7')
        // A site on another Node, a name outside every TLD and a platform name keep theirs.
        ->and($records)->toContain('host-record=node-sabre.ihj-proof-h42.test,10.44.0.10', 'host-record=shop.example.com,10.44.0.7',
            'host-record=gateway.orbit,10.44.0.2');
});

it('gives the members no LAN override for a Cluster Route that the TLD override already answers', function (): void {
    $fleet = liveShapedDnsFleet();
    $sites = liveShapedDnsSites($fleet);
    $overrides = app(RoutePublicationSites::class)->render(collect($sites), static fn (): array => app(DevelopmentDnsConfigRenderer::class)->catalog()->overrides, complete: true);

    expect($overrides)->toHaveKey('node:'.$fleet['sabre']->id);
    foreach ($overrides as $names) {
        expect($names)->toBe(['test' => '192.168.6.20']);
    }
});

it('leaves the private DNS of the fleet unchanged when a Cluster Route under the TLD is added or removed', function (): void {
    $fleet = liveShapedDnsFleet();
    $sites = liveShapedDnsSites($fleet);
    $render = static fn (array $sites): array => app(RoutePublicationSites::class)->render(collect($sites), static function (): array {
        $renderer = app(DevelopmentDnsConfigRenderer::class);

        return [$renderer->render(), $renderer->catalog()->toPublished()];
    }, complete: true);
    $before = $render($sites);
    Route::query()->create(['kind' => 'app', 'project_id' => $fleet['project']->id, 'cluster_id' => $fleet['cluster']->id, 'provenance' => 'explicit',
        'publication' => 'private', 'domain' => 'r11.ihj-proof-h42.test', 'status' => 'pending']);
    $after = $render([...$sites, new DevelopmentSite($fleet['beast']->id, '10.44.0.7', 'r11', '/srv/r11', '/srv/r11/public', null, 'r11.ihj-proof-h42.test')]);

    // So a clone create or destroy captures equal before and after states: its plan has no private DNS work (NativeRouteCreatePlan).
    expect($after)->toBe($before);
});

it('writes the records again that a new Router no longer answers with the same address', function (): void {
    $fleet = liveShapedDnsFleet();
    $sites = liveShapedDnsSites($fleet);
    // A Router change renders the Cluster with its candidate: the TLD now answers with sabre, the sites still name beast.
    $records = app(RoutePublicationSites::class)->render(collect($sites), static fn (): string => app(DevelopmentDnsConfigRenderer::class)
        ->render(null, [], [$fleet['cluster']->id => ['router_node_id' => $fleet['sabre']->id]]), complete: true);

    expect($records)->toContain('address=/.test/10.44.0.10', 'host-record=r1.ihj-proof-h42.test,10.44.0.7', 'host-record=r10.ihj-proof-h42.test,10.44.0.7');
});

/**
 * What a removal of one site captures for the vpn Node, through the capture every Route withdrawal uses: whether its four DNS states
 * differ (route-withdraw then visits the Node; equal states intern as one artifact and it does not), and whether the vpn Node's render
 * before the removal holds a line or catalog entry of the site's domain.
 *
 * @param  list<DevelopmentSite>  $sites
 * @param  array<int, array<string, mixed>>  $clusterOverrides
 * @return array{visits: bool, holds: bool}
 */
function vpnWithdrawal(array $sites, string $domain, array $clusterOverrides = []): array
{
    $vpn = Node::query()->where('name', 'vpn')->firstOrFail();
    $render = static fn (array $sites): array => app(RoutePublicationSites::class)->render(collect($sites),
        static fn (): array => app(NativeRoutePublicationInput::class)->dns($vpn, null, [], $clusterOverrides), complete: true);
    $with = $render($sites);
    $without = $render(array_values(array_filter($sites, static fn (DevelopmentSite $site): bool => $site->domain !== $domain)));
    $state = static fn (array $dns): array => ['caddy' => ['content' => "# Managed by Orbit: Node Caddy build\n"], 'php' => ['configurations' => new stdClass],
        'dns' => $dns, 'certificate_scopes' => []];
    $captured = app(NativeRoutePublicationArtifacts::class)->capture([['node_id' => $vpn->id,
        'before' => $state($with), 'prepared' => $state($with), 'withdrawn' => $state($without), 'after' => $state($without)]])['publications'][0];
    $indexes = array_map(static fn (string $name): mixed => $captured[$name]['dns'], ['before', 'prepared', 'withdrawn', 'after']);

    return ['visits' => count(array_unique($indexes)) > 1,
        'holds' => str_contains($with['records'], "host-record={$domain},") || str_contains($with['catalog'], json_encode($domain, JSON_UNESCAPED_SLASHES))];
}

it('captures differing vpn DNS states for a removal exactly when the vpn Node holds a line of the Route', function (): void {
    $fleet = liveShapedDnsFleet();
    $sites = liveShapedDnsSites($fleet);
    // A Cluster Route outside the TLD: a record, and a LAN override for each member.
    Route::query()->create(['kind' => 'app', 'project_id' => $fleet['project']->id, 'cluster_id' => $fleet['cluster']->id, 'provenance' => 'explicit',
        'publication' => 'private', 'domain' => 'dns-rewrite.example.org', 'status' => 'pending']);
    $sites[] = new DevelopmentSite($fleet['beast']->id, '10.44.0.7', 'dns-rewrite', '/srv/dns-rewrite', '/srv/dns-rewrite/public', null, 'dns-rewrite.example.org');
    $cases = [
        // An in-TLD clone (fixture 69's shape), and a Route published before release 2091: today's render holds no line of either.
        'r1.ihj-proof-h42.test' => false,
        // Outside every TLD, as a Node Route and as a Cluster Route; a Node-scoped Route on the Router; a site on another Node.
        'shop.example.com' => true,
        'dns-rewrite.example.org' => true,
        'node-beast.ihj-proof-h42.test' => true,
        'node-sabre.ihj-proof-h42.test' => true,
    ];
    foreach ($cases as $domain => $visits) {
        expect(vpnWithdrawal($sites, $domain))->toBe(['visits' => $visits, 'holds' => $visits], $domain);
    }
    // During a Router change the in-TLD sites keep their lines, so their removal visits the vpn Node.
    expect(vpnWithdrawal($sites, 'r1.ihj-proof-h42.test', [$fleet['cluster']->id => ['router_node_id' => $fleet['sabre']->id]]))
        ->toBe(['visits' => true, 'holds' => true]);
    // A Cluster whose TLD changed since the Route was published: the capture renders the fleet as it is now, and the visit still
    // follows the line the render holds.
    $fleet['cluster']->forceFill(['tld' => 'dev'])->save();
    foreach (array_keys($cases) as $domain) {
        $outcome = vpnWithdrawal($sites, $domain);
        expect($outcome['visits'])->toBe($outcome['holds'], $domain);
    }
});

it('answers the TLD of an app-prod Node outside every Cluster, so a production preview Route under it writes no record', function (): void {
    $fleet = liveShapedDnsFleet();
    $prod = Node::withoutEvents(fn (): Node => Node::query()->create(['name' => 'app-prod', 'status' => 'active', 'platform' => 'linux', 'architecture' => 'x86_64',
        'public_ssh_host' => '10.44.0.3', 'wireguard_ip' => '10.44.0.3', 'user' => 'orbit', 'cluster_id' => null, 'wireguard_public_key' => 'app-prod-key', 'tld' => 'nested-prod']));
    NodeRole::query()->create(['node_id' => $prod->id, 'role' => RoleName::AppProd, 'status' => LifecycleStatus::Active]);
    Route::query()->create(['kind' => 'app', 'project_id' => $fleet['project']->id, 'node_id' => $prod->id, 'provenance' => 'explicit',
        'publication' => 'private', 'domain' => 'shop.nested-prod', 'status' => 'pending']);
    $sites = [...liveShapedDnsSites($fleet), new DevelopmentSite($prod->id, '10.44.0.3', 'shop-nested-prod', '/srv/shop', '/srv/shop/public', null, 'shop.nested-prod')];
    $records = app(RoutePublicationSites::class)->render(collect($sites), static fn (): string => app(DevelopmentDnsConfigRenderer::class)->render(), complete: true);

    expect($records)->toContain('address=/.nested-prod/10.44.0.3')
        ->and($records)->toContain('local=/nested-prod/')
        ->and($records)->not->toContain('host-record=shop.nested-prod');
});

it('gives an app-prod Node inside a Cluster no TLD rule, as the live production Node keeps its records', function (): void {
    $fleet = liveShapedDnsFleet();
    $cluster = Cluster::query()->create(['name' => 'prod', 'tld' => null]);
    $cluster->forceFill(['state' => ClusterState::Active])->save();
    $prod = Node::withoutEvents(fn (): Node => Node::query()->create(['name' => 'app-prod', 'status' => 'active', 'platform' => 'linux', 'architecture' => 'x86_64',
        'public_ssh_host' => '10.44.0.3', 'wireguard_ip' => '10.44.0.3', 'user' => 'orbit', 'cluster_id' => $cluster->id, 'wireguard_public_key' => 'app-prod-key', 'tld' => 'prod']));
    NodeRole::query()->create(['node_id' => $prod->id, 'role' => RoleName::AppProd, 'status' => LifecycleStatus::Active]);
    $records = app(RoutePublicationSites::class)->render(collect(liveShapedDnsSites($fleet)), static fn (): string => app(DevelopmentDnsConfigRenderer::class)->render(), complete: true);

    expect($records)->not->toContain('/prod/');
});
