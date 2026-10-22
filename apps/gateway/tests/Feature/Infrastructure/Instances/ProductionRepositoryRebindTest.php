<?php

declare(strict_types=1);

use App\Actions\Tools\PrepareVpEnvironmentAction;
use App\Domain\AppDev\RuntimeConvergenceException;
use App\Domain\GitHub\RepositoryReadAccess;
use App\Domain\Instances\Deployment\DeploymentRelease;
use App\Domain\Instances\ProductionRepositoryRecord;
use App\Domain\Shared\ResourceOperationException;
use App\Infrastructure\AppProd\ProductionSshExecutor;
use App\Infrastructure\Instances\RemoteProductionDeployment;
use App\Infrastructure\Instances\RemoteProductionRepositoryBinding;
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
use Illuminate\Filesystem\Filesystem;
use Symfony\Component\Process\Process;
use Tests\Support\AppDevFakeSshExecutor;
use Tests\Support\BufferedBashProgram;
use Tests\Support\LinuxHost;
use Tests\Support\ScriptedBashCommand;
use Tests\Support\StandardLibraryPython;

const REBIND_SSH_URL = 'git@github.com:acme/site.git';
const REBIND_HTTPS_URL = 'https://github.com/acme/site.git';

beforeAll(function (): void {
    rebind_repository_seed();
});

it('isolates writable rebind repositories and environment links copied from the committed seed', function (): void {
    $first = rebind_sandbox();
    $second = rebind_sandbox();
    $third = null;
    $git = static fn (string $root, array $arguments): string => trim(new Process(['git', '-C', $root.'/home/releases/initial', ...$arguments])->mustRun()->getOutput());

    try {
        $initial = $git($second, ['rev-parse', 'HEAD']);
        expect($git($first, ['rev-parse', 'HEAD']))->toBe($initial)
            ->and($git($first, ['rev-parse', '--absolute-git-dir']))->toBe($first.'/home/releases/initial/.git')
            ->and($git($second, ['rev-parse', '--absolute-git-dir']))->toBe($second.'/home/releases/initial/.git');
        file_put_contents($first.'/home/releases/initial/public/index.php', 'private change');
        file_put_contents($first.'/home/releases/initial/.env', 'private environment');
        $git($first, ['add', 'public/index.php']);
        $git($first, ['-c', 'user.name=Orbit Test', '-c', 'user.email=orbit@example.test', 'commit', '--quiet', '-m', 'private change']);
        $git($first, ['remote', 'set-url', 'origin', REBIND_HTTPS_URL]);
        expect($git($first, ['rev-parse', 'HEAD']))->not->toBe($initial)
            ->and($git($first, ['remote', 'get-url', 'origin']))->toBe(REBIND_HTTPS_URL)
            ->and($git($second, ['rev-parse', 'HEAD']))->toBe($initial)
            ->and($git($second, ['remote', 'get-url', 'origin']))->toBe(REBIND_SSH_URL)
            ->and(file_get_contents($second.'/home/releases/initial/public/index.php'))->toBe("<?php\n")
            ->and(file_get_contents($second.'/home/releases/initial/.env'))->toBe("APP_ENV=production\n");
        new Filesystem()->deleteDirectory($first);
        $third = rebind_sandbox();
        expect($git($third, ['rev-parse', 'HEAD']))->toBe($initial)
            ->and($git($third, ['remote', 'get-url', 'origin']))->toBe(REBIND_SSH_URL)
            ->and(file_get_contents($third.'/home/releases/initial/public/index.php'))->toBe("<?php\n")
            ->and(file_get_contents($third.'/home/releases/initial/.env'))->toBe("APP_ENV=production\n");
    } finally {
        foreach ([$first, $second, $third] as $directory) {
            if ($directory !== null) {
                new Filesystem()->deleteDirectory($directory);
            }
        }
    }
});

