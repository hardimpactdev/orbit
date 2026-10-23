<?php

declare(strict_types=1);

use App\Domain\Nodes\ManagedUserAccount;
use App\Domain\Nodes\ManagedUserAccountResolver;
use App\Domain\Nodes\NodeRoleOperationException;
use App\Domain\Nodes\RoleName;
use App\Domain\Shared\LifecycleStatus;
use App\Domain\WebSocket\WebSocketServerCredentials;
use App\Infrastructure\Nodes\AgentCaddyPackage;
use App\Infrastructure\Nodes\Roles\RolePrerequisites;
use App\Infrastructure\Processes\CommandResult;
use App\Infrastructure\Ssh\HostKey;
use App\Infrastructure\Ssh\KnownHostsStore;
use App\Infrastructure\Ssh\RemoteCommand;
use App\Infrastructure\Ssh\SshConnection;
use App\Infrastructure\Ssh\SshExecutor;
use App\Infrastructure\Ssh\SshKeyProvider;
use App\Infrastructure\WebSocket\NativeWebSocketRuntimeLifecycle;
use App\Models\Node;
use App\Models\NodeRole;
use Illuminate\Support\Env;
use Illuminate\Support\Str;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Process\Process;
use Tests\Support\FakeAgentCommandRunner;
use Tests\TestCase;

uses(TestCase::class);

it('installs prerequisites, then clones, installs and restarts Reverb on every converge', function (): void {
    $commands = [];
    $agents = new FakeAgentCommandRunner;
    $lifecycle = websocket_runtime_lifecycle($commands, agents: $agents);
    $node = websocket_runtime_node();
    $assignment = new NodeRole(['role' => RoleName::WebSocket, 'status' => LifecycleStatus::Provisioning]);
    $credentials = new WebSocketServerCredentials('base64:'.base64_encode('k'), 'test-process', 'test-registry-token', 'test-notification-token');

    $lifecycle->converge($node, $assignment, $credentials);

    expect($agents->commands())->toBe(['caddy.install-package', 'roles.install-prerequisites'])
        ->and($agents->calls[1]['input']['packages'])->toContain('caddy', 'composer', 'git', 'php-curl', 'php-xml')
        ->and($commands)->toHaveCount(1);

    $runtime = $commands[0];

    expect($runtime->arguments)
        ->toContain('orbit-websocket')
        ->toContain('/opt/orbit/websocket')
        ->toContain('https://example.test/orbit-reverb.git')
        ->toContain('release-42', '8790')
        ->and($runtime->protectedInput)
        ->not
        ->toBeNull();

    $script = stream_get_contents($runtime->protectedInput->stream());

    expect(strpos($script, 'composer install'))->toBeLessThan(strpos($script, 'systemctl stop'))
        ->and(strpos($script, 'systemctl stop'))->toBeLessThan(strpos($script, 'mv -fT -- "$verify_directory/current"'));

    expect($script)
        ->toContain('git clone')
        ->toContain('git -C "$candidate" checkout')
        ->toContain('fetch --quiet origin "$ref"')
        ->toContain('composer install')
        ->toContain('systemd-analyze verify "$verify_directory/orbit-websocket.service"')
        ->toContain("systemctl daemon-reload\nsystemctl enable --quiet orbit-websocket\nsystemctl restart orbit-websocket")
        ->not->toContain('env_changed')
        ->not->toContain('cmp ')
        ->toContain('systemctl stop orbit-websocket')
        ->toContain('mktemp -d "$releases/release.XXXXXXXX"')
        ->toContain('flock -x 9')
        ->not
        ->toContain('test-registry-token')
        ->not
        ->toContain('[PROTECTED]');
});

it('installs the server commit pinned by the Gateway release despite a former environment override', function (): void {
    $environment = Env::getRepository();
    $previous = $environment->get('ORBIT_WEBSOCKET_REF');
    $environment->set('ORBIT_WEBSOCKET_REF', 'main');
    try {
        $release = require __DIR__.'/../../../../config/orbit.php';
        $ref = $release['websocket']['ref'];
        expect($ref)->toMatch('/\A[0-9a-f]{40}\z/');
        config()->set('orbit.websocket.ref', $ref);
        $commands = [];
        $lifecycle = websocket_runtime_lifecycle($commands, ref: '');
        $lifecycle->converge(websocket_runtime_node(), new NodeRole(['role' => RoleName::WebSocket]), new WebSocketServerCredentials('key', 'process', 'registry', 'notification'));
        expect($commands[0]->arguments)->toContain($ref)->not->toContain('main');
    } finally {
        if ($previous === null) {
            $environment->clear('ORBIT_WEBSOCKET_REF');
        } else {
            $environment->set('ORBIT_WEBSOCKET_REF', $previous);
        }
    }
});

