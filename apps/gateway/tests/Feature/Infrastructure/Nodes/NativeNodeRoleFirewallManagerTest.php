<?php

declare(strict_types=1);

use App\Domain\Firewall\FirewallOperationException;
use App\Domain\Instances\InstanceState;
use App\Domain\Nodes\RoleName;
use App\Domain\Shared\LifecycleStatus;
use App\Infrastructure\Firewall\NodeFirewallRuleCatalog;
use App\Infrastructure\Firewall\UfwRuleOwnership;
use App\Infrastructure\Firewall\UfwStatusParser;
use App\Infrastructure\Nodes\Roles\NativeNodeRoleFirewallManager;
use App\Infrastructure\Processes\CommandResult;
use App\Infrastructure\Ssh\HostKey;
use App\Infrastructure\Ssh\KnownHostsStore;
use App\Infrastructure\Ssh\RemoteCommand;
use App\Infrastructure\Ssh\SshConnection;
use App\Infrastructure\Ssh\SshExecutor;
use App\Infrastructure\Ssh\SshKeyProvider;
use App\Models\Instance;
use App\Models\Node;
use App\Models\Project;

it('preserves public SSH before enabling inactive UFW and opens nothing else', function (): void {
    expect(class_exists(NativeNodeRoleFirewallManager::class))->toBeTrue();

    $ssh = new RoleFirewallSshExecutor(active: false);
    $manager = role_firewall_manager($ssh);
    $node = role_firewall_node();

    $manager->convergeBase($node, 'nckrtl');

    $arguments = array_map(static fn (array $call): array => $call['command']->arguments, $ssh->calls);

    expect($arguments)
        ->toContain(
            ['sudo', 'ufw', 'status', 'numbered'],
            ['sudo', 'ufw', '--force', 'enable'],
            [
                'sudo',
                'ufw',
                'allow',
                'in',
                'proto',
                'tcp',
                'to',
                'any',
                'port',
                '22',
                'comment',
                'orbit:public-ssh-recovery',
            ],
        )
        ->and($ssh->calls[0]['connection']->host)
        ->toBe('192.0.2.10')
        ->and($ssh->comments())
        ->toBe(['orbit:public-ssh-recovery'])
        ->and($ssh->users())
        ->each->toBe('nckrtl');
});

it('finds the public SSH rule that the UpCloud task VM template bakes, and its role closes it', function (): void {
    $ssh = new RoleFirewallSshExecutor(active: false);
    $node = role_firewall_node();
    // What UpCloudTaskVmImageBuilder runs before the template is made.
    $builder = new SshConnection('192.0.2.10', 'orbit', 22, '/tmp/orbit-test-key', '/tmp/known_hosts');
    $ssh->execute($builder, new RemoteCommand(new NodeFirewallRuleCatalog()->publicSshRecovery($node)->arguments));
    $ssh->execute($builder, new RemoteCommand(['sudo', 'ufw', '--force', 'enable']));
    $ssh->calls = [];
    $manager = role_firewall_manager($ssh);

    $manager->convergeBase($node, 'nckrtl');

    expect(array_map(static fn (array $call): array => $call['command']->arguments, $ssh->calls))->each->toBe(['sudo', 'ufw', 'status', 'numbered'])
        ->and($ssh->comments())->toBe(['orbit:public-ssh-recovery']);

    $manager->converge($node, RoleName::AppDev, 'nckrtl');

    expect($ssh->comments())->toBe(['orbit:wireguard-members']);
});

it('trusts WireGuard membership and removes public recovery after VPN convergence', function (): void {
    $ssh = new RoleFirewallSshExecutor;
    $manager = role_firewall_manager($ssh);
    $node = role_firewall_node();
    $manager->convergeBase($node, 'nckrtl');
    $ssh->calls = [];

    $manager->converge($node, RoleName::Vpn, 'nckrtl');

    expect($ssh->comments())
        ->toContain('orbit:wireguard-members')
        ->not->toContain('orbit:public-ssh-recovery')->and(array_column($ssh->calls, 'connection'))->each(
            fn ($connection) => $connection->host->toBe('10.44.0.2'),
        )->and($ssh->users())
        ->each->toBe('nckrtl');
});

it('trusts WireGuard membership and keeps public recovery when a roleless Node joins', function (): void {
    $ssh = new RoleFirewallSshExecutor;
    $manager = role_firewall_manager($ssh);
    $node = role_firewall_node();
    $manager->convergeBase($node, 'nckrtl');
    $ssh->calls = [];

    $manager->trustWireGuardMembers($node, 'nckrtl');

    $arguments = array_map(static fn (array $call): array => $call['command']->arguments, $ssh->calls);
    $deletions = array_values(array_filter(
        $arguments,
        static fn (array $command): bool => ($command[3] ?? null) === 'delete',
    ));

    expect($ssh->comments())
        ->toContain('orbit:wireguard-members', 'orbit:public-ssh-recovery')
        ->and($arguments)
        ->not->toContain(['sudo', 'ufw', '--force', 'enable'])
        ->and($deletions)
        ->toBe([])
        ->and(array_column($ssh->calls, 'connection'))->each(
            fn ($connection) => $connection->host->toBe('10.44.0.2'),
        )->and($ssh->users())
        ->each->toBe('nckrtl');
});

it('restores the exact public SSH recovery rule over WireGuard without enabling UFW', function (): void {
    $ssh = new RoleFirewallSshExecutor;
    $ssh->seed(['orbit:wireguard-members']);

    role_firewall_manager($ssh)->restorePublicSsh(role_firewall_node(), 'nckrtl');

    expect($ssh->comments())
        ->toBe(['orbit:wireguard-members', 'orbit:public-ssh-recovery'])
        ->and($ssh->mutations())
        ->toBe([[
            'sudo',
            'ufw',
            'allow',
            'in',
            'proto',
            'tcp',
            'to',
            'any',
            'port',
            '22',
            'comment',
            'orbit:public-ssh-recovery',
        ]])
        ->and(array_column($ssh->calls, 'connection'))
        ->each(fn ($connection) => $connection->host->toBe('10.44.0.2'))
        ->and($ssh->users())
        ->each->toBe('nckrtl');
});

