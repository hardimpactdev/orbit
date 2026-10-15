<?php

declare(strict_types=1);

use App\Domain\GitHub\RepositoryReadAccess;
use App\Domain\Instances\Deployment\DeploymentEvent;
use App\Domain\Instances\Deployment\DeploymentOutputStream;
use App\Domain\Instances\Deployment\DeploymentPhase;
use App\Domain\Instances\Deployment\DeploymentRelease;
use App\Domain\Instances\Deployment\DeploymentRequest;
use App\Domain\Instances\Deployment\DeploymentStep;
use App\Domain\Shared\ResourceOperationException;
use App\Infrastructure\AppProd\ProductionSshExecutor;
use App\Infrastructure\Instances\ProductionApplicationPaths;
use App\Infrastructure\Instances\ProductionWebRootProgram;
use App\Infrastructure\Instances\RemoteProductionDeployment;
use App\Infrastructure\Processes\CommandResult;
use App\Infrastructure\Processes\ProcessOutput;
use App\Infrastructure\Processes\ProcessOutputStream;
use App\Infrastructure\Processes\ProtectedInput;
use App\Infrastructure\Ssh\HostKey;
use App\Infrastructure\Ssh\KnownHostsStore;
use App\Infrastructure\Ssh\SshKeyProvider;
use App\Models\Instance;
use App\Models\Node;
use App\Models\Project;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;
use Tests\Feature\GitHub\GitHubTestSupport;
use Tests\Support\AppDevFakeSshExecutor;
use Tests\Support\LinuxHost;

pest()->group('privileged');

it('prepares a fresh branch-pinned release without changing current', function (string $webRoot, string $target): void {
    [$deployment, $ssh, $instance] = orb219_remote_deployment([
        new CommandResult(0, "20260911-a1\t".str_repeat('a', 40)."\n", '', 1, false),
    ]);

    $instance->update(['root' => $webRoot]);
    $release = $deployment->prepare($instance, 'release');

    expect($release->name)
        ->toBe('20260911-a1')
        ->and($release->path)
        ->toBe('/home/orbit-app-1/releases/20260911-a1')
        ->and($release->commit)
        ->toBe(str_repeat('a', 40))
        ->and($ssh->commands[0]->arguments)
        ->toBe([
            'bash',
            '-seu',
            '--',
            'https://example.test/deployment.git',
            'orbit-app-1',
            '/home/orbit-app-1',
            (string) $instance->id,
            'release',
            '20260911-a1',
            $webRoot,
        ])
        ->and($ssh->commands[0]->input)
        ->toContain(
            'git clone --no-checkout --origin origin',
            'git -C "$release" fetch --prune -- origin',
            'show-ref --verify --quiet "$source_ref"',
            'checkout --detach "$source_ref"',
            'ln -s '.$target.' "$release_environment"',
            'test ! -e "$release"',
            'check_web_root_links "$release" "$selected_root"',
        )
        ->not->toContain('mv -Tf -- "$temporary" "$current"');
})->with(['root public' => ['public', '../../.env'], 'nested Laravel' => ['server/web/public', '../../../../.env']]);

