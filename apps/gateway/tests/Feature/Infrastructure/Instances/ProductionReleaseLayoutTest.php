<?php

declare(strict_types=1);

use App\Domain\GitHub\RepositoryReadAccess;
use App\Domain\Instances\ComposerSourceClassifier;
use App\Domain\Instances\InstancePhpVersionCatalog;
use App\Domain\Instances\InstanceState;
use App\Domain\Routes\RouteProvenance;
use App\Domain\Routes\RoutePublication;
use App\Domain\Routes\RouteStatus;
use App\Infrastructure\AppDev\DevelopmentCaddyConfigRenderer;
use App\Infrastructure\AppDev\DevelopmentSite;
use App\Infrastructure\AppDev\DevelopmentSiteRepository;
use App\Infrastructure\AppProd\ProductionSshExecutor;
use App\Infrastructure\Instances\RemoteProductionInstanceSourceLifecycle;
use App\Infrastructure\Processes\CommandResult;
use App\Infrastructure\Ssh\HostKey;
use App\Infrastructure\Ssh\KnownHostsStore;
use App\Infrastructure\Ssh\RemoteCommand;
use App\Infrastructure\Ssh\SshKeyProvider;
use App\Models\Instance;
use App\Models\Node;
use App\Models\Project;
use App\Models\Route;
use Illuminate\Filesystem\Filesystem;
use Symfony\Component\Process\Process;
use Tests\Support\AppDevFakeSshExecutor;
use Tests\Support\BufferedBashProgram;

it('derives production serving paths through current and resolves PHP roots after a release switch', function (): void {
    $node = Node::query()->create([
        'name' => 'release-layout',
        'status' => 'active',
        'platform' => 'linux',
        'public_ssh_host' => '192.0.2.216',
        'wireguard_ip' => '10.44.0.216',
        'user' => 'orbit',
    ]);
    $node->roles()->create(['role' => 'app-prod', 'status' => 'active']);
    $project = Project::query()->create([
        'name' => 'Release layout',
        'slug' => 'release-layout',
        'repository_url' => 'https://example.test/release-layout.git',
        'default_branch' => 'main',
        'root' => 'public',
    ]);
    $instance = Instance::query()->create([
        'project_id' => $project->id,
        'node_id' => $node->id,
        'name' => 'production',
        'environment' => 'production',
        'checkout_path' => '/home/orbit-app-216/releases/initial',
        'production_user' => 'orbit-app-216',
        'production_home' => '/home/orbit-app-216',
        'production_php_socket' => '/run/php/orbit-app-216.sock',
        'selected_php_version' => '8.5',
        'branch' => 'main',
        'starting_commit' => str_repeat('a', 40),
        'status' => InstanceState::SourceResolved,
    ]);
    $route = Route::query()->create([
        'project_id' => $project->id,
        'node_id' => $node->id,
        'domain' => 'release-layout.example.test',
        'provenance' => RouteProvenance::Explicit,
        'publication' => RoutePublication::Public,
        'status' => RouteStatus::Pending,
    ]);
    $route->targets()->create(['instance_id' => $instance->id, 'position' => 0]);
    $route->update(['status' => RouteStatus::Active]);

    $site = new DevelopmentSiteRepository()
        ->forNode($node)
        ->sole();
    $production = new DevelopmentCaddyConfigRenderer()->render(collect([$site]));
    $development = new DevelopmentCaddyConfigRenderer()->render(collect([
        new DevelopmentSite(
            nodeId: $node->id,
            nodeAddress: '10.44.0.216',
            scope: 'development',
            checkoutPath: '/home/orbit/site',
            documentRoot: 'public',
            phpVersion: '8.5',
            domain: 'development.test',
        ),
    ]));

    expect($instance->load('project')->effectiveRoot())
        ->toBe('/home/orbit-app-216/current/public')
        ->and($site->checkoutPath)
        ->toBe('/home/orbit-app-216/current')
        ->and($site->documentRoot)
        ->toBe('public')
        ->and($production)
        ->toContain(
            'root * /home/orbit-app-216/current/public',
            'php_fastcgi unix//run/php/orbit-app-216.sock {',
            'resolve_root_symlink',
        )
        ->and($development)
        ->toContain('php_fastcgi unix//run/php/orbit-development.sock')
        ->not->toContain('resolve_root_symlink');
});

