<?php

declare(strict_types=1);

use App\Domain\AppDev\DevelopmentProjectionOperationLock;
use App\Domain\AppDev\RuntimeConvergenceException;
use App\Domain\Certificates\LeafCertificateSigner;
use App\Domain\Clusters\ClusterState;
use App\Domain\Instances\InstanceState;
use App\Domain\Nodes\ManagedUserAccount;
use App\Domain\Nodes\ManagedUserAccountResolver;
use App\Domain\Nodes\NodeProvisioningException;
use App\Domain\Nodes\RoleName;
use App\Domain\Routes\ContributedPrivateHostnames;
use App\Domain\Routes\RouteProvenance;
use App\Domain\Routes\RoutePublication;
use App\Domain\Routes\RouteReplacementStep;
use App\Domain\Routes\RouteStatus;
use App\Domain\Shared\LifecycleStatus;
use App\Domain\Shared\ResourceOperationException;
use App\Domain\WireGuard\WireGuardPeerDnsRepairer;
use App\Infrastructure\AgentCommands\AgentCommandRunner;
use App\Infrastructure\AppDev\DevelopmentCaddyConfigRenderer;
use App\Infrastructure\AppDev\DevelopmentDnsConfigRenderer;
use App\Infrastructure\AppDev\DevelopmentPhpFpmConfigRenderer;
use App\Infrastructure\AppDev\DevelopmentSite;
use App\Infrastructure\AppDev\DevelopmentSiteRepository;
use App\Infrastructure\AppDev\DevelopmentSshExecutor;
use App\Infrastructure\AppDev\DnsmasqPrivateDnsManager;
use App\Infrastructure\AppDev\NativeDevelopmentProjectionOperationLock;
use App\Infrastructure\AppDev\RemoteAppDevCaddyManager;
use App\Infrastructure\AppDev\RemoteAppDevCertificateManager;
use App\Infrastructure\AppDev\RemoteAppDevPhpFpmManager;
use App\Infrastructure\AppDev\RemoteAppDevTldRouteManager;
use App\Infrastructure\AppProd\RemoteAppProdCaddyManager;
use App\Infrastructure\Caddy\Build\NodeCaddyfileRenderer;
use App\Infrastructure\MachineSoftware\MachineRecipe;
use App\Infrastructure\MachineSoftware\PhpBuild;
use App\Infrastructure\MachineSoftware\RecipeLoader;
use App\Infrastructure\Nodes\RemotePhpPackageManager;
use App\Infrastructure\Processes\CommandDeadline;
use App\Infrastructure\Processes\CommandResult;
use App\Infrastructure\Processes\ProcessInvocation;
use App\Infrastructure\Processes\ProcessRunner;
use App\Infrastructure\Ssh\HostKey;
use App\Infrastructure\Ssh\KnownHostsStore;
use App\Infrastructure\Ssh\RemoteCommand;
use App\Infrastructure\Ssh\SshExecutor;
use App\Infrastructure\Ssh\SshKeyProvider;
use App\Models\Cluster;
use App\Models\Instance;
use App\Models\Node;
use App\Models\Project;
use App\Models\Route;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Symfony\Component\Process\ExecutableFinder;
use Tests\Support\AppDevFakeProcessRunner;
use Tests\Support\AppDevFakeSshExecutor;
use Tests\Support\FakeAgentCommandRunner;
use Tests\Support\FakeNodeCaddyBuilds;
use Tests\Support\FpmPublishHarness;
use Tests\Support\PhpBuildAnswers;

function app_dev_account_resolver(
    ManagedUserAccount $account = new ManagedUserAccount('orbit', 'orbit', '/home/orbit'),
): ManagedUserAccountResolver {
    return new class($account) implements ManagedUserAccountResolver
    {
        public function __construct(
            private readonly ManagedUserAccount $account,
        ) {}

        public function resolve(Node $node): ManagedUserAccount
        {
            return $this->account;
        }
    };
}

beforeEach(function (): void {
    $this->phpAgents = new FakeAgentCommandRunner;
    (new PhpBuildAnswers)->register($this->phpAgents);
    $this->phpAgents->answer('php.configure-runtime', ['changed' => false, 'reloaded' => false]);
    app()->instance(AgentCommandRunner::class, $this->phpAgents);
});

it('uses shared peer DNS repair for app development TLD convergence', function (): void {
    $node = Node::query()->create(['name' => 'app-dev-route', 'tld' => 'test', 'public_ssh_host' => '192.0.2.10']);
    $repairer = new class implements WireGuardPeerDnsRepairer
    {
        public ?Node $node = null;

        public function repair(Node $node): void
        {
            $this->node = $node;
        }
    };

    new RemoteAppDevTldRouteManager($repairer)->converge($node);

    expect($repairer->node)->toBe($node);
});

it('keeps DNS repair failure details during app development TLD convergence', function (): void {
    $node = Node::query()->create(['name' => 'app-dev-route', 'tld' => 'test', 'public_ssh_host' => '192.0.2.10']);
    $repairer = new class implements WireGuardPeerDnsRepairer
    {
        public function repair(Node $node): void
        {
            throw new NodeProvisioningException('wireguard-peer-state', 'vpn.peer_dns_state_unsupported', 'Unsupported DNS state.');
        }
    };

    expect(fn () => new RemoteAppDevTldRouteManager($repairer)->converge($node))->toThrow(RuntimeConvergenceException::class, 'Unsupported DNS state.');
});

it('renders isolated pools and private Caddy listeners for every active Instance Route', function (): void {
    [$node, $project] = app_dev_runtime_models();
    $instance = app_dev_supported_app_instance($node, $project->id);
    $route = app_dev_supported_route($instance, 'acme.app-dev.orbit');
    $sites = new DevelopmentSiteRepository()->forNode($node);
    $fpm = new DevelopmentPhpFpmConfigRenderer()->render($sites, new ManagedUserAccount('orbit', 'orbit', '/home/orbit'));
    $caddy = new DevelopmentCaddyConfigRenderer()->render($sites);
    $adapted = caddy_adapt($caddy);

    expect($sites)
        ->toHaveCount(1)
        ->and($sites->sole()->scope)
        ->toBe("app-instance-{$instance->id}")
        ->and($fpm)
        ->toContain(
            "[orbit-app-instance-{$instance->id}]",
            "listen = /run/php/orbit-app-instance-{$instance->id}.sock",
            'listen.group = caddy',
            'env[PATH] = /usr/local/bin:/opt/orbit/composer/vendor/bin:/usr/bin:/bin',
            'php_admin_value[opcache.validate_timestamps] = 1',
            'php_admin_value[opcache.revalidate_freq] = 0',
        )
        ->not->toContain(
            '[orbit-instance-1]',
            '[orbit-workspace-1]',
            'opcache.file_update_protection',
            'opcache.memory_consumption',
            'opcache.jit',
        )->and(
            $caddy,
        )->toContain(
            "https://{$route->domain}",
            'bind 0.0.0.0',
            "php_fastcgi unix//run/php/orbit-app-instance-{$instance->id}.sock",
            "tls /etc/caddy/orbit-certificates/app-instance-{$instance->id}/current/cert.pem",
        )
        ->not->toContain(
            ':80',
            'https://legacy.acme.app-dev.orbit',
            'https://feature.acme.app-dev.orbit',
            'php_fastcgi unix//run/php/orbit-instance-1.sock',
        );

    if (new ExecutableFinder()->find('caddy') !== null) {
        expect($adapted->succeeded())
            ->toBeTrue()
            ->and($adapted->stdout)
            ->toContain('0.0.0.0:443')
            ->not->toContain('127.0.0.1:443');
    }
});

it('stops rendering a Route once another Route has replaced it', function (): void {
    [$node, $project] = app_dev_runtime_models();
    $instance = app_dev_supported_app_instance($node, $project->id);
    $retired = app_dev_supported_route($instance, 'before.app-dev.orbit');
    $replacement = Route::query()->create([
        'project_id' => $retired->project_id,
        'node_id' => $retired->node_id,
        'domain' => 'after.app-dev.orbit',
        'provenance' => RouteProvenance::Explicit,
        'publication' => RoutePublication::Private,
        'status' => RouteStatus::Pending,
        'replaces_route_id' => $retired->id,
        'replacement_step' => RouteReplacementStep::Reserved,
    ]);
    $retired->update(['replaced_by_route_id' => $replacement->id]);
    $replacement->targets()->create(['instance_id' => $instance->id, 'position' => 0]);
    $replacement->update(['status' => RouteStatus::Activating, 'replacement_step' => RouteReplacementStep::DatabaseCutover]);
    $retired->update(['status' => RouteStatus::Retiring]);

    $caddy = new DevelopmentCaddyConfigRenderer()->render(new DevelopmentSiteRepository()->forNode($node));

    // The retired domain keeps answering off the replacement's certificate otherwise, which sends
    // Caddy to automatic HTTPS for a private Orbit domain.
    expect($caddy)
        ->toContain('https://after.app-dev.orbit')
        ->not->toContain('https://before.app-dev.orbit');
});