it('seeds shared storage before steps and keeps existing files through later releases', function (string $root, string $directory, bool $existing, ?array $declared = null): void {
    if (LinuxHost::delegate($this)) {
        return;
    }

    [$deployment, $ssh, $instance] = orb219_remote_deployment([
        new CommandResult(0, "20260911-a1\t".str_repeat('a', 40)."\n", '', 1, false),
        new CommandResult(0, "20260911-a1\t".str_repeat('a', 40)."\n", '', 1, false),
    ]);
    $instance->update(['root' => $root, 'shared_paths' => $declared]);
    $prepared = $deployment->prepare($instance, 'main');
    $deployment->activate($instance, $prepared);
    $filesystem = new Filesystem;
    $sandbox = sys_get_temp_dir().'/orbit-shared-paths-'.bin2hex(random_bytes(6));
    $home = $sandbox.'/home';
    $repository = $sandbox.'/repository';
    $user = trim(new Process(['id', '-un'])->mustRun()->getOutput());
    $group = trim(new Process(['id', '-gn'])->mustRun()->getOutput());
    $storage = $directory.'storage';

    try {
        $filesystem->ensureDirectoryExists($repository.'/'.$root);
        $filesystem->ensureDirectoryExists($repository.'/'.$storage.'/app/public');
        file_put_contents($repository.'/'.$root.'/index.php', '<?php');
        file_put_contents($repository.'/'.$storage.'/app/public/seed.txt', 'seed');
        foreach ([['git', 'init', '-q', '-b', 'main', $repository], ['git', '-C', $repository, 'add', '.'], ['git', '-C', $repository, '-c', 'user.name=Test', '-c', 'user.email=test@example.test', 'commit', '-qm', 'seed']] as $arguments) {
            new Process($arguments)->mustRun();
        }
        $filesystem->ensureDirectoryExists($home.'/releases');
        $filesystem->ensureDirectoryExists($home.'/state', 0700);
        $filesystem->ensureDirectoryExists($sandbox.'/bin');
        file_put_contents($home.'/.env', 'APP_ENV=production');
        file_put_contents($home.'/state/release-layout', $repository."\0$user\0$home\0initial\0");
        chmod($home.'/state/release-layout', 0600);
        file_put_contents($sandbox.'/bin/sudo', "#!/usr/bin/env bash\nif [ \"\${1:-}\" = -u ]; then shift 2; fi\nif [ \"\${1:-}\" = -H ]; then shift; fi\nexec \"\$@\"\n");
        chmod($sandbox.'/bin/sudo', 0755);
        if ($existing) {
            $filesystem->ensureDirectoryExists($home.'/'.$storage.'/app/public');
            file_put_contents($home.'/'.$storage.'/app/public/upload.txt', 'uploaded');
        }
        $script = str_replace(
            ['state_directory="/var/lib/orbit/app-instance-sources/$instance"', 'test "$home" = "/home/$user"', 'root:root:700', 'root:root:600'],
            ['state_directory="$home/state"', 'test -d "$home"', "$user:$group:700", "$user:$group:600"],
            $ssh->commands[0]->input ?? '',
        );
        $arguments = $ssh->commands[0]->arguments;
        array_splice($arguments, 3, 3, [$repository, $user, $home]);

        foreach (['20260911-a1', '20260912-a2'] as $name) {
            $arguments[8] = $name;
            new Process($arguments, env: ['PATH' => $sandbox.'/bin:'.getenv('PATH')], input: $script)->mustRun();
            $release = $home.'/releases/'.$name;
            expect(is_link($release.'/'.$storage))->toBeTrue()
                ->and(realpath($release.'/'.$storage))->toBe($home.'/'.$storage)
                ->and(realpath($release.'/'.$root.'/storage'))->toBe($home.'/'.$storage.'/app/public');
            expect(file_get_contents($home.'/'.$storage.'/app/public/'.($existing ? 'upload.txt' : 'seed.txt')))->toBe($existing ? 'uploaded' : 'seed');
            // A before-activation command writes through the prepared link, as application steps do.
            file_put_contents($release.'/'.$storage.'/app/public/seed.txt', 'runtime');
            $existing = true;
            file_put_contents($home.'/'.$storage.'/app/public/upload.txt', 'uploaded');
        }
        expect(file_get_contents($home.'/'.$storage.'/app/public/seed.txt'))->toBe('runtime');
        $activation = str_replace(
            ['state_directory="/var/lib/orbit/app-instance-sources/$instance"', 'root:root:600'],
            ['state_directory="$home/state"', "$user:$group:600"],
            $ssh->commands[1]->input ?? '',
        );
        $arguments = $ssh->commands[1]->arguments;
        array_splice($arguments, 3, 3, [$repository, $user, $home]);
        $arguments[7] = '20260912-a2';
        $arguments[8] = trim(new Process(['git', '-C', $repository, 'rev-parse', 'HEAD'])->mustRun()->getOutput());
        new Process($arguments, env: ['PATH' => $sandbox.'/bin:'.getenv('PATH')], input: $activation)->mustRun();

        if ($declared !== null) {
            expect(is_dir($home.'/var/uploads'))->toBeTrue()
                ->and(realpath($home.'/releases/20260912-a2/var/uploads'))->toBe($home.'/var/uploads');
        }
        expect(readlink($home.'/current'))->toBe('releases/20260912-a2');
        expect(new Process(['getfacl', '-cp', $home.'/'.$storage.'/app/public'])->mustRun()->getOutput())
            ->toContain('user:caddy:r-x', 'default:user:caddy:r-x');
    } finally {
        $filesystem->deleteDirectory($sandbox);
    }
})->with([
    'first Laravel deployment' => ['public', '', false],
    'existing hand-made storage' => ['public', '', true],
    'nested Laravel first deployment' => ['apps/site/public', 'apps/site/', false],
    'custom directories absent from source' => ['public', '', false, ['storage', 'var/uploads']],
]);

it('refuses unsafe shared directory placement before removing source files', function (string $variant): void {
    if (LinuxHost::delegate($this)) {
        return;
    }

    $filesystem = new Filesystem;
    $sandbox = sys_get_temp_dir().'/orbit-unsafe-shared-'.bin2hex(random_bytes(6));
    $home = $sandbox.'/home';
    $release = $home.'/releases/fresh';
    $path = $variant === 'web root' ? 'public' : 'storage';

    try {
        foreach ([$release.'/public', $release.'/storage', $sandbox.'/outside', $sandbox.'/bin'] as $directory) {
            $filesystem->ensureDirectoryExists($directory);
        }
        file_put_contents($sandbox.'/outside/keep.txt', 'untouched');
        if ($variant === 'source link') {
            rmdir($release.'/storage');
            symlink($sandbox.'/outside', $release.'/storage');
        } elseif ($variant === 'destination link') {
            symlink($sandbox.'/outside', $home.'/storage');
        } elseif ($variant === 'source file') {
            rmdir($release.'/storage');
            file_put_contents($release.'/storage', 'untouched');
        } elseif ($variant === 'destination ancestor') {
            symlink($sandbox.'/outside', $home.'/var');
            $path = 'var/uploads';
        } elseif ($variant === 'source ancestor') {
            symlink($sandbox.'/outside', $release.'/var');
            $path = 'var/uploads';
        } elseif ($variant === 'seed link') {
            symlink($sandbox.'/outside', $release.'/storage/foreign');
        }
        file_put_contents($sandbox.'/bin/sudo', "#!/usr/bin/env bash\nif [ \"\${1:-}\" = -u ]; then shift 2; fi\nif [ \"\${1:-}\" = -H ]; then shift; fi\nexec \"\$@\"\n");
        chmod($sandbox.'/bin/sudo', 0755);
        $script = ProductionApplicationPaths::render(<<<'BASH'
            home=$1
            release=$2
            user=$(id -un)
            relative_root=public
            __SHARED_PATHS__
            BASH, 'public', [$path]);
        $process = new Process(['bash', '-seu', '--', $home, $release], env: ['PATH' => $sandbox.'/bin:'.getenv('PATH')], input: $script);

        expect($process->run())->not->toBe(0)
            ->and(file_get_contents($sandbox.'/outside/keep.txt'))->toBe('untouched')
            ->and(is_link($release.'/'.$path))->toBe(in_array($variant, ['source link'], true));
    } finally {
        $filesystem->deleteDirectory($sandbox);
    }
})->with(['source link', 'destination link', 'source file', 'destination ancestor', 'source ancestor', 'seed link', 'web root']);

