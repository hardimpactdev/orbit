<?php

declare(strict_types=1);

use App\Actions\Doctor\NodeDoctorProbe;
use App\Actions\Nodes\ReconcilePeerDnsAction;
use App\Actions\Nodes\RepairNodeDnsAction;
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
use App\Domain\WireGuard\WireGuardPeerDnsRepairer;
use App\Infrastructure\Fleet\Footprint\PeerDnsFootprintArtifact;
use App\Infrastructure\Processes\CommandResult;
use App\Infrastructure\Ssh\RemoteCommand;
use App\Infrastructure\Ssh\SshConnection;
use App\Infrastructure\Ssh\SshExecutor;
use App\Infrastructure\WireGuard\PeerDnsInspector;
use App\Infrastructure\WireGuard\PeerDnsPolicy;
use App\Models\Cluster;
use App\Models\Node;
use App\Models\Project;
use App\Models\Route;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

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

it('clears old DNS overrides without changing endpoints or contacting a peer', function (): void {
    $node = Node::query()->create(['name' => 'app-prod', 'public_ssh_host' => '192.0.2.3', 'dns_server_override' => '10.0.0.2', 'wireguard_endpoint_override' => '10.0.0.2:51820']);
    $migration = require database_path('migrations/2026_10_19_111105_reset_existing_node_dns_overrides.php');

    $migration->up();
    $migration->up();

    expect($node->refresh()->dns_server_override)->toBeNull()
        ->and($node->wireguard_endpoint_override)->toBe('10.0.0.2:51820');
});