describe('ProductionRepositoryRecord', function (): void {
    it('treats SSH and HTTPS URLs of one repository as the same repository', function (): void {
        $record = new ProductionRepositoryRecord(REBIND_SSH_URL, REBIND_SSH_URL, ['a' => REBIND_SSH_URL, 'b' => REBIND_HTTPS_URL]);

        expect($record->sameRepositoryAs(REBIND_HTTPS_URL))->toBeTrue()
            ->and($record->sameRepositoryAs('https://github.com/acme/site'))->toBeTrue()
            ->and($record->sameRepositoryAs('https://github.com/acme/other.git'))->toBeFalse()
            ->and($record->boundTo(REBIND_HTTPS_URL))->toBeFalse()
            ->and($record->withRepository(REBIND_HTTPS_URL)->boundTo(REBIND_HTTPS_URL))->toBeTrue()
            ->and(new ProductionRepositoryRecord('not a url', null, [])->sameRepositoryAs(REBIND_HTTPS_URL))->toBeFalse();
    });

    it('round-trips through stored inventory', function (): void {
        $record = new ProductionRepositoryRecord(REBIND_SSH_URL, null, ['initial' => REBIND_SSH_URL]);

        expect(ProductionRepositoryRecord::fromArray($record->toArray()))->toEqual($record);
    });
});