it('allows only the exact public storage target and refuses nested or unrelated links', function (string $variant, bool $allowed): void {
    if (LinuxHost::delegate($this)) {
        return;
    }

    $filesystem = new Filesystem;
    $sandbox = sys_get_temp_dir().'/orbit-public-storage-'.bin2hex(random_bytes(6));
    $home = $sandbox.'/home';
    $release = $home.'/releases/fresh';

    try {
        $filesystem->ensureDirectoryExists($release.'/public');
        $filesystem->ensureDirectoryExists($home.'/storage/app/public');
        $filesystem->ensureDirectoryExists($sandbox.'/bin');
        file_put_contents($home.'/storage/app/public/upload.txt', 'public');
        file_put_contents($home.'/.env', 'private');
        symlink($home.'/storage', $release.'/storage');
        symlink($variant === 'wrong target' ? $home : '../storage/app/public', $release.'/public/storage');
        if ($variant === 'unrelated link') {
            symlink($home.'/.env', $release.'/public/secret');
        }
        if ($variant === 'nested link') {
            symlink($home.'/.env', $home.'/storage/app/public/secret');
        }
        if ($variant === 'broken link') {
            unlink($release.'/public/storage');
            symlink('../missing', $release.'/public/storage');
        }
        if ($variant === 'target ancestor link') {
            rename($home.'/storage/app/public', $home.'/uploads');
            symlink($home.'/uploads', $home.'/storage/app/public');
        }
        file_put_contents($sandbox.'/bin/sudo', "#!/usr/bin/env bash\nif [ \"\${1:-}\" = -u ]; then shift 2; fi\nif [ \"\${1:-}\" = -H ]; then shift; fi\nexec \"\$@\"\n");
        chmod($sandbox.'/bin/sudo', 0755);
        $program = ProductionWebRootProgram::functions()."\n".<<<'BASH'
            home=$1
            release=$2
            user=$(id -un)
            check_web_root_links "$release" "$release/public"
            BASH;
        $process = new Process(['bash', '-seu', '--', $home, $release], env: ['PATH' => $sandbox.'/bin:'.getenv('PATH')], input: $program);

        expect($process->run() === 0)->toBe($allowed);
    } finally {
        $filesystem->deleteDirectory($sandbox);
    }
})->with([
    'public storage' => ['safe', true],
    'unrelated link' => ['unrelated link', false],
    'wrong target' => ['wrong target', false],
    'nested link' => ['nested link', false],
    'broken link' => ['broken link', false],
    'target ancestor link' => ['target ancestor link', false],
]);

it('carries a GitHub App token only in protected input for a covered repository', function (): void {
    [$deployment, $ssh, $instance] = orb219_remote_deployment([
        new CommandResult(0, "20260911-a1\t".str_repeat('a', 40)."\n", '', 1, false),
    ]);
    $instance->project->update(['repository_url' => 'git@github.com:acme/deployment.git']);
    GitHubTestSupport::storeApp();
    Http::fake([
        'https://api.github.com/repos/acme/deployment/installation' => Http::response(['id' => 9]),
        'https://api.github.com/app/installations/9/access_tokens' => Http::response(['token' => 'ghs_sentinel'], 201),
    ]);

    $deployment->prepare($instance->refresh(), 'release');

    $command = $ssh->commands[0];
    $header = base64_encode('x-access-token:ghs_sentinel');
    $script = stream_get_contents($command->protectedInput?->stream());

    expect($command->input)->toBeNull()
        ->and(implode(' ', $command->arguments))->not->toContain('ghs_sentinel')->not->toContain($header)
        ->and($command->arguments[3])->toBe('git@github.com:acme/deployment.git')
        ->and($script)
        ->toContain("export GIT_CONFIG_VALUE_0='Authorization: Basic {$header}'")
        ->toContain('git_read sudo $git_read_sudo -u "$user" -H git clone --no-checkout --origin origin')
        ->toContain('git_read sudo $git_read_sudo -u "$user" -H git -C "$release" fetch --prune -- origin');
});