it('uses the current-release root instead of a flat production home', function (): void {
    $node = Node::query()->create([
        'name' => 'flat-production',
        'status' => 'active',
        'platform' => 'linux',
        'public_ssh_host' => '192.0.2.218',
        'wireguard_ip' => '10.44.0.218',
        'user' => 'orbit',
    ]);
    $node->roles()->create(['role' => 'app-prod', 'status' => 'active']);
    $project = Project::query()->create([
        'name' => 'Flat production',
        'slug' => 'flat-production',
        'repository_url' => 'https://example.test/flat-production.git',
        'default_branch' => 'main',
        'root' => 'public',
    ]);
    $instance = Instance::query()->create([
        'project_id' => $project->id,
        'node_id' => $node->id,
        'name' => 'production',
        'environment' => 'production',
        'checkout_path' => '/home/orbit-app-218',
        'production_user' => 'orbit-app-218',
        'production_home' => '/home/orbit-app-218',
        'selected_php_version' => '8.5',
        'branch' => 'main',
        'starting_commit' => str_repeat('b', 40),
        'status' => InstanceState::SourceResolved,
    ]);
    $route = Route::query()->create([
        'project_id' => $project->id,
        'node_id' => $node->id,
        'domain' => 'flat-production.example.test',
        'provenance' => RouteProvenance::Explicit,
        'publication' => RoutePublication::Public,
        'status' => RouteStatus::Pending,
    ]);
    $route->targets()->create(['instance_id' => $instance->id, 'position' => 0]);
    $route->update(['status' => RouteStatus::Active]);

    $site = new DevelopmentSiteRepository()
        ->forNode($node)
        ->sole();
    $configuration = new DevelopmentCaddyConfigRenderer()->render(collect([$site]));

    expect($instance->load('project')->effectiveRoot())
        ->toBe('/home/orbit-app-218/current/public')
        ->and($site->checkoutPath)
        ->toBe('/home/orbit-app-218/current')
        ->and($configuration)
        ->not->toContain('root * /home/orbit-app-218/public');
});

it('authenticates a selected release before publication and clears only its serving link', function (string $webRoot, string $environmentPath, string $target): void {
    [$layout, $ssh, $instance] = orb216_release_layout_lifecycle([
        new CommandResult(0, '', '', 1, false),
        new CommandResult(0, '', '', 1, false),
    ]);

    $instance->update(['root' => $webRoot]);
    $layout->validateCurrent($instance);
    $layout->clearCurrent($instance);

    expect($ssh->commands)
        ->toHaveCount(2)
        ->and($ssh->commands[0]->arguments)
        ->toBe([
            'bash',
            '-seu',
            '--',
            'https://example.test/release-layout.git',
            'orbit-app-216',
            '/home/orbit-app-216',
            (string) $instance->id,
            $webRoot,
            '0',
        ])
        ->and($ssh->commands[0]->input)
        ->toContain(
            'sudo test -f "$marker"',
            'expected=$(printf \'%s\\0%s\\0%s\\0%s\\0\'',
            'case "$selected" in "$releases"/*)',
            'realpath -e -- "$selected_environment"',
            'case "$resolved_root" in "$selected"|"$selected"/*)',
            $environmentPath,
            'readlink -- "$selected_environment")" = '.$target,
        )
        ->and($ssh->commands[1]->arguments[8])
        ->toBe('1')
        ->and($ssh->commands[1]->input)
        ->toContain('sudo -u "$user" -H rm -- "$current"')
        ->not->toContain('rm -rf', 'rm -- "$releases"', 'rm -- "$environment"');
})->with([
    'root public' => ['public', 'selected_environment="$selected/.env"', '../../.env'],
    'nested Laravel' => ['server/web/public', 'selected_environment="$selected${application_suffix}/.env"', '../../../../.env'],
]);