it('reports live and persisted resolver drift and failed private answers', function (string $fault, bool $policy, bool $answers): void {
    [$node, $ssh] = peer_dns_inspection_fixture();
    $ssh->fault = $fault;

    expect(app(PeerDnsInspector::class)->inspect($node))->toBe(['policy' => $policy]);

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

it('propagates new TLDs on reconciliation and skips matching peers', function (): void {
    [$node] = peer_dns_inspection_fixture();
    $repairer = new class implements WireGuardPeerDnsRepairer
    {
        /** @var list<int> */
        public array $nodes = [];

        public function repair(Node $node): void
        {
            $this->nodes[] = $node->id;
        }
    };
    $action = new ReconcilePeerDnsAction(app(PeerDnsPolicy::class), app(PeerDnsInspector::class), new RepairNodeDnsAction($repairer));

    expect($action->execute())->toBe([])->and($repairer->nodes)->toBe([]);
    Cluster::query()->create(['name' => 'dev', 'tld' => 'test']);
    expect($action->execute())->toBe([])->and($repairer->nodes)->toBe([$node->id]);
});

it('continues reconciliation after one peer fails and reports the failed peer', function (): void {
    [$node, $ssh] = peer_dns_inspection_fixture();
    $second = $node->replicate();
    $second->name = 'second';
    $second->wireguard_ip = '10.44.0.4';
    $second->wireguard_public_key = str_repeat('B', 43).'=';
    $second->save();
    $second->roles()->create(['role' => RoleName::AppProd]);
    $ssh->fault = 'saved';
    $repairer = new class($node->id) implements WireGuardPeerDnsRepairer
    {
        /** @var list<int> */
        public array $nodes = [];

        public function __construct(private int $fail) {}

        public function repair(Node $node): void
        {
            $this->nodes[] = $node->id;
            if ($node->id === $this->fail) {
                throw new RuntimeException('peer unavailable');
            }
        }
    };
    $action = new ReconcilePeerDnsAction(app(PeerDnsPolicy::class), app(PeerDnsInspector::class), new RepairNodeDnsAction($repairer));

    expect($action->execute())->toBe([$node->name])->and($repairer->nodes)->toBe([$node->id, $second->id]);
});

it('verifies uncached private and public lookups on separate resolver links', function (string $fault, int $exit): void {
    [$node, $ssh] = peer_dns_inspection_fixture();
    Cluster::query()->create(['name' => 'dev', 'tld' => 'test']);
    $ssh->fault = $fault;

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

/** @return array{Node, SshExecutor&object{fault: string}} */
function peer_dns_inspection_fixture(): array
{
    $gateway = Node::query()->create(['name' => 'gateway', 'public_ssh_host' => '192.0.2.1', 'wireguard_ip' => '10.44.0.1', 'status' => LifecycleStatus::Active]);
    $gateway->roles()->create(['role' => RoleName::Gateway]);
    $gateway->roles()->create(['role' => RoleName::Vpn]);
    $node = Node::query()->create(['name' => 'app-prod', 'public_ssh_host' => '192.0.2.3', 'wireguard_ip' => '10.44.0.3', 'wireguard_public_key' => str_repeat('A', 43).'=', 'ssh_host_fingerprint' => 'SHA256:managed', 'status' => LifecycleStatus::Active]);
    $node->roles()->create(['role' => RoleName::AppProd]);
    $ssh = new class implements SshExecutor
    {
        public string $fault = '';

        /** @var list<string> */
        public array $domains = ['orbit'];

        /** @var list<RemoteCommand> */
        public array $commands = [];

        public function execute(SshConnection $connection, RemoteCommand $command): CommandResult
        {
            $args = $command->arguments;
            if ($this->fault === 'ssh' || ($this->fault === 'query-ssh' && ($args[1] ?? '') === 'query')) {
                return new CommandResult(255, '', 'SSH unavailable', 0, false);
            }
            $this->commands[] = $command;
            if (($args[0] ?? '') === 'sudo') {
                $values = [
                    "orbit\n10.44.0.1\n".($this->fault === 'saved' ? '.' : implode("\n", $this->domains)),
                    'PostUp = resolvectl dns %i 10.44.0.1; resolvectl domain %i '.($this->fault === 'hook' ? '\\~.' : implode(' ', array_map(static fn (string $domain): string => '\\~'.$domain, $this->domains))),
                    'Link 7 (orbit): '.($this->fault === 'server' ? '10.0.0.2' : '10.44.0.1'),
                    'Link 7 (orbit): '.($this->fault === 'domains' ? '~.' : implode(' ', array_map(static fn (string $domain): string => '~'.$domain, $this->domains))),
                    'Link 7 (orbit): '.($this->fault === 'default' ? 'yes' : 'no'),
                ];
                $output = implode("\n", array_map(base64_encode(...), $values));
            } else {
                $name = $args[array_key_last($args)];
                $output = str_starts_with($name, 'example.')
                    ? ($this->fault === 'public-fails' ? '' : 'example.com: 93.184.215.14'.($this->fault === 'global' ? '' : ' -- link: '.($this->fault === 'public-over-vpn' ? 'orbit' : 'eth0')))
                    : (in_array($this->fault, ['private-fails', 'answer'], true) ? '' : $name.': '.($this->fault === 'wrong-answer' ? '192.0.2.99' : ($name === 'gateway.orbit' ? '10.44.0.1' : '10.44.0.7')).' -- link: orbit');
            }

            $truncated = strlen($output) > ($command->maxOutputBytes ?? 65_536);

            return new CommandResult(0, $truncated ? substr($output, -($command->maxOutputBytes ?? 65_536)) : $output."\n", '', 0, $truncated);
        }
    };
    app()->instance(SshExecutor::class, $ssh);

    return [$node->refresh()->load('roles'), $ssh];
}

it('excludes the Gateway after VPN moves to another Node', function (): void {
    [$node] = peer_dns_inspection_fixture();
    $node->roles()->create(['role' => RoleName::Gateway]);
    $node->refresh()->load('roles');

    expect(app(PeerDnsPolicy::class)->manages($node))->toBeFalse()
        ->and(app(PeerDnsFootprintArtifact::class)->applies($node))->toBeFalse();
});

it('takes one locked snapshot without sending DNS queries during reconcile', function (): void {
    [$node, $ssh] = peer_dns_inspection_fixture();

    expect(app(ReconcilePeerDnsAction::class)->execute())->toBe([]);
    expect($ssh->commands)->toHaveCount(1);
    expect($ssh->commands[0]->input)->toContain('flock -s', 'orbit-wireguard-peer.lock')->not->toContain('dig ', 'resolvectl query');
});

it('reports SSH inspection failure as unverifiable without attempting repair', function (): void {
    [$node, $ssh] = peer_dns_inspection_fixture();
    $ssh->fault = 'ssh';
    $report = app(NodeDoctorProbe::class)->inspect(new DoctorNodeContext($node, new NodeInspectionData(true, 'linux', 'x86_64', true)));
    $dnsIssues = array_values(array_filter($report->issues, static fn ($issue): bool => str_contains($issue->message, 'DNS')));

    expect($dnsIssues)->toHaveCount(1)
        ->and($dnsIssues[0]->code)->toBe('node.inspection_failed')
        ->and($dnsIssues[0]->kind)->toBe(DoctorIssueKind::Unverifiable);
    expect(app(ReconcilePeerDnsAction::class)->execute())->toBe([$node->name]);
});

it('checks public resolution in Doctor including global DNS answers', function (string $fault, bool $failed): void {
    [$node, $ssh] = peer_dns_inspection_fixture();
    $ssh->fault = $fault;

    $report = app(NodeDoctorProbe::class)->inspect(new DoctorNodeContext($node, new NodeInspectionData(true, 'linux', 'x86_64', true)));
    $codes = array_map(static fn ($issue): string => $issue->code, $report->issues);
    expect(in_array('node.public_dns_unreachable', $codes, true))->toBe($failed);
})->with([['public-fails', true], ['public-over-vpn', true], ['global', false], ['', false]]);

it('clears unusable global DNS overrides before any peer operation', function (?string $server, ?string $expected): void {
    [$node] = peer_dns_inspection_fixture();
    app(VpnSettings::class)->configure(subnet: '10.44.0.0/24', dnsServer: $server);
    $migration = require database_path('migrations/2026_10_19_120215_reset_invalid_global_vpn_dns_server.php');
    $migration->up();
    $migration->up();

    expect(app(VpnSettings::class)->dnsServer())->toBe($expected)
        ->and(app(PeerDnsPolicy::class)->server($node))->toBe($expected ?? '10.44.0.1');
})->with([[null, null], ['10.0.0.2', null], ['bad', null], ['10.44.0.0', null], ['10.44.0.255', null], ['10.44.0.53', '10.44.0.53']]);

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
    [$node, $ssh] = peer_dns_inspection_fixture();
    Route::query()->create(['kind' => RouteKind::CustomProxy, 'node_id' => $node->id, 'domain' => 'example.com', 'provenance' => RouteProvenance::Explicit, 'publication' => RoutePublication::Private]);

    $report = app(NodeDoctorProbe::class)->inspect(new DoctorNodeContext($node, new NodeInspectionData(true, 'linux', 'x86_64', true)));
    $codes = array_map(static fn ($issue): string => $issue->code, $report->issues);
    expect($codes)->not->toContain('node.private_dns_unreachable', 'node.public_dns_unreachable', 'node.inspection_failed');
    expect($ssh->commands[array_key_last($ssh->commands)]->arguments)->toContain('example.net');
});

it('reports a lookup transport failure as unverifiable', function (): void {
    [$node, $ssh] = peer_dns_inspection_fixture();
    $ssh->fault = 'query-ssh';

    $report = app(NodeDoctorProbe::class)->inspect(new DoctorNodeContext($node, new NodeInspectionData(true, 'linux', 'x86_64', true)));
    $dnsIssues = array_values(array_filter($report->issues, static fn ($issue): bool => str_contains($issue->message, 'DNS')));
    expect($dnsIssues)->toHaveCount(1)
        ->and($dnsIssues[0]->code)->toBe('node.inspection_failed')
        ->and($dnsIssues[0]->kind)->toBe(DoctorIssueKind::Unverifiable);
});

it('inspects the complete maximum namespace without truncating the snapshot', function (): void {
    [$node, $ssh] = peer_dns_inspection_fixture();
    $rows = [];
    for ($i = 0; $i < 511; $i++) {
        $domain = str_repeat('a', 63).'.'.str_repeat('b', 63).'.'.str_repeat('c', 63).'.'.str_pad((string) $i, 61, 'x', STR_PAD_LEFT);
        $rows[] = ['name' => 'c'.$i, 'tld' => $domain];
        $ssh->domains[] = $domain;
    }
    Cluster::query()->insert($rows);
    sort($ssh->domains, SORT_STRING);

    expect(app(PeerDnsInspector::class)->inspect($node))->toBe(['policy' => true]);
});