it('hydrates only Instance Route sites and never reads leftover Instance or Workspace rows', function (): void {
    [$node, $project] = app_dev_runtime_models();
    $instance = app_dev_supported_app_instance($node, $project->id);
    $route = app_dev_supported_route($instance, 'acme.app-dev.orbit');
    $unrelatedNode = Node::query()->create([
        'name' => 'unrelated-app-dev',
        'status' => LifecycleStatus::Active,
        'tld' => 'unrelated.orbit',
        'public_ssh_host' => '192.0.2.40',
        'wireguard_ip' => '10.44.0.40',
    ]);
    $unrelatedNode->roles()->create(['role' => RoleName::AppDev, 'status' => LifecycleStatus::Active]);
    $unrelatedApp = app_dev_supported_app_instance($unrelatedNode, $project->id, 'unrelated');
    $unrelatedRoute = app_dev_supported_route($unrelatedApp, 'unrelated.app-dev.orbit');
    $sites = new DevelopmentSiteRepository;
    $globalSites = $sites->all();
    $globalDns = new DevelopmentDnsConfigRenderer($sites, hostnames: app()->tagged(ContributedPrivateHostnames::class))->render();

    $nodeSites = $sites->forNode($node);

    $siteIdentity = static fn (DevelopmentSite $site): array => [
        $site->scope,
        $site->domain,
        $site->phpVersion,
        $site->nodeAddress,
    ];
    expect($nodeSites->map($siteIdentity)->all())
        ->toBe($globalSites->where('nodeId', $node->id)->values()->map($siteIdentity)->all())
        ->and($nodeSites->pluck('scope')->all())
        ->toBe(["app-instance-{$instance->id}"])
        ->and($nodeSites->sole()->phpVersion)
        ->toBe($instance->selected_php_version)
        ->and(Schema::hasTable('instances'))
        ->toBeTrue()
        ->and(Schema::hasTable('workspaces'))
        ->toBeFalse()
        ->and($globalDns)
        ->toContain(
            "host-record={$route->domain},{$node->wireguard_ip}",
            "host-record={$unrelatedRoute->domain},{$unrelatedNode->wireguard_ip}",
        )
        ->not->toContain('acme.app-dev.orbit.legacy', 'feature.acme.app-dev.orbit');
});

it('retires previous app-dev pools before activating their lower PHP version', function (): void {
    [$node, $project] = app_dev_runtime_models();
    $moving = app_dev_supported_app_instance($node, $project->id, 'moving');
    app_dev_supported_route($moving, 'moving.app-dev.orbit');
    $stable = app_dev_supported_app_instance($node, $project->id, 'stable');
    app_dev_supported_route($stable, 'stable.app-dev.orbit');
    $sites = new DevelopmentSiteRepository;
    $renderer = new DevelopmentPhpFpmConfigRenderer;
    $previousConfiguration = $renderer->render(
        $sites->forNode($node),
        new ManagedUserAccount('orbit', 'orbit', '/home/orbit'),
    );
    $moving->update(['selected_php_version' => '8.4']);
    $transitionConfiguration = $renderer->render(
        $sites->forNode($node)->where('phpVersion', '8.5')->values(),
        new ManagedUserAccount('orbit', 'orbit', '/home/orbit'),
    );
    $ssh = new AppDevFakeSshExecutor([
        new CommandResult(0, "8.5\t".base64_encode($previousConfiguration)."\n", '', 1, false),
    ]);
    $manager = new RemoteAppDevPhpFpmManager(
        sites: $sites,
        renderer: $renderer,
        ssh: app_dev_ssh($ssh),
        accounts: app_dev_account_resolver(),
        packages: new RemotePhpPackageManager,
    );

    $manager->converge($node);

    $publishCalls = collect($ssh->commands)
        ->filter(static fn (RemoteCommand $command): bool => str_contains($command->input ?? '', 'php-fpm.conf'))
        ->values();

    expect($ssh->commands[0]->input)
        ->toContain('base64 --wrap=0 -- "$path"')
        ->and($publishCalls->map(static fn (RemoteCommand $command): string => $command->arguments[4])->all())
        ->toBe(['8.5', '8.4'])
        ->and($publishCalls->first()?->input)
        ->toContain(base64_encode($transitionConfiguration))
        ->not->toContain(base64_encode($previousConfiguration));
});

it('restores the previous app-dev pools when lower PHP activation fails', function (): void {
    [$node, $project] = app_dev_runtime_models();
    $moving = app_dev_supported_app_instance($node, $project->id, 'moving');
    app_dev_supported_route($moving, 'moving.app-dev.orbit');
    $stable = app_dev_supported_app_instance($node, $project->id, 'stable');
    app_dev_supported_route($stable, 'stable.app-dev.orbit');
    $sites = new DevelopmentSiteRepository;
    $renderer = new DevelopmentPhpFpmConfigRenderer;
    $previousConfiguration = $renderer->render(
        $sites->forNode($node),
        new ManagedUserAccount('orbit', 'orbit', '/home/orbit'),
    );
    $moving->update(['selected_php_version' => '8.4']);
    $ssh = new AppDevFakeSshExecutor([
        new CommandResult(0, "8.5\t".base64_encode($previousConfiguration)."\n", '', 1, false),
        new CommandResult(0, '', '', 1, false),
        new CommandResult(1, '', 'activation failed', 1, false),
    ]);
    $manager = new RemoteAppDevPhpFpmManager(
        sites: $sites,
        renderer: $renderer,
        ssh: app_dev_ssh($ssh),
        accounts: app_dev_account_resolver(),
        packages: new RemotePhpPackageManager,
    );

    expect(fn () => $manager->converge($node))
        ->toThrow(function (RuntimeConvergenceException $exception): void {
            expect($exception->errorCode)->toBe('app-dev.php_fpm_config_failed');
        });

    $publishCalls = collect($ssh->commands)
        ->filter(static fn (RemoteCommand $command): bool => str_contains($command->input ?? '', 'php-fpm.conf'))
        ->values();

    expect($publishCalls->map(static fn (RemoteCommand $command): string => $command->arguments[4])->all())
        ->toBe(['8.5', '8.4', '8.5'])
        ->and($publishCalls->last()?->input)
        ->toContain(base64_encode($previousConfiguration));
});

it('removes a newly activated app-dev pool when later PHP activation fails', function (): void {
    [$node, $project] = app_dev_runtime_models();
    $lower = app_dev_supported_app_instance($node, $project->id, 'lower');
    app_dev_supported_route($lower, 'lower.app-dev.orbit');
    $higher = app_dev_supported_app_instance($node, $project->id, 'higher');
    app_dev_supported_route($higher, 'higher.app-dev.orbit');
    $sites = new DevelopmentSiteRepository;
    $renderer = new DevelopmentPhpFpmConfigRenderer;
    $previousConfiguration = $renderer->render(
        $sites->forNode($node),
        new ManagedUserAccount('orbit', 'orbit', '/home/orbit'),
    );
    $lower->update(['selected_php_version' => '8.4']);
    $higher->update(['selected_php_version' => '8.6']);
    $ssh = new AppDevFakeSshExecutor([
        new CommandResult(0, "8.5\t".base64_encode($previousConfiguration)."\n", '', 1, false),
        new CommandResult(0, '', '', 1, false),
        new CommandResult(0, '', '', 1, false),
        new CommandResult(1, '', 'activation failed', 1, false),
    ]);
    $manager = new RemoteAppDevPhpFpmManager(
        sites: $sites,
        renderer: $renderer,
        ssh: app_dev_ssh($ssh),
        accounts: app_dev_account_resolver(),
        packages: new RemotePhpPackageManager(app_dev_recipe_with_php_86()),
    );

    expect(fn () => $manager->converge($node))
        ->toThrow(function (RuntimeConvergenceException $exception): void {
            expect($exception->errorCode)->toBe('app-dev.php_fpm_config_failed');
        });

    $publishCalls = collect($ssh->commands)
        ->filter(static fn (RemoteCommand $command): bool => str_contains($command->input ?? '', 'php-fpm.conf'))
        ->values();

    expect($publishCalls->map(static fn (RemoteCommand $command): string => $command->arguments[4])->all())
        ->toBe(['8.5', '8.4', '8.6', '8.4', '8.5'])
        ->and($publishCalls->get(3)?->input)
        ->toContain("printf '%s' '' | base64 --decode")
        ->and($publishCalls->last()?->input)
        ->toContain(base64_encode($previousConfiguration));
});