it('leaves legacy flat production layouts outside release validation and cleanup', function (): void {
    [$layout, $ssh, $instance] = orb216_release_layout_lifecycle([]);
    $instance->update(['checkout_path' => '/home/orbit-app-216']);

    $layout->validateCurrent($instance);
    $layout->clearCurrent($instance);

    expect($ssh->commands)->toBe([]);
});

/**
 * @param  list<CommandResult>  $results
 * @return array{RemoteProductionInstanceSourceLifecycle, AppDevFakeSshExecutor, Instance}
 */
function orb216_release_layout_lifecycle(array $results): array
{
    $ssh = new AppDevFakeSshExecutor($results);
    $executor = new ProductionSshExecutor(
        $ssh,
        new class implements SshKeyProvider
        {
            public function privateKeyPath(): string
            {
                return '/tmp/orbit-test-key';
            }

            public function publicKey(): string
            {
                return 'ssh-ed25519 test';
            }
        },
        new class implements KnownHostsStore
        {
            public function path(): string
            {
                return '/tmp/orbit-test-known-hosts';
            }

            public function put(string $host, int $port, HostKey $key): void {}
        },
    );
    $node = Node::query()->create([
        'name' => 'release-layout-remote',
        'status' => 'active',
        'platform' => 'linux',
        'public_ssh_host' => '192.0.2.217',
        'wireguard_ip' => '10.44.0.217',
        'user' => 'orbit',
    ]);
    $node->roles()->create(['role' => 'app-prod', 'status' => 'active']);
    $project = Project::query()->create([
        'name' => 'Release layout remote',
        'slug' => 'release-layout-remote',
        'repository_url' => 'https://example.test/release-layout.git',
        'default_branch' => 'main',
        'root' => 'public',
    ]);
    $instance = Instance::query()->create([
        'project_id' => $project->id,
        'node_id' => $node->id,
        'name' => 'production',
        'environment' => 'production',
        'checkout_path' => '/home/orbit-app-216/releases/initial',
        'production_user' => 'orbit-app-216',
        'production_home' => '/home/orbit-app-216',
    ]);

    return [
        new RemoteProductionInstanceSourceLifecycle(
            $executor,
            new ComposerSourceClassifier(new InstancePhpVersionCatalog),
            app(RepositoryReadAccess::class),
        ),
        $ssh,
        $instance,
    ];
}

it('validates and clears the selected release after initial has been pruned', function (string $webRoot, string $suffix, string $target): void {
    [$layout, $ssh, $instance] = orb216_release_layout_lifecycle([
        new CommandResult(0, '', '', 1, false), new CommandResult(0, '', '', 1, false),
    ]);
    $instance->update(['root' => $webRoot]);
    $layout->validateCurrent($instance);
    $layout->clearCurrent($instance);
    $sandbox = sys_get_temp_dir().'/orbit-pruned-layout-'.bin2hex(random_bytes(6));
    $home = "$sandbox/home";
    mkdir("$sandbox/state", 0700, true);
    mkdir("$home/releases/20261022090000-a$suffix/public", 0700, true);
    file_put_contents("$home/.env", 'APP_ENV=production');
    chmod("$home/.env", 0600);
    symlink($target, "$home/releases/20261022090000-a$suffix/.env");
    symlink('releases/20261022090000-a', "$home/current");
    $user = get_current_user();
    $group = posix_getgrgid(posix_getegid())['name'];
    file_put_contents("$sandbox/state/release-layout", $instance->project->repository_url."\0$user\0$home\0initial\0");
    chmod("$sandbox/state/release-layout", 0600);

    try {
        foreach ($ssh->commands as $command) {
            $input = str_replace([
                'state_directory="/var/lib/orbit/app-instance-sources/$instance"',
                'test "$home" = "/home/$user"', '= root:root',
                '! -group "$user"',
            ], [
                'state_directory="'.$sandbox.'/state"', 'test -d "$home"', '= '.escapeshellarg("$user:$group"),
                '! -group '.escapeshellarg($group),
            ], $command->input);
            $sudo = 'sudo() { if [ "${1:-}" = -u ]; then shift 3; fi; "$@"; }'."\n";
            $input = str_replace('stat -c %G -- "$current")" = "$user"', 'stat -c %G -- "$current")" = '.escapeshellarg($group), $input);
            new Process(['bash', '-seu', '--', $instance->project->repository_url, $user, $home, (string) $instance->id, $webRoot, $command->arguments[8]], input: BufferedBashProgram::stdinPrelude($sandbox).$sudo.$input)->mustRun();
        }
        expect(is_link("$home/current"))->toBeFalse();
        expect(is_dir("$home/releases/initial"))->toBeFalse();
        expect(file_get_contents("$home/.env"))->toBe('APP_ENV=production');
    } finally {
        new Filesystem()->deleteDirectory($sandbox);
    }
})->with(['root' => ['public', '', '../../.env'], 'nested' => ['server/web/public', '/server/web', '../../../../.env']]);