describe('RemoteProductionRepositoryBinding', function (): void {
    it('re-binds a production home that records the SSH URL after the Project moved to HTTPS', function (): void {
        $sandbox = rebind_sandbox();
        $executor = rebind_local_executor($sandbox);
        [$instance, $deployment, $binding] = rebind_instance($executor, REBIND_HTTPS_URL, "{$sandbox}/home");

        try {
            // The repro: the Project URL changed, so selection refuses the home.
            expect(fn () => $deployment->selected($instance))
                ->toThrow(function (ResourceOperationException $exception): void {
                    expect($exception->errorCode)->toBe('deployment.repository_rebind_required')
                        ->and($exception->getMessage())->toContain('orbit project:update');
                });

            $record = rebind_initial_record();
            $binding->rebind($instance, $record, $record->withRepository(REBIND_HTTPS_URL));

            expect($deployment->selected($instance)?->name)->toBe('initial')
                ->and($deployment->retained($instance, '20261001000000-a')->name)->toBe('20261001000000-a');
        } finally {
            new Filesystem()->deleteDirectory($sandbox);
        }
    });

    it('inspects and re-binds every recorded URL while preserving protected marker bytes', function (): void {
        $sandbox = rebind_sandbox();
        [$instance, , $binding] = rebind_instance(rebind_local_executor($sandbox), REBIND_HTTPS_URL, "{$sandbox}/home");

        try {
            $record = $binding->inspect($instance);
            expect($record)->toEqual(rebind_initial_record());

            $binding->rebind($instance, $record, $record->withRepository(REBIND_HTTPS_URL));

            expect($binding->inspect($instance))->toEqual($record->withRepository(REBIND_HTTPS_URL))
                ->and(decoct(fileperms("{$sandbox}/state/release-layout") & 0o777))->toBe('600')
                ->and(file_get_contents("{$sandbox}/state/release-layout"))
                ->toBe(REBIND_HTTPS_URL."\0rebind\0{$sandbox}/home\0initial\0")
                ->and(file_get_contents("{$sandbox}/state/initial-clone"))
                ->toBe(REBIND_HTTPS_URL."\0rebind\0{$sandbox}/home\0");
        } finally {
            new Filesystem()->deleteDirectory($sandbox);
        }
    });

    it('accepts repeated convergence from the same old production binding', function (): void {
        $sandbox = rebind_sandbox();
        [$instance, , $binding] = rebind_instance(rebind_local_executor($sandbox), REBIND_HTTPS_URL, "{$sandbox}/home");
        $record = rebind_initial_record();
        $target = $record->withRepository(REBIND_HTTPS_URL);

        try {
            $binding->rebind($instance, $record, $target);
            $binding->rebind($instance, $record, $target);

            expect($binding->inspect($instance))->toEqual($target);
        } finally {
            new Filesystem()->deleteDirectory($sandbox);
        }
    });

    it('rolls back every recorded production URL after a real re-bind', function (): void {
        $sandbox = rebind_sandbox();
        [$instance, , $binding] = rebind_instance(rebind_local_executor($sandbox), REBIND_HTTPS_URL, "{$sandbox}/home");
        $record = rebind_initial_record();
        $target = $record->withRepository(REBIND_HTTPS_URL);

        try {
            $binding->rebind($instance, $record, $target);
            expect($binding->inspect($instance))->toEqual($target);

            $binding->rebind($instance, $target, $record);

            expect($binding->inspect($instance))->toEqual($record);
        } finally {
            new Filesystem()->deleteDirectory($sandbox);
        }
    });

    it('inventories and re-binds an in-use release beyond the pruning retention limit', function (): void {
        if (LinuxHost::delegate($this)) {
            return;
        }

        $sandbox = rebind_sandbox(['initial', '20261001000000-a', '20261002000000-b', '20261003000000-c']);
        [$instance, $deployment, $binding] = rebind_instance(rebind_local_executor($sandbox), REBIND_SSH_URL, "{$sandbox}/home");
        $selected = new DeploymentRelease('initial', "{$sandbox}/home/releases/initial", str_repeat('a', 40));
        $worker = new Process(['sleep', '60'], "{$sandbox}/home/releases/20261001000000-a/public");

        try {
            $worker->start();
            for ($attempt = 0; $attempt < 100 && @readlink('/proc/'.$worker->getPid().'/cwd') !== "{$sandbox}/home/releases/20261001000000-a/public"; $attempt++) {
                usleep(10_000);
            }

            expect($deployment->prune($instance, $selected, null))
                ->toBe(['removed' => [], 'in_use' => ['20261001000000-a']]);

            $record = $binding->inspect($instance);

            expect(array_keys($record->releases))->toBe(['20261001000000-a', '20261002000000-b', '20261003000000-c', 'initial']);

            $binding->rebind($instance, $record, $record->withRepository(REBIND_HTTPS_URL));

            expect($binding->inspect($instance))->toEqual($record->withRepository(REBIND_HTTPS_URL))
                ->and($worker->isRunning())->toBeTrue();
        } finally {
            $worker->stop(0);
            new Filesystem()->deleteDirectory($sandbox);
        }
    });

    it('re-binds the releases that pruning keeps, and pruning then accepts them', function (): void {
        if (LinuxHost::delegate($this)) {
            return;
        }

        $sandbox = rebind_sandbox(['initial', '20261001000000-a', '20261002000000-b', '20261003000000-c']);
        [$instance, $deployment, $binding] = rebind_instance(rebind_local_executor($sandbox), REBIND_SSH_URL, "{$sandbox}/home");
        $selected = new DeploymentRelease('initial', "{$sandbox}/home/releases/initial", str_repeat('a', 40));
        $worker = new Process(['sleep', '60'], "{$sandbox}/home/releases/20261001000000-a/public");
        $record = new ProductionRepositoryRecord(
            REBIND_SSH_URL,
            REBIND_SSH_URL,
            ['20261001000000-a' => REBIND_SSH_URL, '20261002000000-b' => REBIND_SSH_URL, '20261003000000-c' => REBIND_SSH_URL, 'initial' => REBIND_SSH_URL],
        );

        try {
            $worker->start();
            for ($attempt = 0; $attempt < 100 && @readlink('/proc/'.$worker->getPid().'/cwd') !== "{$sandbox}/home/releases/20261001000000-a/public"; $attempt++) {
                usleep(10_000);
            }

            $binding->rebind($instance, $record, $record->withRepository(REBIND_HTTPS_URL));
            $instance->project->update(['repository_url' => REBIND_HTTPS_URL]);
            $instance->refresh();

            expect($deployment->prune($instance, $selected, null))
                ->toBe(['removed' => [], 'in_use' => ['20261001000000-a']]);

            $worker->stop(0);

            expect($deployment->prune($instance, $selected, null))
                ->toBe(['removed' => ['20261001000000-a'], 'in_use' => []])
                ->and($binding->inspect($instance))->toEqual(new ProductionRepositoryRecord(
                    REBIND_HTTPS_URL,
                    REBIND_HTTPS_URL,
                    ['20261002000000-b' => REBIND_HTTPS_URL, '20261003000000-c' => REBIND_HTTPS_URL, 'initial' => REBIND_HTTPS_URL],
                ));
        } finally {
            $worker->stop(0);
            new Filesystem()->deleteDirectory($sandbox);
        }
    });

    it('refuses to rewrite a value that is neither the expected nor the target URL', function (): void {
        $sandbox = rebind_sandbox();
        $executor = rebind_local_executor($sandbox);
        [$instance, , $binding] = rebind_instance($executor, REBIND_HTTPS_URL, "{$sandbox}/home");

        try {
            $record = $binding->inspect($instance);
            $stale = new ProductionRepositoryRecord('git@github.com:acme/other.git', null, []);

            expect(fn () => $binding->rebind($instance, $stale, $stale->withRepository(REBIND_HTTPS_URL)))
                ->toThrow(function (ResourceOperationException $exception): void {
                    expect($exception->errorCode)->toBe('project.production_rebind_failed');
                });

            expect($binding->inspect($instance))->toEqual($record);
        } finally {
            new Filesystem()->deleteDirectory($sandbox);
        }
    });

    it('names a different repository instead of a re-bind', function (): void {
        $ssh = new AppDevFakeSshExecutor([
            new CommandResult(1, '', '', 1, false),
            new CommandResult(0, "MARKER\trelease-layout\t".base64_encode("git@github.com:acme/other.git\0orbit-app-1\0/home/orbit-app-1\0initial\0")."\n", '', 1, false),
        ]);
        [$instance, $deployment] = rebind_instance(rebind_executor($ssh), REBIND_HTTPS_URL, '/home/orbit-app-1', 'orbit-app-1');

        expect(fn () => $deployment->selected($instance))
            ->toThrow(function (ResourceOperationException $exception): void {
                expect($exception->errorCode)->toBe('deployment.repository_mismatch');
            });
    });

    it('keeps the original selection error when the home records the Project URL', function (): void {
        $ssh = new AppDevFakeSshExecutor([
            new CommandResult(1, '', '', 1, false),
            new CommandResult(0, "MARKER\trelease-layout\t".base64_encode(REBIND_HTTPS_URL."\0orbit-app-1\0/home/orbit-app-1\0initial\0")."\n", '', 1, false),
        ]);
        [$instance, $deployment] = rebind_instance(rebind_executor($ssh), REBIND_HTTPS_URL, '/home/orbit-app-1', 'orbit-app-1');

        expect(fn () => $deployment->selected($instance))
            ->toThrow(function (RuntimeConvergenceException $exception): void {
                expect($exception->errorCode)->toBe('deployment.selection_invalid');
            });
    });
});