it('does not reapply an exact public SSH recovery rule', function (): void {
    $ssh = new RoleFirewallSshExecutor;
    $ssh->seed(['orbit:wireguard-members', 'orbit:public-ssh-recovery']);

    role_firewall_manager($ssh)->restorePublicSsh(role_firewall_node(), 'nckrtl');

    expect($ssh->mutations())->toBeEmpty();
});

it('fails closed without mutation when UFW is inactive during public SSH restoration', function (): void {
    $ssh = new RoleFirewallSshExecutor(active: false);

    expect(fn () => role_firewall_manager($ssh)->restorePublicSsh(role_firewall_node(), 'nckrtl'))
        ->toThrow(function (FirewallOperationException $exception): void {
            expect($exception->step)
                ->toBe('host-firewall')
                ->and($exception->errorCode)
                ->toBe('node.firewall_convergence_failed');
        });

    expect($ssh->mutations())->toBeEmpty();
});

it('converges private SSH and each exact role-owned firewall intent', function (RoleName $role, array $comments): void {
    expect(class_exists(NativeNodeRoleFirewallManager::class))->toBeTrue();

    $ssh = new RoleFirewallSshExecutor;
    $manager = role_firewall_manager($ssh);

    $manager->converge(role_firewall_node(), $role, 'nckrtl');

    expect($ssh->comments())
        ->toContain('orbit:wireguard-members', ...$comments)
        ->not->toContain('orbit:public-ssh-recovery');

    expect($ssh->calls[0]['connection']->host)
        ->toBe('10.44.0.2')
        ->and($ssh->users())
        ->each->toBe('nckrtl');
})->with([
    'app development' => [RoleName::AppDev, []],
    'Router' => [RoleName::Router, []],
    'app production' => [RoleName::AppProd, []],
    'gateway' => [RoleName::Gateway, ['orbit:gateway-https']],
    'VPN' => [RoleName::Vpn, []],
]);

it('does not reapply exact managed role rules', function (): void {
    expect(class_exists(NativeNodeRoleFirewallManager::class))->toBeTrue();

    $ssh = new RoleFirewallSshExecutor;
    $manager = role_firewall_manager($ssh);
    $node = role_firewall_node();
    $manager->converge($node, RoleName::AppProd, 'nckrtl');
    $firstMutations = $ssh->mutations();

    $manager->converge($node, RoleName::AppProd, 'nckrtl');

    expect($ssh->mutations())->toBe($firstMutations);
});

it('does not expose app development web ports on public interfaces', function (): void {
    $ssh = new RoleFirewallSshExecutor;

    role_firewall_manager($ssh)->converge(role_firewall_node(), RoleName::AppDev, 'nckrtl');

    expect($ssh->comments())
        ->toBe(['orbit:wireguard-members'])
        ->and($ssh->mutations())
        ->not->toContain(['sudo', 'ufw', 'allow', 'in', 'proto', 'tcp', 'to', 'any', 'port', '80']);
});

it('retires the superseded VPN and app development listener rules', function (): void {
    $ssh = new RoleFirewallSshExecutor;
    $ssh->seed([
        'orbit:vpn-ssh',
        'orbit:app-dev-http',
        'orbit:app-dev-https',
        'orbit:app-dev-direct-http',
        'orbit:app-dev-direct-https',
    ]);

    role_firewall_manager($ssh)->converge(role_firewall_node(), RoleName::AppDev, 'nckrtl');

    expect($ssh->comments())->toBe(['orbit:wireguard-members']);
});

it('retires only Orbit-owned public app production rules and preserves operator rules', function (): void {
    expect(class_exists(NativeNodeRoleFirewallManager::class))->toBeTrue();

    $ssh = new RoleFirewallSshExecutor;
    $manager = role_firewall_manager($ssh);
    $node = role_firewall_node();
    $ssh->seed(['orbit:app-prod-http', 'orbit:app-prod-https']);
    $manager->converge($node, RoleName::AppProd, 'nckrtl');

    $deletions = array_values(array_filter(
        $ssh->mutations(),
        static fn (array $arguments): bool => (
            array_slice($arguments, offset: 0, length: 4) === ['sudo', 'ufw', '--force', 'delete']
        ),
    ));
    $numbers = array_map(static fn (array $arguments): int => (int) $arguments[4], $deletions);

    expect($numbers)
        ->toBe(collect($numbers)->sortDesc()->values()->all())
        ->and($ssh->comments())
        ->not->toContain(
            'orbit:app-prod-http',
            'orbit:app-prod-https',
        )->and($ssh->operatorWebRulesPresent)->toBeTrue()->and($ssh->users())
        ->each->toBe('nckrtl');
});

it('retires public app production rules when leftover Instance tables are absent', function (): void {
    $ssh = new RoleFirewallSshExecutor;
    $node = orb197_persisted_firewall_node('legacy-public');
    $ssh->seed(['orbit:app-prod-http', 'orbit:app-prod-https']);

    role_firewall_manager($ssh)->converge($node, RoleName::AppProd, 'nckrtl');

    expect($ssh->comments())
        ->not
        ->toContain(
            'orbit:app-prod-http',
            'orbit:app-prod-https',
        )
        ->and($ssh->operatorWebRulesPresent)
        ->toBeTrue();
});

it('closes public Ingress HTTP and HTTPS on role removal without a live public Route', function (): void {
    $ssh = new RoleFirewallSshExecutor;
    $ssh->seed(['orbit:wireguard-members', 'orbit:ingress-http', 'orbit:ingress-https']);

    role_firewall_manager($ssh)->remove(role_firewall_node(), RoleName::Ingress, 'nckrtl');

    expect($ssh->comments())
        ->toBe(['orbit:wireguard-members'])
        ->and($ssh->operatorWebRulesPresent)
        ->toBeTrue();
});