it('runs protected application input from the release with streaming controls', function (): void {
    [$deployment, $ssh, $instance] = orb219_remote_deployment([
        new CommandResult(0, 'final-out', 'final-error', 12, false),
    ]);
    $events = [];
    $request = new DeploymentRequest(static function (DeploymentEvent $event) use (&$events): void {
        $events[] = $event;
    });
    $step = new DeploymentStep(
        'build',
        DeploymentPhase::BeforeActivation,
        'printf "private-command"',
        37,
    );

    $result = $deployment->executeStep(
        $instance,
        new DeploymentRelease('retained', '/home/orbit-app-1/releases/retained', str_repeat('b', 40)),
        $step,
        $request,
    );
    $command = $ssh->commands[0];
    ($command->output)(new ProcessOutput(ProcessOutputStream::Stdout, 'one'));
    ($command->output)(new ProcessOutput(ProcessOutputStream::Stderr, 'two'));

    expect($result->stdout)
        ->toBe('final-out')
        ->and($command->arguments)
        ->toBe(['bash', '-seu', '--', 'orbit-app-1', '/home/orbit-app-1', '/home/orbit-app-1/releases/retained'])
        ->and($command->timeout)
        ->toBe(37.0)
        ->and($command->protectedInput)
        ->toBeInstanceOf(ProtectedInput::class)
        ->and($command->input)
        ->toBeNull()
        ->and(implode("\0", $command->arguments))
        ->not->toContain('private-command')
        ->and(print_r($command, return: true))
        ->not->toContain('private-command')
        ->and(stream_get_contents($command->protectedInput->stream()))
        ->toContain(
            'owner=$PPID',
            'sudo -u "$user" -H setsid --wait bash -eu -c \'umask 077; cd -- "$1"; exec bash -eu "$2"\'',
            'supervisor=$!',
            'candidate=$(pgrep -P "$supervisor"',
            '[ "$1" = "$supervisor" ] && [ "$2" = "$candidate" ] && [ "$3" = "$expected_uid" ]',
            'process_group=$(discover_process_group)',
            'while kill -0 "$owner" 2>/dev/null && kill -0 "$supervisor" 2>/dev/null',
            'sudo -u "$user" -H kill -TERM -- "-$process_group"',
            'sudo -u "$user" -H kill -KILL -- "-$process_group"',
            'sudo -u "$user" -H kill -TERM -- "-$watched_group"',
            'sudo -u "$user" -H kill -KILL -- "-$watched_group"',
            base64_encode('printf "private-command"'),
        )
        ->not->toContain(
            'group_file',
            'completion_file',
            'sudo kill -TERM',
            'sudo kill -KILL',
        )
        ->and(array_map(
            static fn (DeploymentEvent $event): array => [$event->step, $event->stream, $event->value],
            $events,
        ))
        ->toBe([
            ['build', DeploymentOutputStream::Stdout, 'one'],
            ['build', DeploymentOutputStream::Stderr, 'two'],
        ]);
});

it('publishes one validated release with an atomic current replacement', function (string $webRoot, string $environmentPath): void {
    [$deployment, $ssh, $instance] = orb219_remote_deployment([
        new CommandResult(0, "retained\t".str_repeat('b', 40)."\n", '', 1, false),
    ]);
    $release = new DeploymentRelease(
        'retained',
        '/home/orbit-app-1/releases/retained',
        str_repeat('b', 40),
    );

    $instance->update(['root' => $webRoot]);
    $selected = $deployment->activate($instance, $release);

    expect($selected)
        ->toBe($release)
        ->and($ssh->commands[0]->input)
        ->toContain(
            'config --null --get remote.origin.url',
            'realpath -m -- "$release/$relative_root"',
            'check_web_root_links "$release" "$selected_root"',
            'sudo setfacl -m u:caddy:--x "$release"',
            'sudo setfacl -P -R -m u:caddy:r-X "$selected_root"',
            'sudo find -P "$selected_root" -type d -exec setfacl -m d:u:caddy:r-x -- {} +',
            'ln -s "releases/$name" "$temporary"',
            'mv -Tf -- "$temporary" "$current"',
            $environmentPath,
        );
})->with([
    'root public' => ['public', 'release_environment="$release/.env"'],
    'nested Laravel' => ['server/web/public', 'release_environment="$release${application_suffix}/.env"'],
]);

it('inspects current and retained releases through owned source and root boundaries', function (string $webRoot, string $environmentPath): void {
    [$deployment, $ssh, $instance] = orb219_remote_deployment([
        new CommandResult(0, "initial\t".str_repeat('a', 40)."\n", '', 1, false),
        new CommandResult(0, "retained\t".str_repeat('b', 40)."\n", '', 1, false),
    ]);

    $instance->update(['root' => $webRoot]);
    $selected = $deployment->selected($instance);
    $retained = $deployment->retained($instance, 'retained');

    expect($selected?->path)
        ->toBe('/home/orbit-app-1/releases/initial')
        ->and($retained->path)
        ->toBe('/home/orbit-app-1/releases/retained');

    foreach ($ssh->commands as $command) {
        expect($command->input)->toContain(
            'config --null --get remote.origin.url',
            'realpath -m -- "$release/$relative_root"',
            'check_web_root_links "$release" "$selected_root"',
            'find -P "$release" -xdev ! -user "$user"',
            'realpath -e -- "$release_environment"',
            $environmentPath,
        );
    }

    expect($ssh->commands[0]->input)
        ->toContain('case "$release" in "$releases"/*)')
        ->and($ssh->commands[1]->input)
        ->toContain('release="$releases/$name"');
})->with([
    'root public' => ['public', 'release_environment="$release/.env"'],
    'nested Laravel' => ['server/web/public', 'release_environment="$release${application_suffix}/.env"'],
]);