function rebind_initial_record(): ProductionRepositoryRecord
{
    return new ProductionRepositoryRecord(
        REBIND_SSH_URL,
        REBIND_SSH_URL,
        ['20261001000000-a' => REBIND_SSH_URL, 'initial' => REBIND_SSH_URL],
    );
}

/** Read-only setup; TestTemporaryDirectory removes the process-owned seed at shutdown. */
function rebind_repository_seed(): string
{
    static $seed = null;
    if ($seed === null) {
        $seed = sys_get_temp_dir().'/orbit-rebind-seed-'.bin2hex(random_bytes(6));
        mkdir($seed.'/public', 0o700, true);
        file_put_contents($seed.'/public/index.php', "<?php\n");
        foreach ([
            ['git', 'init', '--quiet', $seed],
            ['git', '-C', $seed, 'remote', 'add', 'origin', REBIND_SSH_URL],
            ['git', '-C', $seed, 'add', 'public/index.php'],
            ['git', '-C', $seed, '-c', 'user.name=Orbit Test', '-c', 'user.email=orbit@example.test', 'commit', '--quiet', '-m', 'fixture'],
        ] as $arguments) {
            new Process($arguments)->mustRun();
        }
    }

    return $seed;
}

/** @param list<string> $names */
function rebind_sandbox(array $names = ['initial', '20261001000000-a']): string
{
    $sandbox = sys_get_temp_dir().'/orbit-production-rebind-'.bin2hex(random_bytes(6));
    $home = "{$sandbox}/home";
    mkdir("{$sandbox}/state", 0o700, true);
    mkdir("{$sandbox}/bin", 0o755, true);
    file_put_contents(
        "{$sandbox}/bin/sudo",
        <<<'BASH'
            #!/usr/bin/env bash
            if [ "${1:-}" = -u ]; then shift 2; fi
            if [ "${1:-}" = -H ]; then shift; fi
            "$@"
            BASH,
    );
    chmod("{$sandbox}/bin/sudo", 0o755);
    mkdir("{$home}/releases", 0o700, true);
    file_put_contents("{$home}/.env", "APP_ENV=production\n");

    foreach ($names as $name) {
        $release = "{$home}/releases/{$name}";
        mkdir($release, 0o700, true);
        new Process(['cp', '-a', '--', rebind_repository_seed().'/.', $release])->mustRun();
        symlink('../../.env', "{$release}/.env");
    }

    // A partial release has no origin and stays out of the record.
    mkdir("{$home}/releases/partial/.git", 0o700, true);
    symlink('releases/initial', "{$home}/current");

    foreach ([
        'release-layout' => REBIND_SSH_URL."\0rebind\0{$home}\0initial\0",
        'initial-clone' => REBIND_SSH_URL."\0rebind\0{$home}\0",
    ] as $marker => $content) {
        file_put_contents("{$sandbox}/state/{$marker}", $content);
        chmod("{$sandbox}/state/{$marker}", 0o600);
    }

    return $sandbox;
}