it('stages the configured repository when an existing checkout origin is obsolete or absent', function (bool $missingOrigin): void {
    $directory = sys_get_temp_dir().'/orbit-websocket-'.Str::uuid();
    $repository = $directory.'/hardimpactdev/orbit-reverb';
    $checkout = $directory.'/existing checkout';
    $git = static function (array $arguments): string {
        $process = new Process(['git', ...$arguments]);
        $process->mustRun();

        return trim($process->getOutput());
    };
    mkdir($repository, 0o700, true);

    try {
        $git(['init', '--quiet', '--initial-branch=release-42', $repository]);
        file_put_contents($repository.'/VERSION', 'current release');
        $git(['-C', $repository, 'add', 'VERSION']);
        $git(['-C', $repository, '-c', 'user.name=Orbit Test', '-c', 'user.email=orbit@example.test', 'commit', '--quiet', '-m', 'Release']);
        $git(['clone', '--quiet', '--no-checkout', '--', $repository, $checkout]);
        if ($missingOrigin) {
            $git(['-C', $checkout, 'remote', 'remove', 'origin']);
        } else {
            $git(['-C', $checkout, 'remote', 'set-url', 'origin', $directory.'/removed-owner/orbit-reverb']);
        }
        $commands = [];
        $lifecycle = websocket_runtime_lifecycle($commands, repository: $repository, installPath: $checkout);
        $assignment = new NodeRole(['role' => RoleName::WebSocket, 'status' => LifecycleStatus::Provisioning]);
        $credentials = new WebSocketServerCredentials('base64:'.base64_encode('k'), 'process', 'registry-token', 'notify-token');
        $lifecycle->converge(websocket_runtime_node(), $assignment, $credentials);
        $runtime = $commands[0];
        $sudo = <<<'BASH'
            sudo() {
                [ "$1" = -u ] && [ "$3" = -H ] || return 1
                shift 3
                if [ "$1" = env ]; then
                    [ "$2" = COMPOSER_ALLOW_SUPERUSER=1 ] && [ "$3" = composer ] || exit 1
                    candidate="${@: -1}"
                    candidate="${candidate#--working-dir=}"
                    git -C "$candidate" remote get-url origin > "$TEST_STAGE_OBSERVED/origin"
                    git -C "$candidate" rev-parse HEAD > "$TEST_STAGE_OBSERVED/head"
                    cp "$candidate/VERSION" "$TEST_STAGE_OBSERVED/version"
                    exit 0
                fi
                [ "$1" = git ] || return 1
                command "$@"
            }
            BASH;
        $arguments = array_slice($runtime->arguments, 1);
        $arguments[3] = posix_getpwuid(posix_geteuid())['name'];
        $arguments[4] = posix_getgrgid(posix_getegid())['name'];
        $process = new Process($arguments, env: ['TEST_STAGE_OBSERVED' => $directory]);
        $process->setInput($sudo."\n".stream_get_contents($runtime->protectedInput->stream()));

        $process->mustRun();

        expect(trim(file_get_contents($directory.'/origin')))->toBe($repository);
        expect(file_get_contents($directory.'/version'))->toBe('current release');
        expect(trim(file_get_contents($directory.'/head')))->toBe($git(['-C', $repository, 'rev-parse', 'HEAD']));
        expect(is_link($checkout))->toBeFalse();
        expect($git(['-C', $checkout, 'remote']))->toBe($missingOrigin ? '' : 'origin');
        if (! $missingOrigin) {
            expect($git(['-C', $checkout, 'remote', 'get-url', 'origin']))->toBe($directory.'/removed-owner/orbit-reverb');
        }
    } finally {
        new Filesystem()->remove($directory);
    }
})->with([
    'obsolete origin' => false,
    'missing origin' => true,
]);

it('throws a NodeRoleOperationException when convergence fails', function (): void {
    $commands = [];
    $lifecycle = websocket_runtime_lifecycle($commands, failRuntime: true);
    $node = websocket_runtime_node();
    $assignment = new NodeRole(['role' => RoleName::WebSocket, 'status' => LifecycleStatus::Provisioning]);
    $credentials = new WebSocketServerCredentials('base64:'.base64_encode('k'), 'test-process', 'test-registry-token', 'test-notification-token');

    expect(fn () => $lifecycle->converge($node, $assignment, $credentials))
        ->toThrow(fn (NodeRoleOperationException $exception) => expect($exception->underlyingErrorCode)->toBe('websocket.runtime_convergence_failed'));
});