it('reports no selection when current does not exist', function (): void {
    [$deployment, $ssh, $instance] = orb219_remote_deployment([
        new CommandResult(0, "NONE\n", '', 1, false),
    ]);

    expect($deployment->selected($instance))
        ->toBeNull()
        ->and($ssh->commands)
        ->toHaveCount(1);
});

it('lists only validated retained release names and the current selection', function (): void {
    [$deployment, $ssh, $instance] = orb219_remote_deployment([
        new CommandResult(
            0,
            "SELECTED\tinitial\nRELEASE\tinitial\t".str_repeat('a', 40)."\nRELEASE\tretained\t".str_repeat('b', 40)."\n",
            '',
            1,
            false,
        ),
    ]);

    $state = $deployment->releases($instance);

    expect($state->releases)
        ->toBe(['initial', 'retained'])
        ->and($state->selectedRelease)
        ->toBe('initial')
        ->and($ssh->commands[0]->input)
        ->toContain(
            'find -P "$releases" -mindepth 1 -maxdepth 1 -type d',
            'config --null --get remote.origin.url',
            'realpath -m -- "$release/$relative_root"',
            'check_web_root_links "$release" "$selected_root"',
            'find -P "$release" -xdev ! -user "$user"',
            'realpath -e -- "$release_environment"',
        );
});

it('reports a nullable current selection while retaining present releases', function (): void {
    [$deployment, $ssh, $instance] = orb219_remote_deployment([
        new CommandResult(
            0,
            "SELECTED\t\nRELEASE\tinitial\t".str_repeat('a', 40)."\n",
            '',
            1,
            false,
        ),
    ]);

    $state = $deployment->releases($instance);

    expect($state->releases)
        ->toBe(['initial'])
        ->and($state->selectedRelease)
        ->toBeNull()
        ->and($ssh->commands)
        ->toHaveCount(1);
});

it('reads retained releases from a private SSH working directory', function (bool $selected, string $webRoot, string $suffix, string $target): void {
    if (LinuxHost::delegate($this)) {
        return;
    }

    [$deployment, $ssh, $instance] = orb219_remote_deployment([
        new CommandResult(0, "SELECTED\t\nRELEASE\tinitial\t".str_repeat('a', 40)."\n", '', 1, false),
    ]);
    $instance->update(['root' => $webRoot, 'source_is_laravel' => true]);
    $deployment->releases($instance);
    $sandbox = sys_get_temp_dir().'/orbit-private-release-list-'.Str::uuid();
    mkdir($sandbox, 0o755);
    chmod($sandbox, 0o755);
    mkdir("$sandbox/ssh-home", 0o700);
    $home = "$sandbox/home";
    $release = "$home/releases/initial";
    $repository = 'https://example.test/deployment.git';

    try {
        mkdir("$release/public", 0o700, true);
        if ($suffix !== '') {
            mkdir("$release/$webRoot", 0o700, true);
        }
        mkdir("$sandbox/state", 0o700);
        file_put_contents("$home/.env", "APP_ENV=production\n");
        file_put_contents("$release/public/index.php", "<?php\n");
        file_put_contents("$release/$webRoot/index.php", "<?php\n");
        symlink($target, "$release$suffix/.env");
        if ($selected) {
            symlink('releases/initial', "$home/current");
        }
        file_put_contents("$sandbox/state/release-layout", $repository."\0caddy\0".$home."\0initial\0");
        chmod("$sandbox/state/release-layout", 0o600);
        new Process(['sudo', 'chown', '--', 'root:root', "$sandbox/state/release-layout"])->mustRun();
        foreach ([
            ['git', 'init', '--quiet', $release],
            ['git', '-C', $release, 'config', 'user.email', 'orbit@example.test'],
            ['git', '-C', $release, 'config', 'user.name', 'Orbit Test'],
            ['git', '-C', $release, 'remote', 'add', 'origin', $repository],
            ['git', '-C', $release, 'add', 'public/index.php'],
            ['git', '-C', $release, 'commit', '--quiet', '-m', 'fixture'],
        ] as $arguments) {
            new Process($arguments)->mustRun();
        }
        $commit = trim(new Process(['git', '-C', $release, 'rev-parse', 'HEAD'])->mustRun()->getOutput());
        new Process(['sudo', 'chown', '-R', '--', 'caddy:caddy', $home])->mustRun();
        $script = str_replace(
            ['state_directory="/var/lib/orbit/app-instance-sources/$instance"', 'test "$home" = "/home/$user"'],
            ['state_directory="$6"', 'test -d "$home"'],
            $ssh->commands[0]->input ?? '',
        );

        $process = new Process(
            ['bash', '-seu', '--', $repository, 'caddy', $home, 'fixture-instance', $webRoot, "$sandbox/state"],
            "$sandbox/ssh-home",
            input: $script,
        );
        $process->mustRun();

        $selection = $selected ? 'initial' : '';
        expect($process->getOutput())->toBe("SELECTED\t$selection\nRELEASE\tinitial\t$commit\n");
        expect($process->getErrorOutput())->toBe('');
        expect(new Process(['sudo', '-n', '-u', 'caddy', 'test', '-x', "$sandbox/ssh-home"])->run())->toBe(1);
    } finally {
        new Process(['sudo', 'rm', '-r', '--', $sandbox])->mustRun();
    }
})->with([
    'root first clone' => [false, 'public', '', '../../.env'],
    'root selected' => [true, 'public', '', '../../.env'],
    'nested first clone' => [false, 'server/web/public', '/server/web', '../../../../.env'],
    'nested selected' => [true, 'server/web/public', '/server/web', '../../../../.env'],
]);