it('installs selected PHP versions and validates a complete staged FPM configuration before publication', function (): void {
    [$node, $project] = app_dev_runtime_models();
    $php84 = app_dev_supported_app_instance($node, $project->id, 'php84', '8.4');
    app_dev_supported_route($php84, 'php84.app-dev.orbit');
    $php85 = app_dev_supported_app_instance($node, $project->id, 'php85', '8.5');
    app_dev_supported_route($php85, 'php85.app-dev.orbit');
    $ssh = new AppDevFakeSshExecutor([
        new CommandResult(0, "8.5\n", '', 1, false),
        new CommandResult(0, '', '', 1, false),
        new CommandResult(0, '', '', 1, false),
    ]);
    $manager = new RemoteAppDevPhpFpmManager(
        sites: new DevelopmentSiteRepository,
        renderer: new DevelopmentPhpFpmConfigRenderer,
        ssh: app_dev_ssh($ssh),
        accounts: app_dev_account_resolver(),
        packages: new RemotePhpPackageManager,
    );

    $manager->converge($node);

    $publishCalls = collect($ssh->commands)
        ->filter(static fn (RemoteCommand $command): bool => str_contains($command->input ?? '', 'php-fpm.conf'))
        ->values();

    expect($ssh->commands)
        ->toHaveCount(3)
        ->and($this->phpAgents->calls[0]['command'])->toBe('php.install-build')
        ->and($this->phpAgents->calls[0]['input']['extras'])->toBe('keep')
        ->and(array_column($this->phpAgents->calls[0]['input']['versions']['8.4']['packages'], 'name'))->toContain('php8.4-fpm', 'php8.4-pcov', 'php8.4-opcache')
        ->and($publishCalls)
        ->toHaveCount(2)
        ->and($publishCalls->first()?->arguments)
        ->toContain('/run/lock/orbit')
        ->and($publishCalls->first()?->input)
        ->toContain(
            'if [ "$lock_directory" = /run/lock/orbit ]; then',
            'test "$(stat -c %u:%g:%a -- "$lock_directory")" = 0:0:700',
            'lock="$lock_directory/orbit-php-fpm-$version.lock"',
            'flock -w 30 9',
            'cp -- "$pool" "$temporary_directory/pool.d/"',
            'sudo "php-fpm$version" -y "$temporary_directory/php-fpm.conf" -t',
            'cmp -s -- "$candidate" "$managed_configuration"',
            'cp -a -- "$managed_configuration" "$backup"',
            'sudo mv -fT -- "$staged" "$managed_configuration"',
            'if ! sudo systemctl enable "php$version-fpm" || ! sudo systemctl reload-or-restart "php$version-fpm"; then',
            'cp -a -- "$backup" "$rollback"',
            'sudo mv -fT -- "$rollback" "$managed_configuration"',
            'sudo systemctl reload-or-restart "php$version-fpm" || true',
        );

    $script = $publishCalls->first()->input ?? '';
    expect($script)
        ->toContain('exec 9>>"$lock"')
        ->not->toContain('exec 9>"$lock"', '/run/lock/orbit-php-fpm-');
    $setup = mb_strpos(haystack: $script, needle: 'umask 0077');
    $open = mb_strpos(haystack: $script, needle: 'exec 9>>"$lock"');
    $lock = mb_strpos(haystack: $script, needle: 'flock -w 30 9');
    $snapshot = mb_strpos(haystack: $script, needle: 'for pool in "$pool_directory"/*.conf');
    $validation = mb_strpos(
        haystack: $script,
        needle: 'php-fpm$version" -y "$temporary_directory/php-fpm.conf" -t',
    );
    $switch = mb_strpos(
        haystack: $script,
        needle: 'sudo mv -fT -- "$staged" "$managed_configuration"',
    );
    $activation = mb_strpos(haystack: $script, needle: 'if ! sudo systemctl enable');
    $rollback = mb_strpos(
        haystack: $script,
        needle: 'sudo mv -fT -- "$rollback" "$managed_configuration"',
    );

    expect($setup)
        ->toBeInt()
        ->toBeLessThan($open)
        ->and($open)
        ->toBeInt()
        ->toBeLessThan($lock)
        ->and($lock)
        ->toBeInt()
        ->toBeLessThan($snapshot)
        ->and($validation)
        ->toBeInt()
        ->toBeLessThan($switch)
        ->and($switch)
        ->toBeInt()
        ->toBeLessThan($activation)
        ->and($activation)
        ->toBeInt()
        ->toBeLessThan($rollback);
});

it('renders and publishes AppDev FPM pools with the nondefault managed account', function (): void {
    $account = new ManagedUserAccount('nckrtl', 'nckrtl', '/srv/users/nckrtl');
    [$node, $project] = app_dev_runtime_models(account: $account);
    $instance = app_dev_supported_app_instance($node, $project->id);
    app_dev_supported_route($instance, 'acme.app-dev.orbit');
    $sites = new DevelopmentSiteRepository()->forNode($node);
    $rendered = new DevelopmentPhpFpmConfigRenderer()->render($sites, $account);
    $ssh = new AppDevFakeSshExecutor([
        new CommandResult(0, "8.5\n", '', 1, false),
        new CommandResult(0, '', '', 1, false),
    ]);
    $manager = new RemoteAppDevPhpFpmManager(
        sites: new DevelopmentSiteRepository,
        renderer: new DevelopmentPhpFpmConfigRenderer,
        ssh: app_dev_ssh($ssh),
        accounts: app_dev_account_resolver($account),
        packages: new RemotePhpPackageManager,
    );

    $manager->converge($node);

    $publishCall = collect($ssh->commands)
        ->first(static fn (RemoteCommand $command): bool => str_contains($command->input ?? '', 'php-fpm.conf'));

    expect($rendered)
        ->toContain(
            'user = nckrtl',
            'group = nckrtl',
            'listen.owner = nckrtl',
            'listen.group = caddy',
        )
        ->and($publishCall)
        ->not
        ->toBeNull()
        ->and($publishCall->input ?? '')
        ->toContain(base64_encode($rendered))
        ->toContain('sudo install -o root -g root -m 0644 -- "$candidate" "$staged"')
        ->and($publishCall->arguments ?? [])
        ->toContain('8.5');
});

it('restores the exact AppDev FPM file before the recovery reload when activation fails', function (): void {
    [$node, $project] = app_dev_runtime_models();
    $instance = app_dev_supported_app_instance($node, $project->id);
    app_dev_supported_route($instance, 'acme.app-dev.orbit');
    $harness = new FpmPublishHarness;
    $managed = $harness->prepare('8.5', 'orbit-scopes.conf', "previous app-dev pool\n");
    $ssh = new AppDevFakeSshExecutor([new CommandResult(0, "8.5\n", '', 1, false)]);

    try {
        $manager = new RemoteAppDevPhpFpmManager(
            sites: new DevelopmentSiteRepository,
            renderer: new DevelopmentPhpFpmConfigRenderer,
            ssh: app_dev_ssh($ssh),
            accounts: app_dev_account_resolver(),
            packages: new RemotePhpPackageManager,
            phpRoot: $harness->phpRoot(),
            lockDirectory: $harness->lockDirectory(),
        );
        $manager->converge($node);
        $result = $harness->run($ssh->commands[1]);

        expect($result->succeeded())
            ->toBeFalse($result->stderr)
            ->and(file_get_contents($managed))
            ->toBe("previous app-dev pool\n")
            ->and(fileperms($managed) & 0o777)
            ->toBe(0o600)
            ->and($harness->serviceCalls())
            ->toBe(
                [
                    'enable php8.5-fpm',
                    'reload-or-restart php8.5-fpm',
                    'reload-or-restart php8.5-fpm',
                ],
                $result->stderr,
            );
    } finally {
        $harness->cleanup();
    }
});