it('retires public Ingress HTTP and HTTPS when the Cluster has no live public Route', function (): void {
    $ssh = new RoleFirewallSshExecutor;
    $ssh->seed(['orbit:wireguard-members', 'orbit:ingress-http', 'orbit:ingress-https']);

    role_firewall_manager($ssh)->converge(role_firewall_node(), RoleName::Ingress, 'nckrtl');

    expect($ssh->comments())
        ->toBe(['orbit:wireguard-members'])
        ->and($ssh->operatorWebRulesPresent)
        ->toBeTrue();
});

it('keeps a conflicting private production footprint private during role convergence', function (): void {
    $ssh = new RoleFirewallSshExecutor;
    $node = orb197_persisted_firewall_node('private-conflict');
    $project = Project::query()->create([
        'name' => 'Private production',
        'slug' => 'private-production',
        'repository_url' => 'https://example.test/private-production.git',
        'default_branch' => 'main',
        'root' => 'public',
    ]);
    Instance::query()->create([
        'project_id' => $project->id,
        'node_id' => $node->id,
        'name' => 'primary',
        'environment' => 'production',
        'checkout_path' => '/home/orbit-app-private',
        'production_user' => 'orbit-app-private',
        'production_home' => '/home/orbit-app-private',
        'status' => InstanceState::Reserved,
    ]);
    $ssh->seed(['orbit:app-prod-http', 'orbit:app-prod-https']);

    role_firewall_manager($ssh)->converge($node, RoleName::AppProd, 'nckrtl');

    expect($ssh->comments())
        ->not
        ->toContain(
            'orbit:app-prod-http',
            'orbit:app-prod-https',
        )
        ->and($ssh->operatorWebRulesPresent)
        ->toBeTrue();
});

it('fails closed without mutation when an owned comment has drifted', function (): void {
    expect(class_exists(NativeNodeRoleFirewallManager::class))->toBeTrue();

    $ssh = new RoleFirewallSshExecutor(driftedComment: 'orbit:wireguard-members');
    $manager = role_firewall_manager($ssh);

    expect(fn () => $manager->converge(role_firewall_node(), RoleName::AppDev, 'nckrtl'))
        ->toThrow(function (FirewallOperationException $exception): void {
            expect($exception->step)
                ->toBe('host-firewall')
                ->and($exception->errorCode)
                ->toBe('node.firewall_convergence_failed');
        });

    expect($ssh->mutations())->toBeEmpty();
});

it('does not publish Router LAN ingress when converging a non-Router role', function (): void {
    [$router, $eligible] = router_lan_topology();
    $ssh = new RoleFirewallSshExecutor;

    role_firewall_manager($ssh)->converge($router, RoleName::AppProd, 'orbit');

    expect($ssh->comments())
        ->toContain('orbit:wireguard-members')
        ->not->toContain('orbit:router-lan-https:'.$eligible->id);
});

it('removes Router LAN ingress when the Router role is cleared', function (): void {
    [$router, $eligible] = router_lan_topology();
    $ssh = new RoleFirewallSshExecutor;
    $manager = role_firewall_manager($ssh);
    $comment = 'orbit:router-lan-https:'.$eligible->id;

    $manager->converge($router, RoleName::Router, 'orbit');
    $manager->remove($router, RoleName::Router, 'orbit');

    expect($ssh->comments())->not->toContain($comment, 'orbit:router-lan-dns-udp:'.$eligible->id, 'orbit:router-lan-dns-tcp:'.$eligible->id);
});

it('publishes Router LAN ingress from eligible members and leaves WireGuard trust unchanged', function (): void {
    [$router, $eligible] = router_lan_topology();
    $ssh = new RoleFirewallSshExecutor;
    $comment = 'orbit:router-lan-https:'.$eligible->id;

    role_firewall_manager($ssh)->converge($router, RoleName::Router, 'orbit');

    expect($ssh->comments())
        ->toContain('orbit:wireguard-members', $comment)
        ->not->toContain('orbit:public-ssh-recovery', 'orbit:metrics-grafana-upstream')
        ->and($ssh->mutations())
        ->toContain([
            'sudo',
            'ufw',
            'allow',
            'in',
            'proto',
            'tcp',
            'from',
            '10.20.0.11',
            'to',
            '10.20.0.10',
            'port',
            '443',
            'comment',
            $comment,
        ]);
});

it('replaces a drifted Router LAN source without duplicating the owned comment', function (): void {
    [$router, $eligible] = router_lan_topology();
    $ssh = new RoleFirewallSshExecutor;
    $manager = role_firewall_manager($ssh);
    $catalog = new NodeFirewallRuleCatalog;
    $comment = 'orbit:router-lan-https:'.$eligible->id;

    $manager->expandRouterLanIngress($router, [
        $catalog->routerLanIngressRule('10.20.0.99', '10.20.0.10', $eligible->id),
    ], 'orbit');
    $manager->expandRouterLanIngress($router, $catalog->routerLanIngress($router), 'orbit');

    expect($ssh->comments())
        ->toHaveCount(3)
        ->toContain($comment, 'orbit:router-lan-dns-udp:'.$eligible->id, 'orbit:router-lan-dns-tcp:'.$eligible->id)
        ->and($ssh->mutations())
        ->toContain([
            'sudo',
            'ufw',
            'allow',
            'in',
            'proto',
            'tcp',
            'from',
            '10.20.0.11',
            'to',
            '10.20.0.10',
            'port',
            '443',
            'comment',
            $comment,
        ]);
});

