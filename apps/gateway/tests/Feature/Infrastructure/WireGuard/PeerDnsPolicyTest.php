<?php

declare(strict_types=1);

use App\Actions\Doctor\NodeDoctorProbe;
use App\Domain\Doctor\DoctorIssueKind;
use App\Domain\Doctor\DoctorNodeContext;
use App\Domain\Doctor\NodeInspectionData;
use App\Domain\Fleet\NodeFootprintArtifact;
use App\Domain\Nodes\NodeProvisioningException;
use App\Domain\Nodes\RoleName;
use App\Domain\Routes\RouteKind;
use App\Domain\Routes\RouteProvenance;
use App\Domain\Routes\RoutePublication;
use App\Domain\Shared\LifecycleStatus;
use App\Domain\WireGuard\VpnSettings;
use App\Infrastructure\AgentCommands\AgentCommandRunner;
use App\Infrastructure\AgentCommands\AgentRunnerFailure;
use App\Infrastructure\Fleet\Footprint\PeerDnsFootprintArtifact;
use App\Infrastructure\WireGuard\PeerDnsInspector;
use App\Infrastructure\WireGuard\PeerDnsPolicy;
use App\Models\Cluster;
use App\Models\Node;
use App\Models\Project;
use App\Models\Route;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Tests\Support\FakeAgentCommandRunner;

it('renders every private namespace and changes it when records change', function (): void {
    Cluster::query()->create(['name' => 'dev', 'tld' => 'test']);
    $node = Node::query()->create(['name' => 'prod', 'public_ssh_host' => '192.0.2.3', 'tld' => 'prod.internal']);
    $private = Route::query()->create(['kind' => RouteKind::CustomProxy, 'node_id' => $node->id, 'domain' => 'app.example.com', 'provenance' => RouteProvenance::Explicit, 'publication' => RoutePublication::Private]);
    $project = Project::query()->create(['name' => 'public', 'slug' => 'public', 'repository_url' => 'https://github.com/example/public']);
    Route::query()->create(['kind' => RouteKind::App, 'project_id' => $project->id, 'node_id' => $node->id, 'domain' => 'public.example.com', 'provenance' => RouteProvenance::Explicit, 'publication' => RoutePublication::Public]);
    Route::query()->create(['kind' => RouteKind::CustomProxy, 'node_id' => $node->id, 'domain' => 'ai-cli-proxy.test', 'provenance' => RouteProvenance::Explicit, 'publication' => RoutePublication::Private]);

    expect(app(PeerDnsPolicy::class)->domains())->toBe(['app.example.com', 'orbit', 'prod.internal', 'test']);

    $replacement = $private->replicate();
    $replacement->domain = 'new.example.com';
    $private->delete();
    $replacement->save();
    $private = $replacement;
    expect(app(PeerDnsPolicy::class)->domains())->toBe(['new.example.com', 'orbit', 'prod.internal', 'test']);
    $private->delete();
    expect(app(PeerDnsPolicy::class)->domains())->toBe(['orbit', 'prod.internal', 'test']);
});

it('refuses malformed stored names before peer work', function (string $tld): void {
    DB::table('nodes')->insert(['name' => 'bad', 'public_ssh_host' => '192.0.2.3', 'tld' => $tld]);

    expect(fn () => app(PeerDnsPolicy::class)->domains())->toThrow(NodeProvisioningException::class);
})->with(['.', 'bad;command', 'bad..test', 'bad.test..', '-bad.test', str_repeat('a', 64).'.test']);

it('accepts the Orbit domain limit and refuses one more without truncating', function (): void {
    $rows = [];
    for ($i = 0; $i < 511; $i++) {
        $rows[] = ['name' => 'c'.$i, 'tld' => 't'.$i];
    }
    Cluster::query()->insert($rows);
    expect(app(PeerDnsPolicy::class)->domains())->toHaveCount(512);
    DB::table('clusters')->insert(['name' => 'overflow', 'tld' => 'overflow']);

    expect(fn () => app(PeerDnsPolicy::class)->domains())->toThrow(NodeProvisioningException::class);
});

it('reports live and persisted resolver drift and failed private answers', function (string $fault, bool $policy, bool $answers): void {
    [$node, $peer] = peer_dns_inspection_fixture();
    $peer->fault = $fault;

    expect(app(PeerDnsInspector::class)->inspect($node))->toBe(['policy' => $policy, 'uplink' => true]);

    $report = app(NodeDoctorProbe::class)->inspect(new DoctorNodeContext($node, new NodeInspectionData(true, 'linux', 'x86_64', true)));
    $codes = array_map(static fn ($issue): string => $issue->code, $report->issues);
    expect(in_array('node.private_dns_policy_mismatch', $codes, true))->toBe(! $policy)
        ->and(in_array('node.private_dns_unreachable', $codes, true))->toBe(! $answers);
})->with([
    'healthy split DNS' => ['', true, true],
    'old route-all hook' => ['hook', false, true],
    'stale live domains' => ['domains', false, true],
    'stale saved domains' => ['saved', false, true],
    'wrong live server' => ['server', false, true],
    'default route enabled' => ['default', false, true],
    'server down' => ['answer', true, false],
    'wrong private answer' => ['wrong-answer', true, false],
]);