it('removes production data and account after pruning and can resume partial cleanup', function (string $state): void {
    [$layout, $ssh, $instance] = orb216_release_layout_lifecycle([new CommandResult(0, '', '', 1, false)]);
    $user = "orbit-app-{$instance->project_id}";
    $instance->update(['production_user' => $user, 'production_home' => "/home/$user"]);
    $layout->remove($instance);
    [$sandbox, $process] = production_cleanup_program($ssh->commands[0], $state);

    try {
        $process->mustRun();
        expect(is_dir("$sandbox/home"))->toBeFalse();
        expect(file_exists("$sandbox/account"))->toBeFalse();
        expect(is_dir("$sandbox/state"))->toBeFalse();
        expect(file_get_contents("$sandbox/foreign/upload"))->toBe('foreign-data');
        $process->mustRun();
    } finally {
        new Filesystem()->deleteDirectory($sandbox);
    }
})->with(['deployed', 'home-removed', 'account-removed', 'completed', 'terminal-marker-removed']);

it('resumes final state deletion after an interruption removes the marker', function (): void {
    [$layout, $ssh, $instance] = orb216_release_layout_lifecycle([new CommandResult(0, '', '', 1, false)]);
    $user = "orbit-app-{$instance->project_id}";
    $instance->update(['production_user' => $user, 'production_home' => "/home/$user"]);
    $layout->remove($instance);
    [$sandbox, $process] = production_cleanup_program($ssh->commands[0], 'state-rm-interrupted');

    try {
        expect($process->run())->not->toBe(0);
        expect(is_dir("$sandbox/home"))->toBeFalse();
        expect(file_exists("$sandbox/account"))->toBeFalse();
        expect(is_dir("$sandbox/state"))->toBeTrue();
        expect(file_exists("$sandbox/state/release-layout"))->toBeFalse();
        $process->mustRun();
        expect(is_dir("$sandbox/state"))->toBeFalse();
    } finally {
        new Filesystem()->deleteDirectory($sandbox);
    }
});

it('fails production cleanup without deleting its evidence when a resource remains or ownership differs', function (string $state): void {
    [$layout, $ssh, $instance] = orb216_release_layout_lifecycle([new CommandResult(0, '', '', 1, false)]);
    $user = "orbit-app-{$instance->project_id}";
    $instance->update(['production_user' => $user, 'production_home' => "/home/$user"]);
    $layout->remove($instance);
    [$sandbox, $process] = production_cleanup_program($ssh->commands[0], $state);

    try {
        expect($process->run())->not->toBe(0);
        expect(is_dir("$sandbox/state"))->toBeTrue();
        expect(file_get_contents("$sandbox/foreign/upload"))->toBe('foreign-data');
        if (in_array($state, ['wrong-marker', 'missing-marker', 'home-symlink', 'live-process'], true)) {
            expect(file_exists("$sandbox/account"))->toBeTrue();
        }
    } finally {
        new Filesystem()->deleteDirectory($sandbox);
    }
})->with(['wrong-marker', 'home-symlink', 'live-process', 'userdel-fails', 'userdel-lies', 'rm-lies', 'state-rm-lies', 'missing-marker']);