it('skips partial directories while executing the retained release listing', function (string $webRoot, string $suffix, string $target): void {
    [$deployment, $ssh, $instance] = orb219_remote_deployment([
        new CommandResult(0, "SELECTED\tvalid\nRELEASE\tvalid\t".str_repeat('a', 40)."\n", '', 1, false),
    ]);
    $instance->update(['root' => $webRoot]);
    $deployment->releases($instance);

    $filesystem = new Filesystem;
    $sandbox = sys_get_temp_dir().'/orbit-release-list-'.bin2hex(random_bytes(6));
    $home = $sandbox.'/home';
    $release = $home.'/releases/valid';
    $partial = $home.'/releases/partial';
    $repository = 'https://example.test/deployment.git';
    $user = 'orbit-fixture';

    try {
        $filesystem->ensureDirectoryExists($release.'/'.$webRoot);
        $filesystem->ensureDirectoryExists($partial.'/.git');
        $filesystem->ensureDirectoryExists($home.'/state');
        $filesystem->ensureDirectoryExists($sandbox.'/bin');
        file_put_contents($home.'/.env', "APP_ENV=production\n");
        file_put_contents($release.'/'.$webRoot.'/index.php', "<?php\n");
        symlink($target, $release.$suffix.'/.env');
        symlink('releases/valid', $home.'/current');
        file_put_contents(
            $home.'/state/release-layout',
            $repository."\0".$user."\0".$home."\0initial\0",
        );
        chmod($home.'/state/release-layout', 0600);
        file_put_contents(
            $sandbox.'/bin/sudo',
            <<<'BASH'
                #!/usr/bin/env bash
                if [ "${1:-}" = -u ]; then shift 2; fi
                if [ "${1:-}" = -H ]; then shift; fi
                exec "$@"
                BASH,
        );
        chmod($sandbox.'/bin/sudo', 0755);

        foreach ([
            ['git', 'init', '--quiet', $release],
            ['git', '-C', $release, 'config', 'user.email', 'orbit@example.test'],
            ['git', '-C', $release, 'config', 'user.name', 'Orbit Test'],
            ['git', '-C', $release, 'remote', 'add', 'origin', $repository],
            ['git', '-C', $release, 'add', $webRoot.'/index.php'],
            ['git', '-C', $release, 'commit', '--quiet', '-m', 'fixture'],
        ] as $arguments) {
            new Process($arguments)->mustRun();
        }
        $commit = trim((new Process(['git', '-C', $release, 'rev-parse', 'HEAD']))->mustRun()->getOutput());

        $script = str_replace(
            [
                'state_directory="/var/lib/orbit/app-instance-sources/$instance"',
                'test "$home" = "/home/$user"',
                'root:root:600',
                '! -user "$user"',
                '! -group "$user"',
            ],
            [
                'state_directory="$home/state"',
                'test -d "$home"',
                '"$(id -un):$(id -gn):600"',
                '! -uid "$(id -u)"',
                '! -gid "$(id -g)"',
            ],
            $ssh->commands[0]->input ?? '',
        );
        $process = new Process(
            ['bash', '-seu', '--', $repository, $user, $home, 'fixture-instance', $webRoot],
            env: ['PATH' => $sandbox.'/bin:'.getenv('PATH')],
        );
        $process->setInput($script);
        $process->mustRun();

        expect($process->getOutput())
            ->toBe("SELECTED\tvalid\nRELEASE\tvalid\t{$commit}\n")
            ->not->toContain('partial');
    } finally {
        $filesystem->deleteDirectory($sandbox);
    }
})->with(['root public' => ['public', '', '../../.env'], 'nested Laravel' => ['server/web/public', '/server/web', '../../../../.env']]);