it('verifies uncached private and public lookups on separate resolver links', function (string $fault, int $exit): void {
    [$node, $peer] = peer_dns_inspection_fixture();
    Cluster::query()->create(['name' => 'dev', 'tld' => 'test']);
    $peer->fault = $fault;

    $this->artisan('orbit:node-dns-check', ['name' => $node->name, 'private-domain' => 'ai-cli-proxy.test', '--public-domain' => 'example.com'])->assertExitCode($exit);
})->with(['split' => ['', 0], 'public over VPN' => ['public-over-vpn', 1], 'private query fails' => ['private-fails', 1], 'global public DNS' => ['global', 0], 'public query fails' => ['public-fails', 1]]);

it('refuses a global underlay DNS server before inspecting a peer', function (): void {
    [$node] = peer_dns_inspection_fixture();
    app(VpnSettings::class)->configure(subnet: '10.44.0.0/24', dnsServer: '10.0.0.2');
    expect(fn () => app(PeerDnsPolicy::class)->server($node))->toThrow(NodeProvisioningException::class, 'The private DNS server must be a usable address in the WireGuard subnet.');
});

it('changes the peer footprint digest when the private namespace changes and registers it in production', function (): void {
    [$node] = peer_dns_inspection_fixture();
    $artifact = app(PeerDnsFootprintArtifact::class);
    $before = $artifact->digest($node);
    Cluster::query()->create(['name' => 'dev', 'tld' => 'test']);

    expect($artifact->applies($node))->toBeTrue()->and($artifact->digest($node))->not->toBe($before)
        ->and(array_map(static fn ($entry): string => $entry->name(), [...app()->tagged(NodeFootprintArtifact::class)]))->toContain('peer-dns');
});

/** @return array{Node, object{fault: string, domains: list<string>, agents: FakeAgentCommandRunner}} */
function peer_dns_inspection_fixture(): array
{
    $gateway = Node::query()->create(['name' => 'gateway', 'public_ssh_host' => '192.0.2.1', 'wireguard_ip' => '10.44.0.1', 'status' => LifecycleStatus::Active]);
    $gateway->roles()->create(['role' => RoleName::Gateway]);
    $gateway->roles()->create(['role' => RoleName::Vpn]);
    $node = Node::query()->create(['name' => 'app-prod', 'public_ssh_host' => '192.0.2.3', 'wireguard_ip' => '10.44.0.3', 'wireguard_public_key' => str_repeat('A', 43).'=', 'ssh_host_fingerprint' => 'SHA256:managed', 'status' => LifecycleStatus::Active]);
    $node->roles()->create(['role' => RoleName::AppProd]);
    $peer = new class
    {
        public string $fault = '';

        /** @var list<string> */
        public array $domains = ['orbit'];

        public FakeAgentCommandRunner $agents;
    };
    $peer->agents = new FakeAgentCommandRunner;
    $unreachable = static fn (string $command): AgentRunnerFailure => new AgentRunnerFailure($command, AgentRunnerFailure::Transport, 'SSH unavailable');
    $peer->agents->answer('wireguard.inspect-peer-dns', static function () use ($peer, $unreachable): array {
        if ($peer->fault === 'ssh') {
            throw $unreachable('wireguard.inspect-peer-dns');
        }

        return [
            'state' => "orbit\n10.44.0.1\n".($peer->fault === 'saved' ? '.' : implode("\n", $peer->domains)),
            'hooks' => 'PostUp = resolvectl dns %i 10.44.0.1; resolvectl domain %i '.($peer->fault === 'hook' ? '\\~.' : implode(' ', array_map(static fn (string $domain): string => '\\~'.$domain, $peer->domains))),
            'dns' => 'Link 7 (orbit): '.($peer->fault === 'server' ? '10.0.0.2' : '10.44.0.1'),
            'domains' => 'Link 7 (orbit): '.($peer->fault === 'domains' ? '~.' : implode(' ', array_map(static fn (string $domain): string => '~'.$domain, $peer->domains))),
            'default_route' => 'Link 7 (orbit): '.($peer->fault === 'default' ? 'yes' : 'no'),
            'uplink' => $peer->fault !== 'uplink-missing',
        ];
    });
    $peer->agents->answer('wireguard.query-dns', static function (array $input) use ($peer, $unreachable): array {
        if (in_array($peer->fault, ['ssh', 'query-ssh'], true)) {
            throw $unreachable('wireguard.query-dns');
        }

        return ['answers' => array_map(static fn (string $name): string => str_starts_with($name, 'example.')
            ? ($peer->fault === 'public-fails' ? '' : 'example.com: 93.184.215.14'.($peer->fault === 'global' ? '' : ' -- link: '.($peer->fault === 'public-over-vpn' ? 'orbit' : 'eth0')))
            : (in_array($peer->fault, ['private-fails', 'answer'], true) ? '' : $name.': '.($peer->fault === 'wrong-answer' ? '192.0.2.99' : ($name === 'gateway.orbit' ? '10.44.0.1' : '10.44.0.7')).' -- link: orbit'), $input['names'])];
    });
    app()->instance(AgentCommandRunner::class, $peer->agents);

    return [$node->refresh()->load('roles'), $peer];
}

