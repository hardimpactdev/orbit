<?php

declare(strict_types=1);

namespace App\Infrastructure\TaskVms;

use App\Domain\TaskVms\TaskVmException;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;

/**
 * Makes the ed25519 host key that the Gateway pins for an UpCloud build server: UpCloud has no trusted host that could read the guest's
 * key, so the Gateway generates it, gives it to the server in its user-data, and pins it. No trust on first use.
 */
final readonly class UpCloudGuest
{
    private const string PublicKeyPattern = '/\Assh-ed25519 ([A-Za-z0-9+\/]+={0,2})(?: .*)?\z/D';

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
}