it('prunes a production home to the selected, the previous, and the newest other release', function (?string $previous, ?string $busy, array $kept, array $output): void {
    if (LinuxHost::delegate($this)) {
        return;
    }

    [$deployment, $ssh, $instance] = orb219_remote_deployment([new CommandResult(0, '', '', 1, false)]);
    $selected = new DeploymentRelease('20260104000000-d', '/home/orbit-app-1/releases/20260104000000-d', str_repeat('a', 40));
    $deployment->prune($instance, $selected, $previous === null ? null : new DeploymentRelease($previous, '/home/orbit-app-1/releases/'.$previous, str_repeat('b', 40)));

    $filesystem = new Filesystem;
    $sandbox = sys_get_temp_dir().'/orbit-release-prune-'.bin2hex(random_bytes(6));
    $home = $sandbox.'/home';
    $repository = 'https://example.test/deployment.git';

    try {
        foreach (['initial', '20260101000000-a', '20260102000000-b', '20260103000000-c', '20260104000000-d'] as $name) {
            $release = $home.'/releases/'.$name;
            $filesystem->ensureDirectoryExists($release.'/public');
            file_put_contents($release.'/public/index.php', "<?php\n");
            symlink('../../.env', $release.'/.env');
            foreach ([
                ['git', 'init', '--quiet', $release],
                ['git', '-C', $release, 'config', 'user.email', 'orbit@example.test'],
                ['git', '-C', $release, 'config', 'user.name', 'Orbit Test'],
                ['git', '-C', $release, 'remote', 'add', 'origin', $repository],
                ['git', '-C', $release, 'add', 'public/index.php'],
                ['git', '-C', $release, 'commit', '--quiet', '-m', $name],
            ] as $arguments) {
                new Process($arguments)->mustRun();
            }
        }
        $filesystem->ensureDirectoryExists($home.'/storage/app/public');
        file_put_contents($home.'/storage/app/public/upload.txt', 'retained upload');
        foreach (glob($home.'/releases/*') ?: [] as $release) {
            symlink('../../storage', $release.'/storage');
            symlink('../storage/app/public', $release.'/public/storage');
        }
        // A read-only folder must not stop a removal.
        mkdir($home.'/releases/20260101000000-a/cache/locked', 0o755, true);
        file_put_contents($home.'/releases/20260101000000-a/cache/locked/entry', 'cached');
        chmod($home.'/releases/20260101000000-a/cache/locked', 0o555);
        $filesystem->ensureDirectoryExists($home.'/releases/partial/.git');
        $filesystem->ensureDirectoryExists($home.'/releases/.pruned-interrupted/vendor');
        $filesystem->ensureDirectoryExists($home.'/state');
        $filesystem->ensureDirectoryExists($sandbox.'/bin');
        file_put_contents($home.'/.env', "APP_ENV=production\n");
        symlink('releases/20260104000000-d', $home.'/current');
        file_put_contents($home.'/state/release-layout', $repository."\0orbit-fixture\0".$home."\0initial\0");
        chmod($home.'/state/release-layout', 0600);
        file_put_contents($sandbox.'/bin/sudo', <<<'BASH'
            #!/usr/bin/env bash
            if [ "${1:-}" = -u ]; then shift 2; fi
            if [ "${1:-}" = -H ]; then shift; fi
            exec "$@"
            BASH);
        chmod($sandbox.'/bin/sudo', 0755);
        $script = str_replace(
            ['state_directory="/var/lib/orbit/app-instance-sources/$instance"', 'test "$home" = "/home/$user"', 'root:root:600', '! -user "$user"', '! -group "$user"'],
            ['state_directory="$home/state"', 'test -d "$home"', '"$(id -un):$(id -gn):600"', '! -uid "$(id -u)"', '! -gid "$(id -g)"'],
            $ssh->commands[0]->input ?? '',
        );
        // The test's own child processes stand in for the production user's: the fake sudo cannot read other processes.
        expect($script)->toContain('pgrep -u "$user"');
        $script = str_replace('pgrep -u "$user"', 'pgrep -P "$PPID"', $script);
        $arguments = $ssh->commands[0]->arguments;
        expect(array_slice($arguments, 8))->toBe(['3', '20260104000000-d', $previous ?? '']);
        array_splice($arguments, 3, 3, [$repository, 'orbit-fixture', $home]);
        // A running Process, such as a queue worker that a deployment did not restart, works in an old release.
        $worker = new Process(['sleep', '60'], $home.'/releases/'.($busy ?? 'initial').'/public');
        if ($busy !== null) {
            $worker->start();
            for ($attempt = 0; $attempt < 100 && @readlink('/proc/'.$worker->getPid().'/cwd') !== $home.'/releases/'.$busy.'/public'; $attempt++) {
                usleep(10_000);
            }
        }

        $process = new Process($arguments, env: ['PATH' => $sandbox.'/bin:'.getenv('PATH')]);
        $process->setInput($script);
        $process->mustRun();
        $worker->stop(0);

        expect($process->getOutput())->toBe(implode('', array_map(static fn (string $line): string => $line."\n", $output)))
            ->and($process->getErrorOutput())->toBe('')
            ->and(array_map(basename(...), glob($home.'/releases/{,.}[!.]*', GLOB_BRACE) ?: []))->toEqualCanonicalizing([...$kept, 'partial'])
            ->and(readlink($home.'/current'))->toBe('releases/20260104000000-d')
            ->and(file_get_contents($home.'/storage/app/public/upload.txt'))->toBe('retained upload');
    } finally {
        new Process(['chmod', '-R', 'u+w', '--', $sandbox])->run();
        $filesystem->deleteDirectory($sandbox);
    }
})->with([
    'after a rollback' => ['20260102000000-b', null, ['20260104000000-d', '20260102000000-b', '20260103000000-c'], ["PRUNED\t20260101000000-a", "PRUNED\tinitial"]],
    'after a deployment' => ['20260103000000-c', null, ['20260104000000-d', '20260103000000-c', '20260102000000-b'], ["PRUNED\t20260101000000-a", "PRUNED\tinitial"]],
    'after the first deployment' => [null, null, ['20260104000000-d', '20260103000000-c', '20260102000000-b'], ["PRUNED\t20260101000000-a", "PRUNED\tinitial"]],
    'with a process in an old release' => ['20260103000000-c', 'initial', ['20260104000000-d', '20260103000000-c', '20260102000000-b', 'initial'], ["PRUNED\t20260101000000-a", "IN_USE\tinitial"]],
]);

