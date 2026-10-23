<?php

declare(strict_types=1);

use App\Actions\Tools\PrepareVpEnvironmentAction;
use App\Domain\GitHub\RepositoryReadAccess;
use App\Domain\Instances\Deployment\DeploymentEvent;
use App\Domain\Instances\Deployment\DeploymentOutputStream;
use App\Domain\Instances\Deployment\DeploymentPhase;
use App\Domain\Instances\Deployment\DeploymentRelease;
use App\Domain\Instances\Deployment\DeploymentRequest;
use App\Domain\Instances\Deployment\DeploymentStep;
use App\Infrastructure\AppProd\ProductionSshExecutor;
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
use Symfony\Component\Process\Process;
use Tests\Support\AppDevFakeSshExecutor;
use Tests\Support\BufferedBashProgram;
use Tests\Support\FakeAgentCommandRunner;

pest()->group('privileged');

beforeAll(function (): void {
    foreach ([['public', null], ['server/web/public', null], ['public', 'storage'], ['apps/site/public', 'apps/site/storage']] as [$root, $storage]) {
        orb219_repository_seed($root, $storage);
    }
});

it('sources the fake sudo without executing its wrapper and preserves real commands and their status', function (): void {
    $sandbox = sys_get_temp_dir().'/orbit-deployment-sudo-'.bin2hex(random_bytes(6));
    $filesystem = new Filesystem;
    $filesystem->ensureDirectoryExists($sandbox.'/bin');
    try {
        orb219_fake_sudo($sandbox);
        chmod($sandbox.'/bin/sudo', 0o600);
        new Process(['cp', '-a', '--', orb219_repository_seed(), $sandbox.'/repository'])->mustRun();
        $head = new Process(['git', '-C', $sandbox.'/repository', 'rev-parse', 'HEAD'])->mustRun()->getOutput();
        $script = <<<'BASH'
            sudo -u fixture -H git -C "$1" rev-parse HEAD
            PATH="$1/bin" sudo -u fixture -H test -d "$1"
            if sudo -u fixture -H bash -c 'printf "<%s>\n" "$@"; exit 7' -- 'space arg' 'slash\arg' ''; then
                status=0
            else
                status=$?
            fi
            printf 'status=%s\nargument=%s\n' "$status" "$2"
            BASH;
        $process = new Process(
            ['bash', '-seu', '--', $sandbox.'/repository', 'unchanged'],
            env: ['PATH' => $sandbox.'/bin:'.getenv('PATH')],
            input: orb219_scripted_sudo_input($sandbox, $script),
        );

        expect($process->run())->toBe(0)
            ->and($process->getOutput())->toBe($head."<space arg>\n<slash\\arg>\n<>\nstatus=7\nargument=unchanged\n")
            ->and($process->getErrorOutput())->toBe('');
    } finally {
        $filesystem->deleteDirectory($sandbox);
    }
});

it('keeps deployment repository copies independent of their seed and each other', function (): void {
    $seed = orb219_repository_seed('public', 'storage');
    $sandbox = sys_get_temp_dir().'/orbit-deployment-seed-copy-'.bin2hex(random_bytes(6));
    mkdir($sandbox, 0o700);
    try {
        foreach (['first', 'second'] as $copy) {
            new Process(['cp', '-a', '--', $seed, "$sandbox/$copy"])->mustRun();
        }
        $initial = new Process(['git', '-C', $seed, 'rev-parse', 'HEAD'])->mustRun()->getOutput();
        file_put_contents("$sandbox/first/public/index.php", '<?php // private');
        file_put_contents("$sandbox/first/storage/app/public/seed.txt", 'private storage');
        new Process(['git', '-C', "$sandbox/first", 'remote', 'set-url', 'origin', '/private-origin'])->mustRun();
        new Process(['git', '-C', "$sandbox/first", 'add', '.'])->mustRun();
        new Process(['git', '-C', "$sandbox/first", '-c', 'user.name=Orbit Test', '-c', 'user.email=orbit@example.test', 'commit', '--quiet', '-m', 'private'])->mustRun();
        expect(new Process(['git', '-C', "$sandbox/first", 'rev-parse', 'HEAD'])->mustRun()->getOutput())->not->toBe($initial);
        foreach ([$seed, "$sandbox/second"] as $unchanged) {
            expect(file_get_contents("$unchanged/public/index.php"))->toBe("<?php\n")
                ->and(file_get_contents("$unchanged/storage/app/public/seed.txt"))->toBe('seed')
                ->and(new Process(['git', '-C', $unchanged, 'rev-parse', 'HEAD'])->mustRun()->getOutput())->toBe($initial)
                ->and(new Process(['git', '-C', $unchanged, 'remote', 'get-url', 'origin'])->mustRun()->getOutput())->toBe("https://example.test/deployment.git\n")
                ->and(new Process(['git', '-C', $unchanged, 'status', '--porcelain'])->mustRun()->getOutput())->toBe('');
        }
    } finally {
        new Filesystem()->deleteDirectory($sandbox);
    }
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

function orb219_fake_sudo(string $sandbox): void
{
    file_put_contents($sandbox.'/bin/sudo', <<<'BASH'
        #!/usr/bin/env bash
        if [ "${1:-}" = -u ]; then shift 2; fi
        if [ "${1:-}" = -H ]; then shift; fi
        "$@"
        BASH);
    chmod($sandbox.'/bin/sudo', 0755);
}

function orb219_scripted_sudo_input(string $sandbox, string $script): string
{
    return BufferedBashProgram::stdinPrelude($sandbox)
        .'sudo() { source '.escapeshellarg($sandbox.'/bin/sudo').' "$@"; }'."\n"
        .$script;
}

/** Committed setup is read-only; each case copies its own writable repository and index. */
function orb219_repository_seed(string $root = 'public', ?string $storage = null): string
{
    static $seeds = [];
    $key = json_encode([$root, $storage], JSON_THROW_ON_ERROR);
    if (! isset($seeds[$key])) {
        $seed = sys_get_temp_dir().'/orbit-deployment-seed-'.bin2hex(random_bytes(6));
        mkdir($seed.'/'.$root, 0o755, true);
        file_put_contents($seed.'/'.$root.'/index.php', "<?php\n");
        if ($storage !== null) {
            mkdir($seed.'/'.$storage.'/app/public', 0o755, true);
            file_put_contents($seed.'/'.$storage.'/app/public/seed.txt', 'seed');
        }
        foreach ([
            ['git', 'init', '--quiet', '--initial-branch=main', $seed],
            ['git', '-C', $seed, 'remote', 'add', 'origin', 'https://example.test/deployment.git'],
            ['git', '-C', $seed, 'add', '.'],
            ['git', '-C', $seed, '-c', 'user.name=Orbit Test', '-c', 'user.email=orbit@example.test', 'commit', '--quiet', '-m', 'fixture'],
        ] as $arguments) {
            new Process($arguments)->mustRun();
        }
        $seeds[$key] = $seed;
    }

    return $seeds[$key];
}

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
        new RemoteProductionDeployment($executor, new FakeAgentCommandRunner, app(RepositoryReadAccess::class), app(PrepareVpEnvironmentAction::class), static fn (): string => '20260911-a1'),
        $ssh,
        $instance,
    ];
}