it('keeps the previous AppDev FPM file and service untouched when the candidate fails php-fpm -t', function (): void {
    [$node, $project] = app_dev_runtime_models();
    $instance = app_dev_supported_app_instance($node, $project->id);
    app_dev_supported_route($instance, 'acme.app-dev.orbit');
    $harness = new FpmPublishHarness;
    $managed = $harness->prepare('8.5', 'orbit-scopes.conf', "previous app-dev pool\n");
    $harness->failConfigTest();
    $ssh = new AppDevFakeSshExecutor([new CommandResult(0, "8.5\n", '', 1, false)]);

    try {
        $manager = new RemoteAppDevPhpFpmManager(
            sites: new DevelopmentSiteRepository,
            renderer: new DevelopmentPhpFpmConfigRenderer,
            ssh: app_dev_ssh($ssh),
            accounts: app_dev_account_resolver(),
            packages: new RemotePhpPackageManager,
            phpRoot: $harness->phpRoot(),
            lockDirectory: $harness->lockDirectory(),
        );
        $manager->converge($node);
        $result = $harness->run($ssh->commands[1]);

        expect($result->succeeded())
            ->toBeFalse()
            ->and(file_get_contents($managed))
            ->toBe("previous app-dev pool\n")
            ->and($harness->serviceCalls())
            ->toBe([]);
    } finally {
        $harness->cleanup();
    }
});

it('publishes the other pools, then fails, when a desired pool names a missing working directory', function (): void {
    [$node, $project] = app_dev_runtime_models();
    $present = app_dev_supported_app_instance($node, $project->id, 'present');
    app_dev_supported_route($present, 'present.app-dev.orbit');
    $missing = app_dev_supported_app_instance($node, $project->id, 'missing');
    app_dev_supported_route($missing, 'missing.app-dev.orbit');
    $sites = new DevelopmentSiteRepository()->forNode($node);
    $account = new ManagedUserAccount('orbit', 'orbit', '/home/orbit');
    $renderer = new DevelopmentPhpFpmConfigRenderer;
    $missingSite = $sites->first(static fn (DevelopmentSite $site): bool => $site->domain === 'missing.app-dev.orbit');
    $ssh = new AppDevFakeSshExecutor([
        new CommandResult(0, "8.5\t".base64_encode($renderer->render($sites, $account))."\nmissing-directory\t/home/orbit/apps/acme/missing\n", '', 1, false),
    ]);
    $manager = new RemoteAppDevPhpFpmManager(
        sites: new DevelopmentSiteRepository,
        renderer: $renderer,
        ssh: app_dev_ssh($ssh),
        accounts: app_dev_account_resolver(),
        packages: new RemotePhpPackageManager,
    );

    expect(fn () => $manager->converge($node))
        ->toThrow(function (RuntimeConvergenceException $exception) use ($missing): void {
            expect($exception->errorCode)
                ->toBe(RemoteAppDevPhpFpmManager::PoolDirectoryMissing)
                ->and($exception->getMessage())
                ->toContain('orbit-app-instance-'.$missing->id, '/home/orbit/apps/acme/missing');
        });

    $publishCall = collect($ssh->commands)
        ->first(static fn (RemoteCommand $command): bool => str_contains($command->input ?? '', 'php-fpm.conf'));
    $kept = $renderer->render($sites->reject(static fn (DevelopmentSite $site): bool => $site === $missingSite)->values(), $account);

    expect($ssh->commands[0]->arguments)
        ->toContain('/home/orbit/apps/acme/present', '/home/orbit/apps/acme/missing')
        ->and($kept)
        ->toContain('[orbit-app-instance-'.$present->id.']')
        ->not->toContain('[orbit-app-instance-'.$missing->id.']')
        ->and($publishCall?->input)
        ->toContain(base64_encode($kept));
});

it('rolls back to the previous pools without the pool whose working directory is gone', function (): void {
    [$node, $project] = app_dev_runtime_models();
    $moving = app_dev_supported_app_instance($node, $project->id, 'moving');
    app_dev_supported_route($moving, 'moving.app-dev.orbit');
    $stable = app_dev_supported_app_instance($node, $project->id, 'stable');
    app_dev_supported_route($stable, 'stable.app-dev.orbit');
    $renderer = new DevelopmentPhpFpmConfigRenderer;
    $current = $renderer->render(new DevelopmentSiteRepository()->forNode($node), new ManagedUserAccount('orbit', 'orbit', '/home/orbit'));
    $previous = $current."[orbit-app-instance-342]\nchdir = /fast/apps/orbit-website/task-1172\n";
    $moving->update(['selected_php_version' => '8.4']);
    $ssh = new AppDevFakeSshExecutor([
        new CommandResult(0, "8.5\t".base64_encode($previous)."\nmissing-directory\t/fast/apps/orbit-website/task-1172\n", '', 1, false),
        new CommandResult(0, '', '', 1, false),
        new CommandResult(1, '', 'activation failed', 1, false),
    ]);
    $manager = new RemoteAppDevPhpFpmManager(
        sites: new DevelopmentSiteRepository,
        renderer: $renderer,
        ssh: app_dev_ssh($ssh),
        accounts: app_dev_account_resolver(),
        packages: new RemotePhpPackageManager,
    );

    expect(fn () => $manager->converge($node))
        ->toThrow(function (RuntimeConvergenceException $exception): void {
            expect($exception->errorCode)->toBe('app-dev.php_fpm_config_failed');
        });

    $publishCalls = collect($ssh->commands)
        ->filter(static fn (RemoteCommand $command): bool => str_contains($command->input ?? '', 'php-fpm.conf'))
        ->values();

    expect($publishCalls->map(static fn (RemoteCommand $command): string => $command->arguments[4])->all())
        ->toBe(['8.5', '8.4', '8.5'])
        ->and($publishCalls->last()?->input)
        ->toContain(base64_encode($current))
        ->not->toContain(base64_encode($previous));
});

it('reports the installed and desired pools whose working directory is missing, checked as root on the Node', function (): void {
    [$node, $project] = app_dev_runtime_models();
    $harness = new FpmPublishHarness;
    $present = app_dev_supported_app_instance($node, $project->id, 'present');
    $present->update(['checkout_path' => $harness->phpRoot().'/../apps/present']);
    app_dev_supported_route($present, 'present.app-dev.orbit');
    $missing = app_dev_supported_app_instance($node, $project->id, 'missing');
    $missing->update(['checkout_path' => $harness->phpRoot().'/../apps/missing']);
    app_dev_supported_route($missing, 'missing.app-dev.orbit');
    $removed = $harness->phpRoot().'/../apps/task-1172';
    $presentDirectory = $harness->phpRoot().'/../apps/present';
    $missingDirectory = $harness->phpRoot().'/../apps/missing';
    $harness->prepare('8.5', 'orbit-scopes.conf', implode("\n", [
        '[orbit-app-instance-'.$present->id.']',
        "chdir = {$presentDirectory}",
        '[orbit-app-instance-342]',
        "chdir = {$removed}",
        '',
    ]));
    mkdir($presentDirectory, recursive: true);
    $discovery = new AppDevFakeSshExecutor;

    try {
        $manager = static fn (AppDevFakeSshExecutor $ssh): RemoteAppDevPhpFpmManager => new RemoteAppDevPhpFpmManager(
            sites: new DevelopmentSiteRepository,
            renderer: new DevelopmentPhpFpmConfigRenderer,
            ssh: app_dev_ssh($ssh),
            accounts: app_dev_account_resolver(),
            packages: new RemotePhpPackageManager,
            phpRoot: $harness->phpRoot(),
        );
        $manager($discovery)->poolsWithMissingDirectories($node);
        $result = $harness->run($discovery->commands[0]);
        $pools = $manager(new AppDevFakeSshExecutor([$result]))->poolsWithMissingDirectories($node);

        expect($result->succeeded())
            ->toBeTrue($result->stderr)
            ->and($discovery->commands[0]->arguments[0])
            ->toBe('bash')
            ->and($discovery->commands[0]->input)
            ->toContain('| sudo xargs -0 -r -n 1 sh -c')
            ->and($pools)
            ->toBe([
                ['pool' => 'orbit-app-instance-342', 'version' => '8.5', 'directory' => $removed, 'installed' => true],
                ['pool' => 'orbit-app-instance-'.$missing->id, 'version' => '8.5', 'directory' => $missingDirectory, 'installed' => false],
            ]);
    } finally {
        $harness->cleanup();
    }
});

