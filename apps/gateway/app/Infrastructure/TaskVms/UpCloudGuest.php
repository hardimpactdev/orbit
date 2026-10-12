<?php

declare(strict_types=1);

namespace App\Infrastructure\TaskVms;

use App\Domain\TaskVms\TaskVmException;
use App\Infrastructure\Processes\CommandResult;
use App\Infrastructure\Ssh\HostKey;
use App\Infrastructure\Ssh\KnownHostsStore;
use App\Infrastructure\Ssh\RemoteCommand;
use App\Infrastructure\Ssh\SshConnection;
use App\Infrastructure\Ssh\SshExecutor;
use App\Infrastructure\Ssh\SshKeyProvider;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;

/**
 * Reaches an UpCloud server that Orbit created, before it is a Node: over SSH as `orbit` to its public IPv4 address,
 * with the ed25519 host key that the Gateway generated for it and gave it in its user-data. UpCloud has no trusted
 * host that could read the guest's key, so the Gateway makes the key and pins it: no trust on first use.
 */
final readonly class UpCloudGuest
{
    private const string PublicKeyPattern = '/\Assh-ed25519 ([A-Za-z0-9+\/]+={0,2})(?: .*)?\z/D';

    public function __construct(
        private SshExecutor $ssh,
        private KnownHostsStore $knownHosts,
        private SshKeyProvider $keys,
    ) {}

    /**
     * A new ed25519 host key pair, as the private key in OpenSSH format and the public key line.
     *
     * @return array{private: string, public: string}
     */
    public function generateHostKey(string $comment): array
    {
        $directory = sys_get_temp_dir().'/orbit-tvm-key-'.Str::random(16);
        File::ensureDirectoryExists($directory, 0700);
        try {
            $result = Process::timeout(30)->run(['ssh-keygen', '-q', '-t', 'ed25519', '-N', '', '-C', $comment, '-f', $directory.'/key']);
            $private = @file_get_contents($directory.'/key');
            $public = @file_get_contents($directory.'/key.pub');
            if (! $result->successful() || ! is_string($private) || ! is_string($public) || preg_match(self::PublicKeyPattern, trim($public)) !== 1) {
                throw new TaskVmException('task_vm.host_key_failed', 'The Gateway could not generate an SSH host key for a task VM.', 500);
            }

            return ['private' => $private, 'public' => trim($public)];
        } finally {
            File::deleteDirectory($directory);
        }
    }

    /** The `SHA256:…` fingerprint of an ed25519 public key line. */
    public static function fingerprint(string $publicKey): string
    {
        if (preg_match(self::PublicKeyPattern, trim($publicKey), $match) !== 1) {
            throw new TaskVmException('task_vm.host_key_failed', 'The pinned task VM host key is not an ed25519 public key.', 500);
        }

        return 'SHA256:'.rtrim(base64_encode(hash('sha256', (string) base64_decode($match[1], true), true)), '=');
    }

    /** Runs `$command` as `orbit` on the server at `$address`, which must present `$publicKey`. */
    public function run(string $address, string $publicKey, RemoteCommand $command, int $connectTimeout = 10): CommandResult
    {
        preg_match(self::PublicKeyPattern, trim($publicKey), $match);
        $this->knownHosts->put($address, 22, new HostKey('ssh-ed25519', $match[1] ?? '', self::fingerprint($publicKey)));

        return $this->ssh->execute(new SshConnection(
            host: $address,
            user: 'orbit',
            port: 22,
            identityFile: $this->keys->privateKeyPath(),
            knownHostsFile: $this->knownHosts->path(),
            connectTimeout: $connectTimeout,
            commandTimeout: $command->timeout ?? 900.0,
            shareConnection: false,
        ), $command);
    }
}