/** @return array{string, Process} */
function production_cleanup_program(RemoteCommand $command, string $state): array
{
    $sandbox = sys_get_temp_dir().'/orbit-production-cleanup-'.bin2hex(random_bytes(6));
    $home = "$sandbox/home";
    $user = $command->arguments[4];
    $owner = posix_getpwuid(posix_geteuid())['name'];
    $group = posix_getgrgid(posix_getegid())['name'];
    mkdir("$sandbox/state", 0700, true);
    mkdir("$sandbox/foreign", 0700, true);
    file_put_contents("$sandbox/foreign/upload", 'foreign-data');
    file_put_contents("$sandbox/account", 'present');
    file_put_contents("$sandbox/state/release-layout", $command->arguments[3]."\0$user\0$home\0initial\0");
    chmod("$sandbox/state/release-layout", 0600);
    mkdir("$home/releases/20261022090000-a", 0700, true);
    file_put_contents("$home/.env", 'secret');
    file_put_contents("$home/database.sqlite", 'application-data');
    symlink("$sandbox/foreign", "$home/shared-link");
    if (in_array($state, ['home-removed', 'completed', 'terminal-marker-removed'], true)) {
        new Filesystem()->deleteDirectory($home);
    }
    if (in_array($state, ['account-removed', 'completed', 'terminal-marker-removed'], true)) {
        unlink("$sandbox/account");
    }
    if ($state === 'completed') {
        new Filesystem()->deleteDirectory("$sandbox/state");
    }
    if (in_array($state, ['terminal-marker-removed', 'missing-marker'], true)) {
        unlink("$sandbox/state/release-layout");
    }
    if ($state === 'wrong-marker') {
        file_put_contents("$sandbox/state/release-layout", 'foreign-marker');
    }
    if ($state === 'home-symlink') {
        new Filesystem()->deleteDirectory($home);
        symlink("$sandbox/foreign", $home);
    }
    $program = str_replace([
        'state_directory="/var/lib/orbit/app-instance-sources/$instance"',
        'test "$home" = "/home/$user"', 'root:root:700', 'root:root:600', '= "$user:$user"',
    ], [
        'state_directory="'.$sandbox.'/state"', 'test "$home" = '.escapeshellarg($home),
        escapeshellarg("$owner:$group:700"), escapeshellarg("$owner:$group:600"), '= '.escapeshellarg("$owner:$group"),
    ], $command->input);
    $stubs = 'sandbox='.escapeshellarg($sandbox)."\n".'state='.escapeshellarg($state)."\n".<<<'BASH'
        getent() {
            [ -f "$sandbox/account" ] || return 2
            printf '%s:x:999:999::%s:/usr/sbin/nologin\n' "$user" "$home"
        }
        id() { if [ "$1" = -gn ]; then printf '%s\n' "$user"; else printf '999\n'; fi; }
        pgrep() { if [ "$state" = live-process ]; then return 0; else return 1; fi; }
        sudo() {
            if [ "$1" = userdel ]; then
                [ "$state" != userdel-fails ] || return 1
                [ "$state" != userdel-lies ] || return 0
                rm -- "$sandbox/account"
                return
            fi
            if [ "$1" = rm ] && [ "$state" = rm-lies ]; then return 0; fi
            if [ "$1" = rm ] && [ "$state" = state-rm-lies ] && [ "${@: -1}" = "$sandbox/state" ]; then return 0; fi
            if [ "$1" = rm ] && [ "$state" = state-rm-interrupted ] && [ "${@: -1}" = "$sandbox/state" ] && [ -f "$sandbox/state/release-layout" ]; then
                rm -- "$sandbox/state/release-layout"
                return 1
            fi
            "$@"
        }
        BASH;
    $arguments = $command->arguments;
    $arguments[5] = $home;

    return [$sandbox, new Process($arguments, input: BufferedBashProgram::stdinPrelude($sandbox).$stubs."\n".$program)];
}