it('stops and disables the unit, purging the install path only when asked', function (): void {
    $commands = [];
    $lifecycle = websocket_runtime_lifecycle($commands);
    $node = websocket_runtime_node();
    $assignment = new NodeRole(['role' => RoleName::WebSocket, 'status' => LifecycleStatus::Provisioning]);

    $lifecycle->remove($node, $assignment, true);

    expect($commands)->toHaveCount(1);
    expect($commands[0]->input)
        ->toContain('systemctl disable --now')
        ->toContain("systemctl daemon-reload || true\nsystemctl reset-failed -- \"\$service.service\" >/dev/null 2>&1 || true\n")
        ->toContain('rm -rf -- "$install_path" "$install_path.releases"');
});

function websocket_runtime_node(): Node
{
    $node = new Node([
        'name' => 'websocket',
        'public_ssh_port' => 22,
        'user' => 'orbit',
        'wireguard_ip' => '10.44.0.9',
    ]);
    $node->exists = true;
    $node->id = 1;

    return $node;
}

function websocket_runtime_lifecycle(
    array &$commands,
    bool $failRuntime = false,
    string $repository = 'https://example.test/orbit-reverb.git',
    string $installPath = '/opt/orbit/websocket',
    string $ref = 'release-42',
    FakeAgentCommandRunner $agents = new FakeAgentCommandRunner,
): NativeWebSocketRuntimeLifecycle {
    $agents->answer('caddy.install-package', ['changed' => false])->answer('roles.install-prerequisites', []);

    return new NativeWebSocketRuntimeLifecycle(
        prerequisites: new RolePrerequisites($agents, new AgentCaddyPackage($agents)),
        ssh: new class($commands, $failRuntime) implements SshExecutor
        {
            public function __construct(private array &$commands, private bool $failRuntime) {}

            public function execute(SshConnection $connection, RemoteCommand $command): CommandResult
            {
                $this->commands[] = $command;
                $isRuntime = $command->protectedInput !== null;

                return new CommandResult($isRuntime && $this->failRuntime ? 1 : 0, '', '', 1, false);
            }
        },
        keys: new class implements SshKeyProvider
        {
            public function privateKeyPath(): string
            {
                return '/tmp/orbit-test-key';
            }

            public function publicKey(): string
            {
                return 'ssh-ed25519 AAAA test';
            }
        },
        knownHosts: new class implements KnownHostsStore
        {
            public function path(): string
            {
                return '/tmp/orbit-test-known-hosts';
            }

            public function put(string $host, int $port, HostKey $key): void {}
        },
        accounts: new class implements ManagedUserAccountResolver
        {
            public function resolve(Node $node): ManagedUserAccount
            {
                return new ManagedUserAccount('orbit-websocket', 'orbit-websocket', '/home/orbit-websocket');
            }
        },
        repository: $repository,
        ref: $ref,
        installPath: $installPath,
        port: 8790,
    );
}