it('excludes the Gateway after VPN moves to another Node', function (): void {
    [$node] = peer_dns_inspection_fixture();
    $node->roles()->create(['role' => RoleName::Gateway]);
    $node->refresh()->load('roles');

    expect(app(PeerDnsPolicy::class)->manages($node))->toBeFalse()
        ->and(app(PeerDnsFootprintArtifact::class)->applies($node))->toBeFalse();
});

it('reports SSH inspection failure as unverifiable without attempting repair', function (): void {
    [$node, $peer] = peer_dns_inspection_fixture();
    $peer->fault = 'ssh';
    $report = app(NodeDoctorProbe::class)->inspect(new DoctorNodeContext($node, new NodeInspectionData(true, 'linux', 'x86_64', true)));
    $dnsIssues = array_values(array_filter($report->issues, static fn ($issue): bool => str_contains($issue->summary, 'DNS')));

    expect($dnsIssues)->toHaveCount(1)
        ->and($dnsIssues[0]->code)->toBe('node.inspection_failed')
        ->and($dnsIssues[0]->kind)->toBe(DoctorIssueKind::Unverifiable);
});

it('checks public resolution in Doctor including global DNS answers', function (string $fault, bool $failed): void {
    [$node, $peer] = peer_dns_inspection_fixture();
    $peer->fault = $fault;

    $report = app(NodeDoctorProbe::class)->inspect(new DoctorNodeContext($node, new NodeInspectionData(true, 'linux', 'x86_64', true)));
    $codes = array_map(static fn ($issue): string => $issue->code, $report->issues);
    expect(in_array('node.public_dns_unreachable', $codes, true))->toBe($failed);
})->with([['public-fails', true], ['public-over-vpn', true], ['global', false], ['', false]]);

it('refuses invalid names and overflow at each namespace write boundary without saving', function (string $kind): void {
    $node = Node::query()->create(['name' => 'prod', 'public_ssh_host' => '192.0.2.3']);
    $candidate = match ($kind) {
        'node' => $node->fill(['tld' => 'bad;name']),
        'cluster' => new Cluster(['name' => 'dev', 'tld' => 'bad;name']),
        'route' => new Route(['kind' => RouteKind::CustomProxy, 'node_id' => $node->id, 'domain' => 'bad;name', 'provenance' => RouteProvenance::Explicit, 'publication' => RoutePublication::Private]),
    };
    expect(fn () => $candidate->save())->toThrow(ValidationException::class);
    expect(app(PeerDnsPolicy::class)->domains())->toBe(['orbit']);
    $rows = [];
    for ($i = 0; $i < 511; $i++) {
        $rows[] = ['name' => 'c'.$i, 'tld' => 't'.$i];
    }
    Cluster::query()->insert($rows);
    $candidate->setAttribute($kind === 'route' ? 'domain' : 'tld', 'overflow.example');
    expect(fn () => $candidate->save())->toThrow(ValidationException::class);
    expect(app(PeerDnsPolicy::class)->domains())->toHaveCount(512);
    $candidate->setAttribute($kind === 'route' ? 'domain' : 'tld', 'covered.t0');
    expect($candidate->save())->toBeTrue();
    expect(app(PeerDnsPolicy::class)->domains())->toHaveCount(512);
})->with(['node', 'cluster', 'route']);

it('refuses removal of a covering namespace when its descendants exceed the cap', function (string $kind): void {
    $node = Node::query()->create(['name' => 'prod', 'public_ssh_host' => '192.0.2.3']);
    $cover = match ($kind) {
        'node' => $node->fill(['tld' => 'example.test']),
        'cluster' => new Cluster(['name' => 'dev', 'tld' => 'example.test']),
        'route' => new Route(['kind' => RouteKind::CustomProxy, 'node_id' => $node->id, 'domain' => 'example.test', 'provenance' => RouteProvenance::Explicit, 'publication' => RoutePublication::Private]),
    };
    $cover->save();
    $rows = [];
    for ($i = 0; $i < 512; $i++) {
        $rows[] = ['name' => 'c'.$i, 'tld' => 't'.$i.'.example.test'];
    }
    Cluster::query()->insert($rows);

    expect(fn () => $cover->delete())->toThrow(ValidationException::class);
    $this->assertModelExists($cover);
    expect(app(PeerDnsPolicy::class)->domains())->toBe(['example.test', 'orbit']);
})->with(['node', 'cluster', 'route']);