it('replaces drifted Router LAN ingress and prunes obsolete members', function (): void {
    [$router, $eligible] = router_lan_topology();
    $ssh = new RoleFirewallSshExecutor;
    $manager = role_firewall_manager($ssh);
    $catalog = new NodeFirewallRuleCatalog;
    $obsoleteComment = 'orbit:router-lan-https:999';
    $manager->expandRouterLanIngress($router, [
        ...$catalog->routerLanIngress($router),
        $catalog->routerLanIngressRule('10.20.0.99', '10.20.0.10', 999),
    ], 'orbit');
    $desired = $catalog->routerLanIngress($router);

    $manager->expandRouterLanIngress($router, $desired, 'orbit');
    $manager->pruneRouterLanIngress($router, $desired, 'orbit');

    expect($ssh->comments())
        ->toContain('orbit:router-lan-https:'.$eligible->id)
        ->not->toContain($obsoleteComment);
});

it('restores the preceding Router LAN policy when publication fails', function (): void {
    [$router, $eligible] = router_lan_topology();
    $ssh = new RoleFirewallSshExecutor;
    $manager = role_firewall_manager($ssh);
    $desired = new NodeFirewallRuleCatalog()->routerLanIngress($router);
    $comment = 'orbit:router-lan-https:'.$eligible->id;
    $ssh->failApplyComment = $comment;

    expect(fn () => $manager->expandRouterLanIngress($router, $desired, 'orbit'))
        ->toThrow(function (FirewallOperationException $exception) use ($comment): void {
            expect($exception->errorCode)
                ->toBe('node.firewall_convergence_failed')
                ->and($exception->getMessage())
                ->toContain($comment);
        });

    expect($ssh->comments())->not->toContain($comment);
});

it('expands prunes and removes all Router LAN families without touching unrelated rules', function (): void {
    [$router, $member] = router_lan_topology();
    $ssh = new RoleFirewallSshExecutor;
    $manager = role_firewall_manager($ssh);
    $catalog = new NodeFirewallRuleCatalog;
    $manager->converge($router, RoleName::Router, 'orbit');
    $old = $catalog->routerLanIngress($router);
    $member->update(['lan_ip' => '10.20.0.21']);
    $desired = $catalog->routerLanIngress($router);

    $manager->expandRouterLanIngress($router, $desired, 'orbit');
    $manager->pruneRouterLanIngress($router, $desired, 'orbit');
    $before = count($ssh->mutations());
    $manager->expandRouterLanIngress($router, $desired, 'orbit');
    $manager->pruneRouterLanIngress($router, $desired, 'orbit');

    expect(count($ssh->mutations()))->toBe($before);
    expect($ssh->comments())->toBe([
        'orbit:wireguard-members',
        'orbit:router-lan-https:'.$member->id,
        'orbit:router-lan-dns-udp:'.$member->id,
        'orbit:router-lan-dns-tcp:'.$member->id,
    ]);
    foreach ($old as $rule) {
        expect($ssh->mutations())->toContain($rule->arguments);
    }
    foreach ($desired as $rule) {
        expect($ssh->mutations())->toContain($rule->arguments);
    }

    $manager->pruneRouterLanIngress($router, [], 'orbit');
    expect($ssh->comments())->toBe(['orbit:wireguard-members']);
    expect($ssh->operatorWebRulesPresent)->toBeTrue();
    expect($ssh->execute(new SshConnection('host', 'orbit', 22, '/key', '/known'), new RemoteCommand(['sudo', 'ufw', 'status', 'numbered']))->stdout)
        ->toContain('53/udp DENY IN 203.0.113.10 # unrelated');
});

it('restores every preceding family when the last DNS apply fails', function (): void {
    [$router, $member] = router_lan_topology();
    $ssh = new RoleFirewallSshExecutor;
    $manager = role_firewall_manager($ssh);
    $catalog = new NodeFirewallRuleCatalog;
    $manager->converge($router, RoleName::Router, 'orbit');
    $connection = new SshConnection('host', 'orbit', 22, '/key', '/known');
    $status = new RemoteCommand(['sudo', 'ufw', 'status', 'numbered']);
    $before = $ssh->execute($connection, $status)->stdout;
    $newMember = router_lan_node('new-member', '10.44.0.22', '10.20.0.22', $router->cluster);
    $ssh->failApplyComment = 'orbit:router-lan-dns-tcp:'.$newMember->id;

    expect(fn () => $manager->expandRouterLanIngress($router, $catalog->routerLanIngress($router), 'orbit'))
        ->toThrow(FirewallOperationException::class);
    expect($ssh->execute($connection, $status)->stdout)->toBe($before);
    expect($ssh->comments())->toContain('orbit:router-lan-dns-udp:'.$member->id, 'orbit:router-lan-dns-tcp:'.$member->id);
});

it('restores HTTPS and both DNS rules after a partially completed prune fails', function (): void {
    [$router] = router_lan_topology();
    $ssh = new RoleFirewallSshExecutor;
    $manager = role_firewall_manager($ssh);
    $desired = new NodeFirewallRuleCatalog()->routerLanIngress($router);
    $manager->expandRouterLanIngress($router, $desired, 'orbit');
    $ssh->failDeleteNumber = 3;

    expect(fn () => $manager->pruneRouterLanIngress($router, [], 'orbit'))->toThrow(FirewallOperationException::class);
    $status = $ssh->execute(new SshConnection('host', 'orbit', 22, '/key', '/known'), new RemoteCommand(['sudo', 'ufw', 'status', 'numbered']))->stdout;
    foreach ($desired as $rule) {
        expect(new UfwStatusParser()->ownership($status, $rule->shape))
            ->toBe(UfwRuleOwnership::Exact);
    }
    expect($ssh->comments())->toHaveCount(3);
    expect($ssh->operatorWebRulesPresent)->toBeTrue();
});