it('removes nothing when it cannot read where a running process works', function (): void {
    if (LinuxHost::delegate($this)) {
        return;
    }

    [$deployment, $ssh, $instance] = orb219_remote_deployment([new CommandResult(0, '', '', 1, false)]);
    $deployment->prune($instance, new DeploymentRelease('initial', '/home/orbit-app-1/releases/initial', str_repeat('a', 40)), null);
    $filesystem = new Filesystem;
    $sandbox = sys_get_temp_dir().'/orbit-release-prune-'.bin2hex(random_bytes(6));
    $home = $sandbox.'/home';
    $repository = 'https://example.test/deployment.git';

    try {
        foreach (['initial', '20260101000000-a', '20260102000000-b', '20260103000000-c'] as $name) {
            $release = $home.'/releases/'.$name;
            $filesystem->ensureDirectoryExists($release.'/public');
            file_put_contents($release.'/public/index.php', "<?php\n");
            symlink('../../.env', $release.'/.env');
            foreach ([['git', 'init', '--quiet', $release], ['git', '-C', $release, 'remote', 'add', 'origin', $repository], ['git', '-C', $release, 'add', 'public/index.php'], ['git', '-C', $release, '-c', 'user.email=orbit@example.test', '-c', 'user.name=Orbit Test', 'commit', '--quiet', '-m', $name]] as $arguments) {
                new Process($arguments)->mustRun();
            }
        }
        $filesystem->ensureDirectoryExists($home.'/state');
        $filesystem->ensureDirectoryExists($sandbox.'/bin');
        file_put_contents($home.'/.env', "APP_ENV=production\n");
        symlink('releases/initial', $home.'/current');
        file_put_contents($home.'/state/release-layout', $repository."\0orbit-fixture\0".$home."\0initial\0");
        chmod($home.'/state/release-layout', 0600);
        file_put_contents($sandbox.'/bin/sudo', "#!/usr/bin/env bash\nif [ \"\${1:-}\" = -u ]; then shift 2; fi\nif [ \"\${1:-}\" = -H ]; then shift; fi\nexec \"\$@\"\n");
        chmod($sandbox.'/bin/sudo', 0755);
        $script = str_replace(
            ['state_directory="/var/lib/orbit/app-instance-sources/$instance"', 'test "$home" = "/home/$user"', 'root:root:600', '! -user "$user"', '! -group "$user"', 'pgrep -u "$user"', 'sudo readlink --'],
            ['state_directory="$home/state"', 'test -d "$home"', '"$(id -un):$(id -gn):600"', '! -uid "$(id -u)"', '! -gid "$(id -g)"', 'pgrep -P "$PPID"', 'false --'],
            $ssh->commands[0]->input ?? '',
        );
        $arguments = $ssh->commands[0]->arguments;
        array_splice($arguments, 3, 3, [$repository, 'orbit-fixture', $home]);

        $process = new Process($arguments, env: ['PATH' => $sandbox.'/bin:'.getenv('PATH')]);
        $process->setInput($script);

        expect($process->run())->not->toBe(0)
            ->and($process->getOutput())->toBe('')
            ->and(array_map(basename(...), glob($home.'/releases/*') ?: []))->toEqualCanonicalizing(['initial', '20260101000000-a', '20260102000000-b', '20260103000000-c']);
    } finally {
        $filesystem->deleteDirectory($sandbox);
    }
});

it('names the removed and in-use releases also when the prune wrote a message', function (): void {
    [$deployment, , $instance] = orb219_remote_deployment([
        new CommandResult(0, "PRUNED\t20260101000000-a\nIN_USE\tinitial\n", "find: 'storage/framework/sessions/x': No such file or directory\n", 1, false),
    ]);

    expect($deployment->prune($instance, new DeploymentRelease('20260104000000-d', '/home/orbit-app-1/releases/20260104000000-d', str_repeat('a', 40)), null))
        ->toBe(['removed' => ['20260101000000-a'], 'in_use' => ['initial']]);
});

it('rejects traversal before asking the remote host to inspect a release', function (): void {
    [$deployment, $ssh, $instance] = orb219_remote_deployment([]);

    try {
        $deployment->retained($instance, '../foreign');
        $this->fail('The unsafe retained release was accepted.');
    } catch (ResourceOperationException $exception) {
        expect($exception->errorCode)->toBe('rollback.release_invalid');
    }

    expect($ssh->commands)->toBe([]);
});

/**
 * @param  list<CommandResult>  $results
 * @return array{RemoteProductionDeployment, AppDevFakeSshExecutor, Instance}
 */
function orb219_remote_deployment(array $results): array
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
        'name' => 'deployment-node',
        'status' => 'active',
        'platform' => 'linux',
        'public_ssh_host' => '192.0.2.219',
        'wireguard_ip' => '10.44.0.219',
        'user' => 'orbit',
    ]);
    $node->roles()->create(['role' => 'app-prod', 'status' => 'active']);
    $project = Project::query()->create([
        'name' => 'Deployment',
        'slug' => 'deployment',
        'repository_url' => 'https://example.test/deployment.git',
        'default_branch' => 'main',
        'root' => 'public',
    ]);
    $instance = Instance::query()->create([
        'project_id' => $project->id,
        'node_id' => $node->id,
        'name' => 'production',
        'environment' => 'production',
        'source_layout' => 'release',
        'checkout_path' => '/home/orbit-app-1/releases/initial',
        'production_user' => 'orbit-app-1',
        'production_home' => '/home/orbit-app-1',
        'root' => 'public',
        'branch' => 'main',
        'provisioning_step' => 'active',
        'status' => 'active',
    ]);

    return [
        new RemoteProductionDeployment($executor, app(RepositoryReadAccess::class), static fn (): string => '20260911-a1'),
        $ssh,
        $instance,
    ];
}