it('installs PHP without starting FPM and replaces a stale pool while FPM is failed', function (): void {
    [$node, $project] = app_dev_runtime_models();
    $instance = app_dev_supported_app_instance($node, $project->id);
    app_dev_supported_route($instance, 'acme.app-dev.orbit');
    $account = new ManagedUserAccount('orbit', 'orbit', '/home/orbit');
    $current = new DevelopmentPhpFpmConfigRenderer()->render(new DevelopmentSiteRepository()->forNode($node), $account);
    $stale = $current."[orbit-app-instance-342]\nchdir = /fast/apps/orbit-website/task-1172\n";
    $harness = new FpmPublishHarness;
    $managed = $harness->prepare('8.5', 'orbit-scopes.conf', $stale);
    $harness->stopService();
    $harness->allowActivation();
    $ssh = new AppDevFakeSshExecutor([
        new CommandResult(0, "8.5\t".base64_encode($stale)."\nmissing-directory\t/fast/apps/orbit-website/task-1172\n", '', 1, false),
    ]);

    try {
        $manager = new RemoteAppDevPhpFpmManager(
            sites: new DevelopmentSiteRepository,
            renderer: new DevelopmentPhpFpmConfigRenderer,
            ssh: app_dev_ssh($ssh),
            accounts: app_dev_account_resolver(),
            packages: new RemotePhpPackageManager,
            phpRoot: $harness->phpRoot(),
            lockDirectory: $harness->lockDirectory(),
        );
        $manager->converge($node);
        $result = $harness->run($ssh->commands[1]);

        expect($this->phpAgents->commands())->toBe(['php.install-build', 'php.configure-runtime'])
            ->and($result->succeeded())
            ->toBeTrue($result->stderr)
            ->and(file_get_contents($managed))
            ->toBe($current)
            ->and($harness->serviceCalls())
            ->toBe(['enable php8.5-fpm', 'reload-or-restart php8.5-fpm'])
            ->and($harness->serviceActive())
            ->toBeTrue();
    } finally {
        $harness->cleanup();
    }
});

it('starts a failed PHP-FPM whose Orbit pools are already current', function (): void {
    [$node, $project] = app_dev_runtime_models();
    $instance = app_dev_supported_app_instance($node, $project->id);
    app_dev_supported_route($instance, 'acme.app-dev.orbit');
    $current = new DevelopmentPhpFpmConfigRenderer()->render(
        new DevelopmentSiteRepository()->forNode($node),
        new ManagedUserAccount('orbit', 'orbit', '/home/orbit'),
    );
    $harness = new FpmPublishHarness;
    $managed = $harness->prepare('8.5', 'orbit-scopes.conf', $current);
    $harness->stopService();
    $harness->allowActivation();
    $ssh = new AppDevFakeSshExecutor([new CommandResult(0, "8.5\t".base64_encode($current)."\n", '', 1, false)]);

    try {
        $manager = new RemoteAppDevPhpFpmManager(
            sites: new DevelopmentSiteRepository,
            renderer: new DevelopmentPhpFpmConfigRenderer,
            ssh: app_dev_ssh($ssh),
            accounts: app_dev_account_resolver(),
            packages: new RemotePhpPackageManager,
            phpRoot: $harness->phpRoot(),
            lockDirectory: $harness->lockDirectory(),
        );
        $manager->converge($node);
        $result = $harness->run($ssh->commands[1]);

        expect($result->succeeded())
            ->toBeTrue($result->stderr)
            ->and(file_get_contents($managed))
            ->toBe($current)
            ->and($harness->serviceCalls())
            ->toBe(['enable php8.5-fpm', 'is-active --quiet php8.5-fpm', 'restart php8.5-fpm'])
            ->and($harness->serviceActive())
            ->toBeTrue();
    } finally {
        $harness->cleanup();
    }
});

it('rejects an unsupported PHP version before target discovery or installation', function (): void {
    [$node, $project] = app_dev_runtime_models();
    $unsupported = app_dev_supported_app_instance($node, $project->id, 'unsupported', '8.3');
    app_dev_supported_route($unsupported, 'unsupported.app-dev.orbit');
    $ssh = new AppDevFakeSshExecutor;
    $manager = new RemoteAppDevPhpFpmManager(
        sites: new DevelopmentSiteRepository,
        renderer: new DevelopmentPhpFpmConfigRenderer,
        ssh: app_dev_ssh($ssh),
        accounts: app_dev_account_resolver(),
        packages: new RemotePhpPackageManager,
    );

    expect(fn () => $manager->converge($node))
        ->toThrow(function (RuntimeConvergenceException $exception): void {
            expect($exception->errorCode)->toBe('app-dev.php_version_unsupported');
        })
        ->and($ssh->commands)
        ->toBeEmpty();
});

it('signs the request of the Node and publishes the leaf without the key leaving the Node', function (): void {
    [$node, $project] = app_dev_runtime_models();
    $instance = app_dev_supported_app_instance($node, $project->id);
    $route = app_dev_supported_route($instance, 'acme.app-dev.orbit');
    $agents = new FakeAgentCommandRunner()
        ->answer('certificates.request-leaf', ['state' => 'request', 'request' => 'CSR FROM TARGET'])
        ->answer('certificates.publish-leaf', []);
    $signer = app_dev_test_signer();
    $manager = new RemoteAppDevCertificateManager($agents, $signer, app_dev_account_resolver());

    $manager->convergeInstance($instance, $route);

    $leaf = $agents->calls[0]['input'];
    expect($agents->commands())->toBe(['certificates.request-leaf', 'certificates.publish-leaf'])
        ->and($leaf)->toMatchArray([
            'scope' => "app-instance-{$instance->id}",
            'domain' => 'acme.app-dev.orbit',
            'root_hash' => hash('sha256', "ROOT CERTIFICATE\n"),
            'user' => 'orbit',
            'home' => '/home/orbit',
        ])
        ->and($signer->calls)->toBe([['domain' => 'acme.app-dev.orbit', 'csr' => 'CSR FROM TARGET']])
        ->and($agents->calls[1]['input'])->toBe([...$leaf, 'certificate' => "LEAF CERTIFICATE\n", 'root_certificate' => "ROOT CERTIFICATE\n"]);
});

it('reuses a current leaf without signing', function (): void {
    [$node, $project] = app_dev_runtime_models();
    $instance = app_dev_supported_app_instance($node, $project->id);
    $route = app_dev_supported_route($instance, 'acme.app-dev.orbit');
    $agents = new FakeAgentCommandRunner()->answer('certificates.request-leaf', ['state' => 'current']);
    $signer = app_dev_test_signer();

    new RemoteAppDevCertificateManager($agents, $signer, app_dev_account_resolver())->convergeInstance($instance, $route);

    expect($agents->commands())->toBe(['certificates.request-leaf'])
        ->and($signer->calls)->toBe([]);
});

it('uses a nondefault managed home for app-dev certificate converge and removal', function (): void {
    $account = new ManagedUserAccount('nckrtl', 'nckrtl', '/srv/users/nckrtl');
    [$node, $project] = app_dev_runtime_models(account: $account);
    $instance = app_dev_supported_app_instance($node, $project->id);
    $route = app_dev_supported_route($instance, 'acme.app-dev.orbit');
    $agents = new FakeAgentCommandRunner()
        ->answer('certificates.request-leaf', ['state' => 'request', 'request' => 'CSR'])
        ->answer('certificates.publish-leaf', [])
        ->answer('certificates.remove-leaf', []);
    $manager = new RemoteAppDevCertificateManager($agents, app_dev_test_signer(), app_dev_account_resolver($account));

    $manager->convergeInstance($instance, $route);
    // Removal withdraws the Route's sites before it removes their certificate.
    $route->update(['status' => RouteStatus::Retiring, 'sites_published' => false]);
    $manager->removeInstance($instance);

    expect($agents->calls[0]['input'])->toMatchArray(['user' => 'nckrtl', 'home' => '/srv/users/nckrtl'])
        ->and($agents->calls[2])->toMatchArray([
            'command' => 'certificates.remove-leaf',
            'input' => ['scope' => "app-instance-{$instance->id}", 'home' => '/srv/users/nckrtl'],
        ]);
});