it('chooses a public Doctor probe outside private Route suffixes', function (): void {
    [$node, $peer] = peer_dns_inspection_fixture();
    Route::query()->create(['kind' => RouteKind::CustomProxy, 'node_id' => $node->id, 'domain' => 'example.com', 'provenance' => RouteProvenance::Explicit, 'publication' => RoutePublication::Private]);

    $report = app(NodeDoctorProbe::class)->inspect(new DoctorNodeContext($node, new NodeInspectionData(true, 'linux', 'x86_64', true)));
    $codes = array_map(static fn ($issue): string => $issue->code, $report->issues);
    expect($codes)->not->toContain('node.private_dns_unreachable', 'node.public_dns_unreachable', 'node.inspection_failed');
    expect($peer->agents->calls[array_key_last($peer->agents->calls)]['input']['names'])->toBe(['gateway.orbit', 'example.net']);
});

it('reports a lookup transport failure as unverifiable', function (): void {
    [$node, $peer] = peer_dns_inspection_fixture();
    $peer->fault = 'query-ssh';

    $report = app(NodeDoctorProbe::class)->inspect(new DoctorNodeContext($node, new NodeInspectionData(true, 'linux', 'x86_64', true)));
    $dnsIssues = array_values(array_filter($report->issues, static fn ($issue): bool => str_contains($issue->summary, 'DNS')));
    expect($dnsIssues)->toHaveCount(1)
        ->and($dnsIssues[0]->code)->toBe('node.inspection_failed')
        ->and($dnsIssues[0]->kind)->toBe(DoctorIssueKind::Unverifiable);
});

it('inspects the complete maximum namespace without truncating the snapshot', function (): void {
    [$node, $peer] = peer_dns_inspection_fixture();
    $rows = [];
    for ($i = 0; $i < 511; $i++) {
        $domain = str_repeat('a', 63).'.'.str_repeat('b', 63).'.'.str_repeat('c', 63).'.'.str_pad((string) $i, 61, 'x', STR_PAD_LEFT);
        $rows[] = ['name' => 'c'.$i, 'tld' => $domain];
        $peer->domains[] = $domain;
    }
    Cluster::query()->insert($rows);
    sort($peer->domains, SORT_STRING);

    expect(app(PeerDnsInspector::class)->inspect($node))->toBe(['policy' => true, 'uplink' => true]);
});

it('names missing uplink DNS in Doctor', function (): void {
    [$node, $peer] = peer_dns_inspection_fixture();
    $peer->fault = 'uplink-missing';
    $report = app(NodeDoctorProbe::class)->inspect(new DoctorNodeContext($node, new NodeInspectionData(true, 'linux', 'x86_64', true)));
    expect(array_map(static fn ($issue): string => $issue->code, $report->issues))->toContain('node.private_dns_uplink_missing');
});

it('allows nonincreasing teardown and TLD rollback when stored namespace already exceeds the cap', function (string $kind): void {
    $node = Node::query()->create(['name' => 'prod', 'public_ssh_host' => '192.0.2.3', 'tld' => 'original.internal']);
    $cover = match ($kind) {
        'node' => $node,
        'cluster' => Cluster::query()->create(['name' => 'dev', 'tld' => 'dev.internal']),
        'route' => Route::query()->create(['kind' => RouteKind::CustomProxy, 'node_id' => $node->id, 'domain' => 'app.example.com', 'provenance' => RouteProvenance::Explicit, 'publication' => RoutePublication::Private]),
    };
    $rows = [];
    for ($i = 0; $i < 513; $i++) {
        $rows[] = ['name' => 'c'.$i, 'tld' => 't'.$i];
    }
    Cluster::query()->insert($rows);
    if ($kind === 'node') {
        expect($node->update(['tld' => 'changed.internal']))->toBeTrue();
        expect($node->update(['tld' => 'original.internal']))->toBeTrue();
        expect($node->refresh()->tld)->toBe('original.internal');
    }
    expect($cover->delete())->toBeTrue();
    $this->assertModelMissing($cover);
    expect(fn () => Cluster::query()->create(['name' => 'growth', 'tld' => 'growth.internal']))->toThrow(ValidationException::class);
    expect(fn () => app(PeerDnsPolicy::class)->domains())->toThrow(NodeProvisioningException::class);
})->with(['node', 'cluster', 'route']);