it('prepares a separate release before stopping the running service', function (bool $buildFails, bool $listenerReady): void {
    $commands = [];
    websocket_runtime_lifecycle($commands)->converge(websocket_runtime_node(), new NodeRole, new WebSocketServerCredentials('base64:aw==', 'process', 'registry-token', 'notify-token'));
    $script = stream_get_contents($commands[0]->protectedInput->stream());
    $directory = sys_get_temp_dir().'/orbit-websocket-install-'.bin2hex(random_bytes(8));
    mkdir($directory.'/bin', 0700, true);
    mkdir($directory.'/systemd', 0700);
    mkdir($directory.'/current', 0700);
    file_put_contents($directory.'/current/version', 'old');
    mkdir($directory.'/current.releases/release.stale', 0700, true);
    mkdir($directory.'/current.releases/previous.stale', 0700);
    $listener = stream_socket_server('tcp://127.0.0.1:0');
    $port = parse_url('tcp://'.stream_socket_get_name($listener, false), PHP_URL_PORT);
    if (! $listenerReady) {
        fclose($listener);
    }
    $scripts = [
        'sudo' => <<<'SH'
            #!/bin/bash
            shift 3
            if [ "$1" = /usr/bin/php ]; then exit 0; fi
            exec "$@"
            SH,
        'git' => <<<'SH'
            #!/bin/bash
            if [ "$1" = clone ]; then
                destination="${@: -1}"
                printf new > "$destination/version"
                touch "$destination/artisan"
            fi
            SH,
        'composer' => <<<'SH'
            #!/bin/bash
            printf 'build\n' >> "$TEST_INSTALL_EVENTS"
            test "$TEST_INSTALL_FAIL" != 1
            SH,
        'systemctl' => <<<'SH'
            #!/bin/bash
            printf '%s\n' "$*" >> "$TEST_INSTALL_EVENTS"
            if [ "$1" = stop ]; then
                test "$(cat "$TEST_INSTALL_CURRENT/version")" = "$TEST_INSTALL_PREVIOUS" || exit 2
            fi
            if [ "$1" = restart ]; then
                test "$(cat "$TEST_INSTALL_CURRENT/version")" = new || exit 3
            fi
            SH,
        'sleep' => "#!/bin/bash\nprintf 'wait\\n' >> \"\$TEST_INSTALL_EVENTS\"\n",
        'systemd-analyze' => "#!/bin/bash\nexit 0\n",
        'chown' => "#!/bin/bash\nexit 0\n",
        'install' => <<<'SH'
            #!/bin/bash
            args=()
            while [ "$#" -gt 0 ]; do
                case "$1" in
                    -o|-g) shift 2 ;;
                    *) args+=("$1"); shift ;;
                esac
            done
            exec /usr/bin/install "${args[@]}"
            SH,
    ];
    foreach ($scripts as $name => $contents) {
        file_put_contents($directory.'/bin/'.$name, $contents);
        chmod($directory.'/bin/'.$name, 0700);
    }
    $script = str_replace('/etc/systemd/system', $directory.'/systemd', $script);
    try {
        $process = new Process(['bash', '-seu', '--', 'test-user', 'test-group', $directory.'/current', 'test-repository', 'test-ref', (string) $port], env: [
            'PATH' => $directory.'/bin:'.getenv('PATH'), 'TEST_INSTALL_EVENTS' => $directory.'/events',
            'TEST_INSTALL_PREVIOUS' => 'old', 'TEST_INSTALL_FAIL' => $buildFails ? '1' : '0', 'TEST_INSTALL_CURRENT' => $directory.'/current',
        ], input: $script);
        $process->run();
        $events = file_get_contents($directory.'/events');
        if ($buildFails) {
            expect($process->isSuccessful())->toBeFalse()->and($events)->toBe("build\n")
                ->and(file_get_contents($directory.'/current/version'))->toBe('old')
                ->and(glob($directory.'/current.releases/release.*'))->toBe([$directory.'/current.releases/release.stale'])
                ->and(is_dir($directory.'/current.releases/previous.stale'))->toBeTrue();
        } elseif (! $listenerReady) {
            expect($process->isSuccessful())->toBeFalse()
                ->and($process->getErrorOutput())->toContain('did not open its listener within 30 seconds')
                ->and(substr_count($events, "wait\n"))->toBe(30)
                ->and(file_get_contents($directory.'/current/version'))->toBe('new')
                ->and(glob($directory.'/current.releases/*'))->toHaveCount(4)
                ->and(is_dir($directory.'/current.releases/release.stale'))->toBeTrue()
                ->and(is_dir($directory.'/current.releases/previous.stale'))->toBeTrue();
        } else {
            expect($process->isSuccessful())->toBeTrue($process->getErrorOutput())
                ->and(is_link($directory.'/current'))->toBeTrue()
                ->and(file_get_contents($directory.'/current/version'))->toBe('new')
                ->and($events)->toContain("build\ncat orbit-websocket.service\nstop orbit-websocket")
                ->and(glob($directory.'/current.releases/previous.*/version'))->toHaveCount(1);
            expect(glob($directory.'/current.releases/*'))->toHaveCount(2);
            for ($install = 0; $install < 2; $install++) {
                $previous = readlink($directory.'/current');
                $process->setEnv([...$process->getEnv(), 'TEST_INSTALL_PREVIOUS' => 'new']);
                $process->mustRun();
                $releases = glob($directory.'/current.releases/*');
                expect($releases)->toHaveCount(2)->toContain($previous, readlink($directory.'/current'));
            }

        }
    } finally {
        if (is_resource($listener)) {
            fclose($listener);
        }
        new Filesystem()->remove($directory);
    }
})->with([
    'build and listener succeed' => [false, true],
    'build fails' => [true, false],
    'active process never listens' => [false, false],
]);