function app_dev_test_signer(): LeafCertificateSigner
{
    return new class implements LeafCertificateSigner
    {
        /** @var list<array{domain: string, csr: string}> */
        public array $calls = [];

        public function sign(string $domain, string $certificateRequest): string
        {
            $this->calls[] = ['domain' => $domain, 'csr' => $certificateRequest];

            return "LEAF CERTIFICATE\n";
        }

        public function rootCertificate(): string
        {
            return "ROOT CERTIFICATE\n";
        }
    };
}

it('requests a Node Caddy build for Route sites and publishes DNS through its preserved validation aggregate', function (): void {
    [$node, $project] = app_dev_runtime_models();
    $instance = app_dev_supported_app_instance($node, $project->id);
    app_dev_supported_route($instance, 'acme.app-dev.orbit');
    $feature = app_dev_supported_app_instance($node, $project->id, 'feature');
    app_dev_supported_route($feature, 'feature.acme.app-dev.orbit');
    $ssh = new AppDevFakeSshExecutor;
    $builds = new FakeNodeCaddyBuilds;
    $rendered = [];
    $builds->onBuild = function (Node $node) use (&$rendered): void {
        $rendered[] = app(NodeCaddyfileRenderer::class)->render($node)->content;
    };
    $caddy = new RemoteAppDevCaddyManager(builds: $builds, agents: new FakeAgentCommandRunner);
    $processes = new AppDevFakeProcessRunner;
    $dns = new DnsmasqPrivateDnsManager($processes, new DevelopmentDnsConfigRenderer(new DevelopmentSiteRepository, hostnames: app()->tagged(ContributedPrivateHostnames::class)));

    $caddy->build($node);
    $dns->converge();

    expect($builds->built)->toBe([$node->name])
        ->and($rendered[0])
        ->toContain('https://acme.app-dev.orbit {', 'https://feature.acme.app-dev.orbit {')
        ->and($ssh->commands)->toBe([])
        ->and($processes->invocations)
        ->toHaveCount(1)
        ->and($processes->invocations[0]->input)
        ->toContain(
            base64_encode(
                "# Managed by Orbit.\naddress=/.app-dev.orbit/10.44.0.3\nhost-record=acme.app-dev.orbit,10.44.0.3\nhost-record=feature.acme.app-dev.orbit,10.44.0.3\nhost-record=gateway.orbit,10.44.0.1\nlocal=/app-dev.orbit/\n",
            ),
            'exec 9>/run/lock/orbit-dnsmasq.lock',
            'flock -w 30 9',
            'cp -a -- /etc/dnsmasq.d/. "$validation/fragments/"',
            'sed "s#/etc/dnsmasq.d#$validation/fragments#g" /etc/dnsmasq.conf',
            'dnsmasq --test --conf-file="$validation/dnsmasq.conf"',
            'cmp -s -- "$validation/fragments/orbit-records.conf" "$managed"',
            'if systemctl is-active --quiet dnsmasq; then',
            'mv -fT -- "$candidate" "$managed"',
            'systemctl restart dnsmasq',
            'catalog_managed=/var/lib/orbit/private-dns/catalog.json',
            'python3 -c \'import json,sys; json.load(open(sys.argv[1], encoding="utf-8"))\'',
        );
});

it('retains exact DNS records for active and pending Routes on different Routers', function (): void {
    $firstRoute = orb173_dns_projection_route('first', '10.44.0.31', '10.44.0.32', RouteStatus::Active);
    $secondRoute = orb173_dns_projection_route('second', '10.44.0.41', '10.44.0.42', RouteStatus::Pending);
    $processes = new AppDevFakeProcessRunner;
    $renderer = new DevelopmentDnsConfigRenderer(new DevelopmentSiteRepository, hostnames: app()->tagged(ContributedPrivateHostnames::class));
    $manager = new DnsmasqPrivateDnsManager($processes, $renderer);

    $secondRoute->publishSites();
    $manager->converge();

    $configuration = $renderer->render();

    expect($configuration)
        ->toContain(
            "host-record={$firstRoute->domain},10.44.0.32",
            "host-record={$secondRoute->domain},10.44.0.42",
        )
        ->and($processes->invocations)
        ->toHaveCount(1)
        ->and($processes->invocations[0]->input)
        ->toContain(base64_encode($configuration));
});

it('projects only the explicit provisioning node before its active transition', function (): void {
    $pending = Node::query()->create([
        'name' => 'pending-app-dev',
        'status' => LifecycleStatus::Provisioning,
        'platform' => 'linux',
        'tld' => 'pending.orbit',
        'public_ssh_host' => '192.0.2.30',
        'wireguard_ip' => '10.44.0.30',
    ]);
    $pending->roles()->create(['role' => RoleName::AppDev, 'status' => LifecycleStatus::Provisioning]);
    Node::query()->create([
        'name' => 'other-pending-app-dev',
        'status' => LifecycleStatus::Provisioning,
        'platform' => 'linux',
        'tld' => 'other-pending.orbit',
        'public_ssh_host' => '192.0.2.31',
        'wireguard_ip' => '10.44.0.31',
    ]);
    $processes = new AppDevFakeProcessRunner;
    $manager = new DnsmasqPrivateDnsManager($processes, new DevelopmentDnsConfigRenderer(new DevelopmentSiteRepository, hostnames: app()->tagged(ContributedPrivateHostnames::class)));

    $manager->converge($pending);

    expect($processes->invocations[0]->input)
        ->toContain(base64_encode(
            "# Managed by Orbit.\naddress=/.pending.orbit/10.44.0.30\nlocal=/pending.orbit/\n",
        ))
        ->not->toContain(
            base64_encode('address=/.other-pending.orbit/10.44.0.31'),
            'other-pending.orbit',
        );
});

it('projects node wildcards only while the app-dev role is provisioning or active', function (): void {
    foreach ([
        LifecycleStatus::Provisioning,
        LifecycleStatus::Active,
        LifecycleStatus::Removing,
        LifecycleStatus::Failed,
    ] as $index => $status) {
        $node = Node::query()->create([
            'name' => "dns-role-{$status->value}",
            'status' => LifecycleStatus::Active,
            'platform' => 'linux',
            'tld' => "role-{$status->value}.orbit",
            'public_ssh_host' => '192.0.2.'.(40 + $index),
            'wireguard_ip' => '10.44.0.'.(40 + $index),
        ]);
        $node->roles()->create(['role' => RoleName::AppDev, 'status' => $status]);
    }
    $processes = new AppDevFakeProcessRunner;

    new DnsmasqPrivateDnsManager($processes, new DevelopmentDnsConfigRenderer(new DevelopmentSiteRepository, hostnames: app()->tagged(ContributedPrivateHostnames::class)))->converge();

    $input = $processes->invocations[0]->input ?? '';
    preg_match("/printf '%s' '([^']+)'/", $input, $matches);
    $configuration = base64_decode($matches[1] ?? '', strict: true);

    expect($configuration)
        ->toContain('role-provisioning.orbit', 'role-active.orbit')
        ->not->toContain('role-removing.orbit', 'role-failed.orbit');
});

it('holds the shared projection lock while capturing and publishing DNS intent', function (): void {
    app_dev_runtime_models();
    $orbitHome = sys_get_temp_dir().'/orbit-dns-lock-'.Str::uuid();
    config()->set('orbit.home', $orbitHome);
    $processes = new class($orbitHome) implements ProcessRunner
    {
        public bool $observedLock = false;

        public function __construct(
            private readonly string $orbitHome,
        ) {}

        public function run(ProcessInvocation $invocation): CommandResult
        {
            $lock = fopen($this->orbitHome.'/.dnsmasq-projections.lock', mode: 'c+');

            if ($lock === false) {
                throw new RuntimeException('Could not inspect the DNS projection lock.');
            }

            $acquired = flock($lock, LOCK_EX | LOCK_NB);
            $this->observedLock = ! $acquired;

            if ($acquired) {
                flock($lock, LOCK_UN);
            }

            fclose($lock);

            return new CommandResult(0, '', '', 1, false);
        }
    };

    try {
        new DnsmasqPrivateDnsManager($processes, new DevelopmentDnsConfigRenderer(new DevelopmentSiteRepository, hostnames: app()->tagged(ContributedPrivateHostnames::class)))->converge();

        expect($processes->observedLock)->toBeTrue();
    } finally {
        new Filesystem()->deleteDirectory($orbitHome);
    }
});

