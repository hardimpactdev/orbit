<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Infrastructure\Processes\CommandResult;
use App\Infrastructure\Ssh\RemoteCommand;
use App\Infrastructure\Ssh\SshConnection;
use App\Infrastructure\Ssh\SshExecutor;
use App\Infrastructure\TaskVms\TaskVmImagePeers;
use RuntimeException;

/**
 * Task VM hosts that answer the Gateway's SSH commands by argv, keyed by WireGuard address. Each host keeps its
 * images, instances, transfer key, receiver, `authorized_keys`, host key, and image exports. Host scripts answer by their
 * name in `resources/task-vms`. A transfer succeeds only as sshd and the receiver would allow it: the source sends from the address and with the key that the target's
 * `authorized_keys` names, the pinned host key is the target's, and the target has the receiver.
 */
final class FakeIncusFleet implements SshExecutor
{
    /** @var array<string, FakeIncusFleetHost> */
    public array $hosts = [];

    /** @var list<array{string, list<string>, ?string}> each command as host, argv, stdin */
    public array $commands = [];

    /** The setup script's answer. */
    public string $setupOutput = "{\"ok\":true}\n";

    public function host(string $address): FakeIncusFleetHost
    {
        return $this->hosts[$address] ??= new FakeIncusFleetHost($address);
    }

    /** @return list<string> the commands that ran on `$address`, without `sudo -n` */
    public function argv(string $address): array
    {
        return array_values(array_map(
            static fn (array $command): string => implode(' ', array_slice($command[1], 2)),
            array_filter($this->commands, static fn (array $command): bool => $command[0] === $address),
        ));
    }

    public function execute(SshConnection $connection, RemoteCommand $command): CommandResult
    {
        $argv = $command->arguments;
        $this->commands[] = [$connection->host, $argv, $command->input];
        $host = $this->host($connection->host);
        if ($argv[0] !== 'sudo' || $argv[1] !== '-n') {
            throw new RuntimeException('Unexpected command: '.implode(' ', $argv));
        }

        return match ($argv[2]) {
            'incus' => $argv[3] === '--project'
                ? $host->project(array_slice($argv, 5), $command->input)
                : $this->incus($connection->host, array_slice($argv, 3)),
            'bash' => $argv[3] === '-s' ? new CommandResult(0, $this->setupOutput, '', 1, false) : $this->script($host, $argv[4], array_slice($argv, 6), (string) $command->input),
            'cat' => $host->answer('read the host key', static fn (): string => $host->hostKey." root@{$host->address}\n"),
            'rm' => $host->answer('rm', static function () use ($host, $argv): void {
                foreach (array_slice($argv, 5) as $path) {
                    match (true) {
                        $path === TaskVmImagePeers::KeyDirectory => $host->transferKey = null,
                        $path === TaskVmImagePeers::Receiver => $host->receiver = null,
                        $path === TaskVmImagePeers::WorkDirectory => $host->exports = [],
                        default => $host->exports = array_filter($host->exports, static fn (string $file): bool => ! str_starts_with($file, $path.'/')),
                    };
                }
            }),
            default => throw new RuntimeException('Unexpected command: '.implode(' ', $argv)),
        };
    }

    /** The host that owns `$address`, by its WireGuard or LAN address. */
    public function owner(string $address): ?FakeIncusFleetHost
    {
        foreach ($this->hosts as $host) {
            if ($host->address === $address || $host->lan === $address) {
                return $host;
            }
        }

        return null;
    }