/**
 * Runs the real remote programs locally, as the test user, against the sandbox.
 */
function rebind_local_executor(string $sandbox): ProductionSshExecutor
{
    $owner = posix_getpwuid(posix_geteuid()) ?: throw new RuntimeException('The fixture account must exist.');
    $group = posix_getgrgid(posix_getegid()) ?: throw new RuntimeException('The fixture group must exist.');

    return rebind_executor(new readonly class($sandbox, $owner['name'].':'.$group['name'], posix_geteuid(), posix_getegid()) implements SshExecutor
    {
        public function __construct(
            private string $sandbox,
            private string $account,
            private int $uid,
            private int $gid,
        ) {}

        public function execute(SshConnection $connection, RemoteCommand $command): CommandResult
        {
            $input = $command->protectedInput?->stream() !== null
                ? stream_get_contents($command->protectedInput->stream())
                : (string) $command->input;
            $input = str_replace(
                [
                    'state_directory="/var/lib/orbit/app-instance-sources/$instance"',
                    'test "$home" = "/home/$user"',
                    'root:root:700',
                    'root:root:600',
                    'sudo chown root:root -- "$temporary"',
                    '! -user "$user"',
                    '! -group "$user"',
                    // The test's own child processes stand in for the production user's.
                    'pgrep -u "$user"',
                ],
                [
                    'state_directory="'.$this->sandbox.'/state"',
                    'test -d "$home"',
                    escapeshellarg($this->account.':700'),
                    escapeshellarg($this->account.':600'),
                    ':',
                    '! -uid '.$this->uid,
                    '! -gid '.$this->gid,
                    'pgrep -P "$PPID"',
                ],
                $input,
            );
            $process = new Process(
                $command->arguments,
                env: ['PATH' => StandardLibraryPython::path($this->sandbox.'/bin:'.getenv('PATH'))],
                input: BufferedBashProgram::stdinPrelude($this->sandbox).ScriptedBashCommand::define('sudo', $this->sandbox.'/bin/sudo').$input,
            );
            $process->run();

            return new CommandResult((int) $process->getExitCode(), $process->getOutput(), $process->getErrorOutput(), 1, false);
        }
    });
}

function rebind_executor(SshExecutor $ssh): ProductionSshExecutor
{
    return new ProductionSshExecutor(
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
}

/** @return array{Instance, RemoteProductionDeployment, RemoteProductionRepositoryBinding} */
function rebind_instance(ProductionSshExecutor $executor, string $repository, string $home, string $user = 'rebind'): array
{
    $node = Node::query()->create([
        'name' => 'rebind-node',
        'status' => 'active',
        'platform' => 'linux',
        'public_ssh_host' => '192.0.2.231',
        'wireguard_ip' => '10.44.0.231',
        'user' => 'orbit',
    ]);
    $node->roles()->create(['role' => 'app-prod', 'status' => 'active']);
    $project = Project::query()->create([
        'name' => 'Rebind',
        'slug' => 'rebind',
        'repository_url' => $repository,
        'default_branch' => 'main',
        'root' => 'public',
    ]);
    $instance = Instance::query()->create([
        'project_id' => $project->id,
        'node_id' => $node->id,
        'name' => 'production',
        'environment' => 'production',
        'source_layout' => 'release',
        'checkout_path' => "{$home}/releases/initial",
        'production_user' => $user,
        'production_home' => $home,
        'root' => 'public',
        'branch' => 'main',
        'provisioning_step' => 'active',
        'status' => 'active',
    ]);
    $binding = new RemoteProductionRepositoryBinding($executor);

    return [
        $instance,
        new RemoteProductionDeployment($executor, app(RepositoryReadAccess::class), app(PrepareVpEnvironmentAction::class), null, $binding),
        $binding,
    ];
}