it('keeps one reentrant projection owner until outer completion and releases it after failure', function (): void {
    $orbitHome = sys_get_temp_dir().'/orbit-development-projection-'.Str::uuid();
    $now = 0.0;
    $clock = static function () use (&$now): float {
        return $now;
    };
    $contenderDeadline = new CommandDeadline($clock);
    $contenderDeadline->start(0.02);
    $owner = new NativeDevelopmentProjectionOperationLock($orbitHome, new CommandDeadline($clock), $clock);
    $contender = new NativeDevelopmentProjectionOperationLock(
        $orbitHome,
        $contenderDeadline,
        $clock,
        static function (int $microseconds) use (&$now): void {
            $now += $microseconds / 1_000_000;
        },
    );
    $events = [];

    try {
        $owner->run(function () use ($owner, $contender, &$events): void {
            $events[] = 'outer-enter';
            $owner->run(function () use (&$events): void {
                $events[] = 'inner-enter';
            });
            $events[] = 'inner-return';

            expect(fn () => $contender->run(static fn (): string => 'contended'))
                ->toThrow(function (ResourceOperationException $exception): void {
                    expect($exception->errorCode)
                        ->toBe('app-dev.projection_busy')
                        ->and($exception->status)
                        ->toBe(409);
                });
        });

        expect(fn () => $owner->run(static fn () => throw new RuntimeException('outer failure')))
            ->toThrow(RuntimeException::class, 'outer failure');
        $released = new NativeDevelopmentProjectionOperationLock(
            $orbitHome,
            new CommandDeadline($clock),
            $clock,
        );

        expect($events)
            ->toBe(['outer-enter', 'inner-enter', 'inner-return'])
            ->and($released->run(static fn (): string => 'released'))
            ->toBe('released')
            ->and(fileperms($orbitHome) & 0o777)
            ->toBe(0o700)
            ->and(fileperms($orbitHome.'/.dnsmasq-projections.lock') & 0o777)
            ->toBe(0o600);
    } finally {
        new Filesystem()->deleteDirectory($orbitHome);
    }
});

it('binds one native projection owner for each application request scope', function (): void {
    $first = app(DevelopmentProjectionOperationLock::class);
    $sameScope = app(DevelopmentProjectionOperationLock::class);

    app()->forgetScopedInstances();

    $nextScope = app(DevelopmentProjectionOperationLock::class);

    expect($first)
        ->toBeInstanceOf(NativeDevelopmentProjectionOperationLock::class)
        ->toBe($sameScope)
        ->not->toBe($nextScope);
});

it('waits within the command deadline and continues after the current owner releases', function (): void {
    $orbitHome = sys_get_temp_dir().'/orbit-development-projection-wait-'.Str::uuid();
    mkdir($orbitHome, permissions: 0o700, recursive: true);
    $path = $orbitHome.'/.dnsmasq-projections.lock';
    $held = fopen($path, mode: 'c+');
    expect($held)->not->toBeFalse();
    flock($held, LOCK_EX);
    $now = 10.0;
    $clock = static function () use (&$now): float {
        return $now;
    };
    $deadline = new CommandDeadline($clock);
    $deadline->start(0.025);
    $waits = 0;
    $owner = new NativeDevelopmentProjectionOperationLock(
        $orbitHome,
        $deadline,
        $clock,
        static function (int $microseconds) use (&$now, &$waits, &$held): void {
            $now += $microseconds / 1_000_000;
            $waits++;

            if ($waits === 2 && is_resource($held)) {
                flock($held, LOCK_UN);
                fclose($held);
                $held = null;
            }
        },
    );

    try {
        expect($owner->run(static fn (): string => 'fresh'))
            ->toBe('fresh')
            ->and($waits)
            ->toBe(2)
            ->and(round($now, 3))
            ->toBe(10.02);
    } finally {
        if (is_resource($held)) {
            flock($held, LOCK_UN);
            fclose($held);
        }

        new Filesystem()->deleteDirectory($orbitHome);
    }
});

it('enters projection ownership before app-dev Caddy and DNS host publication', function (): void {
    [$node] = app_dev_runtime_models();
    $owner = new AppDevProjectionOwnerSpy;
    $agents = new FakeAgentCommandRunner()->answer('caddy.order-after-vpn', static function () use ($owner): array {
        expect($owner->active)->toBeTrue();

        return ['changed' => false];
    });
    $caddy = new RemoteAppDevCaddyManager(
        builds: new FakeNodeCaddyBuilds,
        agents: $agents,
        projection: $owner,
    );
    $processes = new class($owner) implements ProcessRunner
    {
        public function __construct(
            private readonly AppDevProjectionOwnerSpy $owner,
        ) {}

        public function run(ProcessInvocation $invocation): CommandResult
        {
            expect($this->owner->active)->toBeTrue();

            return new CommandResult(0, '', '', 1, false);
        }
    };
    $dns = new DnsmasqPrivateDnsManager(
        $processes,
        new DevelopmentDnsConfigRenderer(new DevelopmentSiteRepository, hostnames: app()->tagged(ContributedPrivateHostnames::class)),
        $owner,
    );

    $caddy->converge($node);
    $caddy->remove($node);
    $dns->converge();

    expect($owner->runs)
        ->toBe(3)
        ->and($owner->active)
        ->toBeFalse()
        ->and($agents->calls)
        ->toHaveCount(1);
});

it('orders the app-dev Caddy unit through the agent before it builds the Node', function (): void {
    [$node] = app_dev_runtime_models();
    $agents = new FakeAgentCommandRunner()->answer('caddy.order-after-vpn', ['changed' => false]);
    $builds = new FakeNodeCaddyBuilds;
    $builds->onBuild = fn () => expect($agents->commands())->toBe(['caddy.order-after-vpn']);
    $manager = new RemoteAppDevCaddyManager(builds: $builds, agents: $agents);

    $manager->converge($node);

    expect($builds->built)->toBe([$node->name]);
    expect($agents->calls)->toBe([['node' => $node->name, 'command' => 'caddy.order-after-vpn', 'input' => []]]);
});

it('keeps its error code and records the build stage and Caddy message when the build fails', function (): void {
    [$node] = app_dev_runtime_models();
    $ssh = new AppDevFakeSshExecutor;
    $builds = new FakeNodeCaddyBuilds;
    $builds->failNext($node->name, 'validate', 'Error: adapting config using caddyfile: unrecognized directive');
    $manager = new RemoteAppDevCaddyManager(builds: $builds, agents: new FakeAgentCommandRunner);

    expect(fn () => $manager->build($node))
        ->toThrow(function (RuntimeConvergenceException $exception) use ($node): void {
            expect($exception->errorCode)->toBe('app-dev.caddy_config_failed')
                ->and($exception->step)->toBe('caddy-config')
                ->and($exception->getMessage())->toBe("The Caddy build for Node [{$node->name}] failed at stage [validate]: Error: adapting config using caddyfile: unrecognized directive")
                ->and($exception->result?->stderr)->toContain('failed at stage [validate]');
        });

    expect($ssh->commands)->toBe([]);
});

it('keeps the live DNS fragment untouched when effective validation fails', function (): void {
    app_dev_runtime_models();
    $processes = new AppDevFakeProcessRunner;
    $processes->fail = true;
    $manager = new DnsmasqPrivateDnsManager($processes, new DevelopmentDnsConfigRenderer(new DevelopmentSiteRepository, hostnames: app()->tagged(ContributedPrivateHostnames::class)));

    expect($manager->converge(...))
        ->toThrow(function (RuntimeConvergenceException $exception): void {
            expect($exception->errorCode)->toBe('app-dev.dns_config_failed');
        });

    $script = $processes->invocations[0]->input ?? '';
    $validation = mb_strpos(
        haystack: $script,
        needle: 'dnsmasq --test --conf-file="$validation/dnsmasq.conf"',
    );
    $liveSwitch = mb_strpos(
        haystack: $script,
        needle: 'mv -fT -- "$candidate" "$managed"',
    );
    $restart = mb_strrpos(haystack: $script, needle: 'systemctl restart dnsmasq');

    expect($validation)
        ->toBeInt()
        ->and($liveSwitch)
        ->toBeInt()
        ->and($restart)
        ->toBeInt()
        ->and($validation)
        ->toBeLessThan($liveSwitch)
        ->and($liveSwitch)
        ->toBeLessThan($restart)
        ->and($script)
        ->toContain(
            'exec 9>/run/lock/orbit-dnsmasq.lock',
            'flock -w 30 9',
            'cmp -s -- "$validation/fragments/orbit-records.conf" "$managed"',
            'if ! systemctl restart dnsmasq; then',
            'install -o root -g root -m 0644 -- "$backup" "$managed"',
            'rm -f -- "$managed"',
            'systemctl restart dnsmasq || true',
            'trap \'rm -rf -- "$validation"; rm -f -- "$candidate" "$backup" "$catalog_candidate" "$catalog_backup"\' EXIT',
        );
});