it('prunes stale IPv6 family residue but preserves similarly named operator rules', function (): void {
    [$router] = router_lan_topology();
    $ssh = new RoleFirewallSshExecutor;
    $ssh->seedRule('orbit:router-lan-dns-udp:999', '2001:db8::99', '2001:db8::10', '53', 'udp', 'v6');
    $ssh->seedRule('orbit:router-lan-dns-tcp:999', '10.20.0.99', '10.20.0.10', '53', 'tcp');
    $ssh->seedRule('orbit:router-lan-dns-tcp-operator', '10.20.0.98', '10.20.0.10', '53', 'tcp');

    role_firewall_manager($ssh)->pruneRouterLanIngress($router, [], 'orbit');

    expect($ssh->comments())->toBe(['orbit:router-lan-dns-tcp-operator']);
});

it('restores the observed protocol and port instead of normalizing a preceding drifted family', function (): void {
    [$router] = router_lan_topology();
    $ssh = new RoleFirewallSshExecutor;
    $ssh->seedRule('orbit:router-lan-dns-udp:999', '10.20.0.99', '10.20.0.10', '54', 'tcp');
    $ssh->seedRule('orbit:router-lan-dns-tcp:999', '10.20.0.99', '10.20.0.10', '55', 'udp');
    $ssh->failDeleteNumber = 2;

    expect(fn () => role_firewall_manager($ssh)->pruneRouterLanIngress($router, [], 'orbit'))->toThrow(FirewallOperationException::class);

    $status = $ssh->execute(new SshConnection('host', 'orbit', 22, '/key', '/known'), new RemoteCommand(['sudo', 'ufw', 'status', 'numbered']))->stdout;
    expect($status)->toContain(
        '10.20.0.10 54/tcp ALLOW IN 10.20.0.99 # orbit:router-lan-dns-udp:999',
        '10.20.0.10 55/udp ALLOW IN 10.20.0.99 # orbit:router-lan-dns-tcp:999',
    );
    expect($ssh->comments())->toHaveCount(2);
});

it('deletes repeated family comments only once per original number and preserves adjacent WireGuard trust', function (bool $ipv6): void {
    [$router] = router_lan_topology();
    $ssh = new RoleFirewallSshExecutor;
    foreach ([['https', 'tcp', '443'], ['dns-udp', 'udp', '53'], ['dns-tcp', 'tcp', '53']] as [$family, $protocol, $port]) {
        $ssh->seedRule('orbit:router-lan-'.$family.':999', '10.20.0.98', '10.20.0.10', $port, $protocol);
        $ssh->seedRule('orbit:router-lan-'.$family.':999', '10.20.0.99', '10.20.0.10', $port, $protocol);
        if ($ipv6) {
            $ssh->seedRule('orbit:router-lan-'.$family.':999', '2001:db8::99', '2001:db8::10', $port, $protocol, 'v6');
        }
    }
    $ssh->seed(['orbit:wireguard-members']);

    role_firewall_manager($ssh)->pruneRouterLanIngress($router, [], 'orbit');

    expect($ssh->comments())->toBe(['orbit:wireguard-members']);
    expect($ssh->operatorWebRulesPresent)->toBeTrue();
    expect($ssh->mutations())->toBe(array_map(
        static fn (int $number): array => ['sudo', 'ufw', '--force', 'delete', (string) $number],
        $ipv6 ? [10, 9, 8, 7, 6, 5, 4, 3, 2] : [7, 6, 5, 4, 3, 2],
    ));
})->with(['IPv4 drift' => false, 'IPv4 and IPv6 drift' => true]);

it('prunes protocol-unspecified family ports and ranges without touching intended or operator rules', function (string $family, string $port, bool $broad, bool $empty): void {
    [$router] = router_lan_topology();
    $ssh = new RoleFirewallSshExecutor;
    $manager = role_firewall_manager($ssh);
    $desired = $empty ? [] : new NodeFirewallRuleCatalog()->routerLanIngress($router);
    $manager->expandRouterLanIngress($router, $desired, 'orbit');
    $before = $ssh->inventory();
    $ssh->seedRule('orbit:router-lan-'.$family.':999', $broad ? 'Anywhere' : '10.20.0.99', $broad ? 'Anywhere' : '10.20.0.10', $port, 'any');
    $mutations = count($ssh->mutations());

    $manager->pruneRouterLanIngress($router, $desired, 'orbit');

    expect($ssh->inventory())->toBe($before);
    expect(array_slice($ssh->mutations(), $mutations))->toBe([
        ['sudo', 'ufw', '--force', 'delete', $empty ? '2' : '5'],
    ]);
    expect($ssh->operatorWebRulesPresent)->toBeTrue();
})->with([
    'HTTPS scoped port' => ['https', '443', false],
    'UDP scoped port' => ['dns-udp', '53', false],
    'TCP scoped port' => ['dns-tcp', '53', false],
    'HTTPS broad port' => ['https', '443', true],
    'UDP broad port' => ['dns-udp', '53', true],
    'TCP broad port' => ['dns-tcp', '53', true],
    'HTTPS scoped range' => ['https', '440:443', false],
    'UDP scoped range' => ['dns-udp', '50:53', false],
    'TCP scoped range' => ['dns-tcp', '50:53', false],
])->with(['populated desired' => false, 'empty desired' => true]);

it('refuses unreadable owned family inventory before any publication or prune mutation', function (string $family, bool $expand): void {
    [$router] = router_lan_topology();
    $ssh = new RoleFirewallSshExecutor;
    $ssh->seedRule('orbit:router-lan-https:777', '10.20.0.99', '10.20.0.10', '443', 'tcp');
    $ssh->extraStatusLines = ['[ 98] OpenSSH ALLOW IN Anywhere # orbit:router-lan-'.$family.':999'];
    $before = $ssh->inventory();
    $manager = role_firewall_manager($ssh);

    expect(function () use ($manager, $router, $expand): void {
        if ($expand) {
            $manager->expandRouterLanIngress($router, new NodeFirewallRuleCatalog()->routerLanIngress($router), 'orbit');
        } else {
            $manager->pruneRouterLanIngress($router, [], 'orbit');
        }
    })->toThrow(function (FirewallOperationException $exception): void {
        expect($exception->errorCode)->toBe('node.firewall_convergence_failed');
    });
    expect($ssh->mutations())->toBe([]);
    expect($ssh->inventory())->toBe($before);
})->with(['https', 'dns-udp', 'dns-tcp'])->with(['prune' => false, 'publish' => true]);

