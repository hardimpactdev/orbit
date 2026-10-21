<?php

declare(strict_types=1);

use App\Domain\Nodes\ManagedUserAccount;
use App\Domain\Nodes\ManagedUserAccountResolver;
use App\Domain\Nodes\NodeRoleOperationException;
use App\Domain\Nodes\RoleName;
use App\Domain\Shared\LifecycleStatus;
use App\Domain\WebSocket\WebSocketCredentials;
use App\Domain\WebSocket\WebSocketServerCredentials;
use App\Infrastructure\Nodes\CaddyPackageProgram;
use App\Infrastructure\Nodes\Roles\NodeRolePrerequisiteCommandFactory;
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
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;

it('installs prerequisites, then clones, installs and restarts Reverb on every converge', function (): void {
    $commands = [];
    $lifecycle = websocket_runtime_lifecycle($commands);
    $node = websocket_runtime_node();
    $assignment = new NodeRole(['role' => RoleName::WebSocket, 'status' => LifecycleStatus::Provisioning]);
    $credentials = new WebSocketServerCredentials('base64:'.base64_encode('k'), [new WebSocketCredentials('app-id', 'app-key', 'app-secret')]);

    $lifecycle->converge($node, $assignment, $credentials);

    expect($commands)->toHaveCount(3);

    $caddyPackage = $commands[0];
    expect($caddyPackage->arguments)
        ->toContain(CaddyPackageProgram::RELEASE_URL)
        ->toContain('/etc/apt/sources.list.d/orbit-caddy.sources')
        ->and($caddyPackage->input)->toContain('sha512sum --check');

    $prerequisites = $commands[1];
    expect($prerequisites->arguments)->toContain('caddy')
        ->and($prerequisites->arguments)->toContain('composer')
        ->and($prerequisites->arguments)->toContain('git')
        ->and($prerequisites->arguments)->toContain('php-curl')
        ->and($prerequisites->arguments)->toContain('php-xml');

    $runtime = $commands[2];

    expect($runtime->arguments)
        ->toContain('orbit-websocket')
        ->toContain('/opt/orbit/websocket')
        ->toContain('https://example.test/orbit-reverb.git')
        ->toContain('release-42')
        ->and($runtime->protectedInput)
        ->not
        ->toBeNull();

    $script = stream_get_contents($runtime->protectedInput->stream());

    expect($script)
        ->toContain('git clone')
        ->toContain('fetch --quiet origin "$ref"')
        ->toContain('composer install')
        ->toContain('systemd-analyze verify "$verify_directory/orbit-websocket.service"')
        ->toContain("systemctl daemon-reload\nsystemctl enable --quiet orbit-websocket\nsystemctl restart orbit-websocket\nsystemctl is-active --quiet orbit-websocket")
        ->not->toContain('env_changed')
        ->not->toContain('cmp ')
        ->not->toContain('systemctl start orbit-websocket')
        ->not
        ->toContain('app-secret')
        ->not
        ->toContain('[PROTECTED]');
});

it('fetches the configured repository when an existing checkout has an obsolete origin', function (): void {
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
        $git(['-C', $checkout, 'remote', 'set-url', 'origin', $directory.'/removed-owner/orbit-reverb']);
        $commands = [];
        $lifecycle = websocket_runtime_lifecycle($commands, repository: $repository, installPath: $checkout);
        $assignment = new NodeRole(['role' => RoleName::WebSocket, 'status' => LifecycleStatus::Provisioning]);
        $credentials = new WebSocketServerCredentials('base64:'.base64_encode('k'), [new WebSocketCredentials('app-id', 'app-key', 'app-secret')]);
        $lifecycle->converge(websocket_runtime_node(), $assignment, $credentials);
        $runtime = $commands[2];
        $sudo = <<<'BASH'
            sudo() {
                [ "$1" = -u ] && [ "$3" = -H ] || return 1
                shift 3
                if [ "$1" = env ]; then
                    [ "$2" = COMPOSER_ALLOW_SUPERUSER=1 ] && [ "$3" = composer ] || exit 1
                    exit 0
                fi
                [ "$1" = git ] || return 1
                command "$@"
            }
            BASH;
        $process = new Process(array_slice($runtime->arguments, 1));
        $process->setInput($sudo."\n".stream_get_contents($runtime->protectedInput->stream()));

        $process->mustRun();

        expect($git(['-C', $checkout, 'remote', 'get-url', 'origin']))->toBe($repository);
        expect(file_get_contents($checkout.'/VERSION'))->toBe('current release');
        expect($git(['-C', $checkout, 'rev-parse', 'HEAD']))->toBe($git(['-C', $repository, 'rev-parse', 'HEAD']));
    } finally {
        new Filesystem()->deleteDirectory($directory);
    }
});

it('throws a NodeRoleOperationException when convergence fails', function (): void {
    $commands = [];
    $lifecycle = websocket_runtime_lifecycle($commands, failRuntime: true);
    $node = websocket_runtime_node();
    $assignment = new NodeRole(['role' => RoleName::WebSocket, 'status' => LifecycleStatus::Provisioning]);
    $credentials = new WebSocketServerCredentials('base64:'.base64_encode('k'), [new WebSocketCredentials('app-id', 'app-key', 'app-secret')]);

    expect(fn () => $lifecycle->converge($node, $assignment, $credentials))
        ->toThrow(NodeRoleOperationException::class);
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
        ->toContain('rm -rf -- "$install_path"');
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
): NativeWebSocketRuntimeLifecycle {
    return new NativeWebSocketRuntimeLifecycle(
        commands: new NodeRolePrerequisiteCommandFactory,
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
        ref: 'release-42',
        installPath: $installPath,
        port: 8790,
    );
}