    /** @param  list<string>  $argv */
    private function incus(string $address, array $argv): CommandResult
    {
        $host = $this->host($address);

        if ($argv[0] === 'query' && $argv[1] === '--') {
            return $host->answer('inventory', static fn (): string => json_encode(match ($argv[2]) {
                // The stray report's inventory: the instances of the task VM project, and no custom volume.
                '/1.0/instances?all-projects=true' => array_map(static fn (string $name): string => '/1.0/instances/'.$name.'?project=orbit-tasks', array_keys($host->instances)),
                '/1.0/storage-pools' => ['/1.0/storage-pools/orbit-tasks'],
                default => [],
            }, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
        }

        return match (true) {
            $argv[0] === 'query' && $host->fail === 'query reply' => (function () use ($host, $argv): CommandResult {
                $host->retarget(json_decode($argv[4], true)['target'], rawurldecode(basename(explode('?', $argv[6], 2)[0])));

                return new CommandResult(255, '', "Connection reset by peer\n", 1, false);
            })(),
            $argv[0] === 'query' => $host->answer('query', fn () => $host->retarget(json_decode($argv[4], true)['target'], rawurldecode(basename(explode('?', $argv[6], 2)[0])))),
            default => throw new RuntimeException('Unexpected incus command: '.implode(' ', $argv)),
        };
    }

    /** @param  list<string>  $arguments */
    private function script(FakeIncusFleetHost $host, string $script, array $arguments, string $input): CommandResult
    {
        return match (TaskVmScriptNames::of($script)) {
            'peer-identity.sh' => $host->answer('ssh-keygen transfer key', static function () use ($host, $arguments): string {
                $host->transferKey ??= FakeIncusFleetHost::publicKey();

                return $host->transferKey.' '.$arguments[1]."\n";
            }),
            'peer-install-receiver.sh' => $host->answer('install the receiver', static function () use ($host, $arguments, $input): void {
                expect($arguments[0])->toBe(TaskVmImagePeers::Receiver);
                $host->receiver = $input;
            }),
            'peer-authorized-keys.sh' => $host->answer('authorized_keys', static function () use ($host, $arguments, $input): void {
                $host->user = $arguments[0];
                $kept = array_values(array_filter($host->authorizedKeys, static function (string $line) use ($arguments): bool {
                    $fields = preg_split('/\s+/', trim($line));

                    return preg_match('/'.$arguments[1].'/', (string) end($fields)) !== 1;
                }));
                $host->authorizedKeys = [...$kept, ...array_values(array_filter(explode("\n", $input), static fn (string $line): bool => $line !== ''))];
            }),
            'image-export.sh' => $this->export($host, $arguments),
            'peer-send.sh' => $this->send($host, $arguments),
            default => throw new RuntimeException('Unexpected script: '.$script),
        };
    }

    /**
     * `export <directory> <fingerprint> <project>`: one image file in the directory.
     *
     * @param  list<string>  $arguments
     */
    private function export(FakeIncusFleetHost $host, array $arguments): CommandResult
    {
        [$directory, $fingerprint] = $arguments;

        return $host->answer('image export', static function () use ($host, $directory, $fingerprint): string {
            if (! isset($host->images[$fingerprint])) {
                throw new RuntimeException('Error: Image not found');
            }
            $file = $directory.'/image.tar';
            $host->exports[$fingerprint] = $file;

            return $file."\n";
        });
    }

    /**
     * `send <input> <key> <bind> <host key> <user> <address>`, as the source's ssh to the target's receiver.
     *
     * @param  list<string>  $arguments
     */
    private function send(FakeIncusFleetHost $source, array $arguments): CommandResult
    {
        [$input, $key, $bind, $hostKey, $user, $address] = $arguments;
        $target = $this->owner($address);
        if (! $target instanceof FakeIncusFleetHost || $target->fail === 'ssh connect') {
            return new CommandResult(255, '', "ssh: connect to host {$address} port 22: Connection refused\n", 1, false);
        }
        if ($key !== TaskVmImagePeers::KeyDirectory.'/id_ed25519' || $this->owner($bind) !== $source || $user !== $target->user) {
            throw new RuntimeException("Unexpected send from [{$bind}] as [{$user}] with [{$key}].");
        }
        if ($hostKey !== ($target->sshdKey ?? $target->hostKey)) {
            return new CommandResult(255, '', "Host key verification failed.\n", 1, false);
        }
        $line = array_values(array_filter($target->authorizedKeys, static fn (string $line): bool => $source->transferKey !== null
            && str_starts_with($line, 'from="'.$bind.'",restrict,command="sudo -n '.TaskVmImagePeers::Receiver.' ')
            && str_contains($line, '" '.$source->transferKey.' ')))[0] ?? null;
        if ($line === null) {
            return new CommandResult(255, '', "{$user}@{$address}: Permission denied (publickey).\n", 1, false);
        }
        if ($target->receiver === null) {
            return new CommandResult(1, '', "sudo: /usr/local/libexec/orbit/task-vm-image-receive: command not found\n", 1, false);
        }
        if ($input === '/dev/null') {
            return new CommandResult(0, "ready\n", '', 1, false);
        }
        $fingerprint = array_search($input, $source->exports, true);
        if (! is_string($fingerprint)) {
            throw new RuntimeException("The source has no export [{$input}].");
        }
        if ($target->fail === 'transfer') {
            return new CommandResult(255, '', "client_loop: send disconnect: Broken pipe\n", 1, false);
        }
        $received = $target->copyAs ?? $fingerprint;
        $target->images[$received] ??= [];
        $target->received[] = $received;
        if ($target->fail === 'transfer reply') {
            return new CommandResult(255, '', "Connection to {$address} closed by remote host.\n", 1, false);
        }

        return new CommandResult(0, $received."\n", '', 1, false);
    }
}