/** @return array{Node, Project} */
function app_dev_runtime_models(
    ManagedUserAccount $account = new ManagedUserAccount('orbit', 'orbit', '/home/orbit'),
): array {
    $node = Node::query()->create([
        'name' => 'app-dev',
        'status' => LifecycleStatus::Active,
        'tld' => 'app-dev.orbit',
        'public_ssh_host' => '192.0.2.10',
        'wireguard_ip' => '10.44.0.3',
        'user' => $account->user,
    ]);
    $node->roles()->create(['role' => RoleName::AppDev, 'status' => LifecycleStatus::Active]);
    $project = Project::query()->create([
        'name' => 'Acme',
        'slug' => 'acme',
        'repository_url' => 'git@github.com:acme/site.git',
    ]);
    $gateway = Node::query()->create([
        'name' => 'gateway',
        'status' => LifecycleStatus::Active,
        'platform' => 'linux',
        'public_ssh_host' => '85.9.218.89',
        'wireguard_ip' => '10.44.0.1',
    ]);
    $gateway->roles()->create(['role' => RoleName::Gateway, 'status' => LifecycleStatus::Active]);

    return [$node, $project];
}

function app_dev_supported_app_instance(
    Node $node,
    int $projectId,
    string $name = 'default',
    string $phpVersion = '8.5',
): Instance {
    return Instance::query()->create([
        'project_id' => $projectId,
        'node_id' => $node->id,
        'name' => $name,
        'checkout_path' => "/home/orbit/apps/acme/{$name}",
        'selected_php_version' => $phpVersion,
        'root' => 'public',
        'status' => InstanceState::Active,
    ]);
}

function app_dev_supported_route(Instance $instance, string $domain): Route
{
    $route = Route::query()->create([
        'project_id' => $instance->project_id,
        'node_id' => $instance->node_id,
        'domain' => $domain,
        'provenance' => RouteProvenance::Explicit,
        'publication' => RoutePublication::Private,
        'status' => RouteStatus::Pending,
    ]);
    $route->targets()->create([
        'instance_id' => $instance->id,
        'position' => 0,
    ]);
    $route->update(['status' => RouteStatus::Active]);

    return $route->refresh();
}

function orb173_dns_projection_route(
    string $name,
    string $workloadAddress,
    string $routerAddress,
    RouteStatus $status,
): Route {
    $cluster = Cluster::query()->create([
        'name' => "{$name}-cluster",
        'state' => ClusterState::Active,
    ]);
    $workload = Node::query()->create([
        'cluster_id' => $cluster->id,
        'name' => "{$name}-workload",
        'status' => LifecycleStatus::Active,
        'platform' => 'linux',
        'tld' => "{$name}.test",
        'public_ssh_host' => "{$name}-workload.test",
        'wireguard_ip' => $workloadAddress,
        'user' => 'orbit',
    ]);
    $workload
        ->roles()
        ->create([
            'role' => RoleName::AppDev,
            'status' => LifecycleStatus::Active,
        ]);
    $router = Node::query()->create([
        'cluster_id' => $cluster->id,
        'name' => "{$name}-router",
        'status' => LifecycleStatus::Active,
        'platform' => 'linux',
        'public_ssh_host' => "{$name}-router.test",
        'wireguard_ip' => $routerAddress,
        'user' => 'orbit',
    ]);
    $router
        ->roles()
        ->create([
            'cluster_id' => $cluster->id,
            'role' => RoleName::Router,
            'status' => LifecycleStatus::Active,
        ]);
    $project = Project::query()->create([
        'name' => ucfirst($name),
        'slug' => $name,
        'repository_url' => "https://example.test/{$name}.git",
        'root' => 'public',
    ]);
    $instance = Instance::query()->create([
        'project_id' => $project->id,
        'node_id' => $workload->id,
        'name' => 'default',
        'checkout_path' => "/home/orbit/apps/{$name}",
        'root' => 'public',
        'branch' => 'main',
        'starting_commit' => str_repeat($name === 'first' ? 'a' : 'b', 40),
        'selected_php_version' => '8.5',
        'status' => $status === RouteStatus::Active
            ? InstanceState::Active
            : InstanceState::SourceResolved,
    ]);
    $route = Route::query()->create([
        'project_id' => $project->id,
        'cluster_id' => $cluster->id,
        'domain' => "{$name}.app.test",
        'provenance' => RouteProvenance::Explicit,
        'publication' => RoutePublication::Private,
        'status' => RouteStatus::Pending,
    ]);
    $route
        ->targets()
        ->create([
            'instance_id' => $instance->id,
            'position' => 0,
        ]);

    if ($status === RouteStatus::Active) {
        $route->update(['status' => RouteStatus::Active]);
    }

    return $route;
}

it('builds the Node on role removal, so the build keeps every Route site stored state still places there', function (): void {
    [$node] = app_dev_runtime_models();
    $ssh = new AppDevFakeSshExecutor;
    $builds = new FakeNodeCaddyBuilds;

    new RemoteAppDevCaddyManager(builds: $builds, agents: new FakeAgentCommandRunner)->remove($node);

    expect($builds->built)->toBe([$node->name])
        ->and($ssh->commands)->toBe([]);
});

final class AppDevProjectionOwnerSpy implements DevelopmentProjectionOperationLock
{
    public int $runs = 0;

    public bool $active = false;

    public function run(Closure $operation): mixed
    {
        $this->runs++;
        $this->active = true;

        try {
            return $operation();
        } finally {
            $this->active = false;
        }
    }
}

function app_dev_ssh(SshExecutor $ssh): DevelopmentSshExecutor
{
    $keys = new class implements SshKeyProvider
    {
        public function privateKeyPath(): string
        {
            return '/home/orbit/.orbit/ssh/id_ed25519';
        }

        public function publicKey(): string
        {
            return 'ssh-ed25519 AAAA';
        }
    };
    $knownHosts = new class implements KnownHostsStore
    {
        public function path(): string
        {
            return '/home/orbit/.orbit/ssh/known_hosts';
        }

        public function put(string $host, int $port, HostKey $key): void {}
    };

    return new DevelopmentSshExecutor($ssh, $keys, $knownHosts);
}

/** The repository recipe plus a PHP 8.6 build, so a test can select a third PHP version. */
function app_dev_recipe_with_php_86(): MachineRecipe
{
    $recipe = new RecipeLoader()->load();
    $tools = $recipe->tools;
    $packages = [];
    foreach ($recipe->php('8.5')->packages as $name) {
        $renamed = str_starts_with($name, 'php8.5-') ? 'php8.6-'.substr($name, 7) : $name;
        $tools[$renamed] = $recipe->tools[$name];
        $packages[] = $renamed;
    }

    return new MachineRecipe($recipe->identity, $recipe->snapshot, $recipe->architectures, $tools, $recipe->profiles, $recipe->canonicalJson, $recipe->json,
        [...$recipe->php, '8.6' => new PhpBuild('8.6', '8.6.0', $packages, $recipe->php('8.5')->extensions)], $recipe->ciPhp);
}

it('orders production Caddy before building and stops on an ordering refusal', function (): void {
    [$node] = app_dev_runtime_models();
    $agents = new FakeAgentCommandRunner()->answer('caddy.order-after-vpn', ['changed' => false]);
    $builds = new FakeNodeCaddyBuilds;
    $builds->onBuild = fn () => expect($agents->commands())->toBe(['caddy.order-after-vpn']);
    $manager = new RemoteAppProdCaddyManager($builds, $agents);

    $manager->converge($node);

    expect($builds->built)->toBe([$node->name]);
    $agents->refuse('caddy.order-after-vpn', 'services.ordering_failed', ['stage' => 'restart']);
    expect(fn () => $manager->converge($node))->toThrow(function (RuntimeConvergenceException $exception): void {
        expect($exception->errorCode)->toBe('app-prod.caddy_config_failed')
            ->and($exception->step)->toBe('app-prod-caddy-service-ordering');
    });
    expect($builds->built)->toBe([$node->name]);
});