it('ignores unreadable similarly named unowned comments during family pruning', function (): void {
    [$router] = router_lan_topology();
    $ssh = new RoleFirewallSshExecutor;
    $ssh->extraStatusLines = ['[ 98] OpenSSH ALLOW IN Anywhere # orbit:router-lan-https-operator'];

    role_firewall_manager($ssh)->pruneRouterLanIngress($router, [], 'orbit');

    expect($ssh->mutations())->toBe([]);
    expect($ssh->extraStatusLines)->toBe(['[ 98] OpenSSH ALLOW IN Anywhere # orbit:router-lan-https-operator']);
});

it('preserves every snapshot shape sharing a comment when publication or pruning fails', function (string $failure): void {
    [$router] = router_lan_topology();
    $ssh = new RoleFirewallSshExecutor;
    $ssh->seedRule('orbit:router-lan-https:999', '10.20.0.99', '10.20.0.10', '444', 'tcp');
    $ssh->seedRule('orbit:router-lan-dns-udp:999', '10.20.0.98', '10.20.0.10', '53', 'udp');
    $ssh->seedRule('orbit:router-lan-dns-udp:999', '10.20.0.99', '10.20.0.10', '53', 'udp');
    $ssh->seedRule('orbit:router-lan-dns-udp:999', '2001:db8::99', '2001:db8::10', '53', 'udp', 'v6');
    $ssh->seedRule('orbit:router-lan-dns-tcp:999', '10.20.0.98', '10.20.0.10', '50:53', 'any');
    $ssh->seedRule('orbit:router-lan-dns-tcp:999', '10.20.0.99', '10.20.0.10', '55', 'tcp');
    $ssh->seed(['orbit:wireguard-members']);
    $before = $ssh->inventory();
    $catalog = new NodeFirewallRuleCatalog;
    $tcp = $catalog->routerLanIngressRule('10.20.0.100', '10.20.0.10', 1000, 'orbit:router-lan-dns-tcp');
    $udp = $catalog->routerLanIngressRule('10.20.0.100', '10.20.0.10', $failure === 'replacement' ? 999 : 1000, 'orbit:router-lan-dns-udp');
    $desired = $failure === 'replacement' ? [$udp] : ($failure === 'first apply' ? [$tcp] : [$udp, $tcp]);
    $ssh->failApplyComment = $failure === 'replacement' ? $udp->shape->comment : $tcp->shape->comment;
    if ($failure === 'partial prune') {
        $ssh->failDeleteNumber = 5;
    }
    $manager = role_firewall_manager($ssh);

    expect(function () use ($manager, $router, $desired, $failure): void {
        if ($failure === 'partial prune') {
            $manager->pruneRouterLanIngress($router, [], 'orbit');
        } else {
            $manager->expandRouterLanIngress($router, $desired, 'orbit');
        }
    })->toThrow(function (FirewallOperationException $exception): void {
        expect($exception->errorCode)->toBe('node.firewall_convergence_failed');
    });
    expect($ssh->inventory())->toEqualCanonicalizing($before);
    expect($ssh->operatorWebRulesPresent)->toBeTrue();
    if ($failure === 'first apply') {
        expect($ssh->mutations())->toBe([$tcp->arguments]);
        expect($ssh->inventory())->toBe($before);
    }
})->with(['first apply', 'partial apply', 'partial prune', 'replacement']);

it('refuses identical snapshot duplicates that UFW cannot recreate before mutation', function (): void {
    [$router] = router_lan_topology();
    $ssh = new RoleFirewallSshExecutor;
    $ssh->seedRule('orbit:router-lan-dns-udp:999', '10.20.0.99', '10.20.0.10', '53', 'udp');
    $ssh->seedRule('orbit:router-lan-dns-udp:999', '10.20.0.99', '10.20.0.10', '53', 'udp');
    $before = $ssh->inventory();

    expect(fn () => role_firewall_manager($ssh)->pruneRouterLanIngress($router, [], 'orbit'))
        ->toThrow(FirewallOperationException::class);
    expect($ssh->mutations())->toBe([]);
    expect($ssh->inventory())->toBe($before);
});

it('rejects missing WireGuard addresses before firewall mutation', function (): void {
    $ssh = new RoleFirewallSshExecutor;
    $node = role_firewall_node();
    $node->wireguard_ip = null;

    expect(fn () => role_firewall_manager($ssh)->converge($node, RoleName::Vpn, 'nckrtl'))
        ->toThrow(FirewallOperationException::class);
    expect($ssh->calls)->toBeEmpty();
});

it('reaches public SSH through the jump node only while the Node has no active role', function (): void {
    $jump = orb197_persisted_firewall_node('jump-host');
    $node = orb197_persisted_firewall_node('jumped-node');
    $node->update(['status' => LifecycleStatus::Provisioning, 'ssh_jump_node_id' => $jump->id]);
    $direct = new RoleFirewallSshExecutor(active: false);
    $jumped = new RoleFirewallSshExecutor(active: false);

    role_firewall_manager($direct)->convergeBase(role_firewall_node(), 'nckrtl');
    role_firewall_manager($jumped)->convergeBase($node, 'nckrtl');

    expect(array_map(static fn (array $call): ?SshConnection => $call['connection']->proxyJump, $direct->calls))
        ->each->toBeNull()
        ->and(array_map(static fn (array $call): string => $call['connection']->host, $jumped->calls))
        ->each->toBe($node->public_ssh_host)
        ->and(array_map(
            static fn (array $call): string => "{$call['connection']->proxyJump?->user}@{$call['connection']->proxyJump?->host}:{$call['connection']->proxyJump?->port}",
            $jumped->calls,
        ))
        ->each->toBe("nckrtl@{$jump->wireguard_ip}:22");

    $node->roles()->create(['role' => RoleName::AppDev, 'status' => LifecycleStatus::Active]);
    $jumped->calls = [];
    role_firewall_manager($jumped)->convergeBase($node, 'nckrtl');

    expect($jumped->calls)
        ->not->toBeEmpty()
        ->and(array_map(static fn (array $call): ?SshConnection => $call['connection']->proxyJump, $jumped->calls))
        ->each->toBeNull();
});

function role_firewall_node(): Node
{
    return new Node([
        'name' => 'role-node',
        'platform' => 'linux',
        'public_ssh_host' => '192.0.2.10',
        'public_ssh_port' => 22,
        'wireguard_ip' => '10.44.0.2',
    ]);
}

function orb197_persisted_firewall_node(string $name): Node
{
    $addressSuffix = Node::query()->count() + 2;

    return Node::query()->create([
        'name' => $name,
        'status' => LifecycleStatus::Active,
        'platform' => 'linux',
        'public_ssh_host' => "192.0.2.{$addressSuffix}",
        'public_ssh_port' => 22,
        'wireguard_ip' => "10.44.0.{$addressSuffix}",
        'user' => 'nckrtl',
    ]);
}

function role_firewall_manager(SshExecutor $ssh): NativeNodeRoleFirewallManager
{
    $keys = new class implements SshKeyProvider
    {
        public function privateKeyPath(): string
        {
            return '/tmp/orbit-test-key';
        }

        public function publicKey(): string
        {
            return 'ssh-ed25519 TEST';
        }
    };
    $knownHosts = new class implements KnownHostsStore
    {
        public function path(): string
        {
            return '/tmp/orbit-known-hosts';
        }

        public function put(string $host, int $port, HostKey $key): void {}
    };

    return new NativeNodeRoleFirewallManager($ssh, $keys, $knownHosts);
}

final class RoleFirewallSshExecutor implements SshExecutor
{
    /** @var list<array{connection: SshConnection, command: RemoteCommand}> */
    public array $calls = [];

    public bool $operatorWebRulesPresent = true;

    /** @var list<array{comment: string, family: string, source: string, destination: string, port: string, protocol: string}> */
    private array $rules = [];

    public ?string $failApplyComment = null;

    public ?int $failDeleteNumber = null;

    /** @var list<string> */
    public array $extraStatusLines = [];

    public function __construct(
        private bool $active = true,
        private readonly ?string $driftedComment = null,
    ) {}

    public function execute(SshConnection $connection, RemoteCommand $command): CommandResult
    {
        $this->calls[] = ['connection' => $connection, 'command' => $command];
        $arguments = $command->arguments;

        if ($arguments === ['sudo', 'ufw', 'status', 'numbered']) {
            return new CommandResult(0, $this->status(), '', 1, false);
        }

        if (array_slice(array: $arguments, offset: 0, length: 2) === ['sudo', 'awk']) {
            return new CommandResult(0, $this->storedRules(), '', 1, false);
        }

        if (array_slice(array: $arguments, offset: 0, length: 3) === ['sudo', 'ufw', 'allow']) {
            $commentIndex = array_search('comment', $arguments, strict: true);
            $comment = is_int($commentIndex) ? $arguments[$commentIndex + 1] ?? null : null;

            if (is_string($comment)) {
                if ($this->failApplyComment === $comment) {
                    $this->failApplyComment = null;

                    return new CommandResult(1, '', 'apply failed', 1, false);
                }

                $fromIndex = array_search('from', $arguments, strict: true);
                $toIndex = array_search('to', $arguments, strict: true);
                $portIndex = array_search('port', $arguments, strict: true);
                $protoIndex = array_search('proto', $arguments, strict: true);
                $this->add($comment, [
                    'protocol' => is_int($protoIndex) ? (string) $arguments[$protoIndex + 1] : 'any',
                    'source' => is_int($fromIndex) ? (string) ($arguments[$fromIndex + 1] ?? 'any') : 'any',
                    'destination' => is_int($toIndex) ? (string) ($arguments[$toIndex + 1] ?? 'any') : 'any',
                    'port' => is_int($portIndex) ? (string) ($arguments[$portIndex + 1] ?? 'any') : 'any',
                ]);
            }
        }

        if ($arguments === ['sudo', 'ufw', '--force', 'enable']) {
            $this->active = true;
        }

        if (array_slice(array: $arguments, offset: 0, length: 4) === ['sudo', 'ufw', '--force', 'delete']) {
            if ($this->failDeleteNumber === (int) ($arguments[4] ?? 0)) {
                $this->failDeleteNumber = null;

                return new CommandResult(1, '', 'delete failed', 1, false);
            }

            if (in_array((int) ($arguments[4] ?? 0), [90, 91], strict: true)) {
                $this->operatorWebRulesPresent = false;
            }

            $index = (int) ($arguments[4] ?? 0) - 2;

            if (array_key_exists($index, $this->rules)) {
                array_splice(array: $this->rules, offset: $index, length: 1);
            }
        }

        return new CommandResult(0, '', '', 1, false);
    }

    /** @param list<string> $comments */
    public function seed(array $comments): void
    {
        foreach ($comments as $comment) {
            $this->add($comment);
        }
    }

    public function seedRule(string $comment, string $source, string $destination, string $port, string $protocol, string $family = 'v4'): void
    {
        $this->rules[] = compact('comment', 'source', 'destination', 'port', 'protocol', 'family');
    }

    /** @return list<string> */
    public function users(): array
    {
        return array_map(static fn (array $call): string => $call['connection']->user, $this->calls);
    }

    /** @return list<string> */
    public function comments(): array
    {
        return array_values(array_unique(array_column($this->rules, 'comment')));
    }

    /** @return list<array{comment: string, family: string, source: string, destination: string, port: string, protocol: string}> */
    public function inventory(): array
    {
        return $this->rules;
    }

    /** @return list<list<string>> */
    public function mutations(): array
    {
        return array_values(array_map(
            static fn (array $call): array => $call['command']->arguments,
            array_filter(
                $this->calls,
                static fn (array $call): bool => (
                    array_slice(array: $call['command']->arguments, offset: 0, length: 2) === ['sudo', 'ufw']
                    && $call['command']->arguments !== ['sudo', 'ufw', 'status', 'numbered']
                ),
            ),
        ));
    }

    /** @param array{source?: string, destination?: string, port?: string, protocol?: string} $shape */
    private function add(string $comment, array $shape = []): void
    {
        $families = in_array(
            $comment,
            [
                'orbit:public-ssh-recovery',
                'orbit:app-prod-http',
                'orbit:app-prod-https',
                'orbit:gateway-https',
                'orbit:app-dev-direct-http',
                'orbit:app-dev-direct-https',
                'orbit:ingress-http',
                'orbit:ingress-https',
            ],
            strict: true,
        )
            ? ['v4', 'v6']
            : [str_contains($shape['source'] ?? '', ':') || str_contains($shape['destination'] ?? '', ':') ? 'v6' : 'v4'];

        foreach ($families as $family) {
            $this->rules[] = [
                'comment' => $comment,
                'family' => $family,
                'source' => $shape['source'] ?? 'any',
                'destination' => $shape['destination'] ?? 'any',
                'port' => $shape['port'] ?? 'any',
                'protocol' => $shape['protocol'] ?? 'any',
            ];
        }
    }

    private function status(): string
    {
        if (! $this->active) {
            return "Status: inactive\n";
        }

        $lines = ['Status: active', '', '[ 1] 53/udp DENY IN 203.0.113.10 # unrelated'];

        foreach ($this->rules as $offset => $rule) {
            $number = $offset + 2;
            $lines[] = $this->line($number, $rule);
        }

        if ($this->operatorWebRulesPresent) {
            $lines[] = '[90] 80/tcp ALLOW IN 198.51.100.20';
            $lines[] = '[91] 443/tcp ALLOW IN 198.51.100.20';
        }

        if ($this->driftedComment !== null && ! in_array($this->driftedComment, $this->comments(), strict: true)) {
            $lines[] = '[99] 10.44.0.2 1:65535/tcp on orbit ALLOW IN Anywhere # '.$this->driftedComment;
        }

        return implode("\n", [...$lines, ...$this->extraStatusLines])."\n";
    }

    /** @param array{comment: string, family: string, source: string, destination: string, port: string, protocol: string} $rule */
    private function line(int $number, array $rule): string
    {
        $comment = $rule['comment'];
        $v6 = $rule['family'] === 'v6' ? ' (v6)' : '';

        if (str_starts_with($comment, 'orbit:router-lan-')) {
            $protocol = $rule['protocol'] === 'any' ? '' : '/'.$rule['protocol'];

            return "[ {$number}] {$rule['destination']} {$rule['port']}{$protocol}{$v6} ALLOW IN {$rule['source']}{$v6} # {$comment}";
        }

        return match ($comment) {
            'orbit:public-ssh-recovery' => "[ {$number}] 22/tcp{$v6} ALLOW IN Anywhere{$v6} # {$comment}",
            'orbit:wireguard-members' => '[ '.$number.'] '.($rule['destination'] === 'any' ? '10.44.0.2' : $rule['destination']).' on orbit ALLOW IN Anywhere # '.$comment,
            'orbit:vpn-ssh' => "[ {$number}] 10.44.0.2 22/tcp on orbit ALLOW IN Anywhere # {$comment}",
            'orbit:app-dev-http' => "[ {$number}] 10.44.0.2 80/tcp on orbit ALLOW IN Anywhere # {$comment}",
            'orbit:app-dev-https' => "[ {$number}] 10.44.0.2 443/tcp on orbit ALLOW IN Anywhere # {$comment}",
            'orbit:app-dev-direct-http' => "[ {$number}] 80/tcp{$v6} ALLOW IN Anywhere{$v6} # {$comment}",
            'orbit:app-dev-direct-https' => "[ {$number}] 443/tcp{$v6} ALLOW IN Anywhere{$v6} # {$comment}",
            'orbit:app-prod-http' => "[ {$number}] 80/tcp{$v6} ALLOW IN Anywhere{$v6} # {$comment}",
            'orbit:app-prod-https' => "[ {$number}] 443/tcp{$v6} ALLOW IN Anywhere{$v6} # {$comment}",
            'orbit:ingress-http' => "[ {$number}] 80/tcp{$v6} ALLOW IN Anywhere{$v6} # {$comment}",
            'orbit:ingress-https' => "[ {$number}] 443/tcp{$v6} ALLOW IN Anywhere{$v6} # {$comment}",
            'orbit:gateway-https' => "[ {$number}] 443/tcp{$v6} on orbit ALLOW IN Anywhere{$v6} # {$comment}",
            default => throw new LogicException("Unknown test comment [{$comment}]."),
        };
    }

    private function storedRules(): string
    {
        $lines = [];

        foreach ($this->rules as $rule) {
            $comment = $rule['comment'];

            if ($comment !== 'orbit:public-ssh-recovery') {
                continue;
            }

            $endpoint = $rule['family'] === 'v6' ? '::/0' : '0.0.0.0/0';
            $encoded = bin2hex($comment);
            $lines[] = "__orbit_ufw_tuple:{$rule['family']}:### tuple ### allow tcp 22 {$endpoint} any {$endpoint} in comment={$encoded}";
        }

        return implode("\n", $lines);
    }
}
