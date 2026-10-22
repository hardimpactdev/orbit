<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Infrastructure\Tasks\TaskUnitProgram;
use Illuminate\Filesystem\Filesystem;
use RuntimeException;
use Symfony\Component\Process\Process;

/** Immutable admission prerequisites; each managed lifecycle owns a complete, writable copy. */
final class ManagedTaskCheckSeed
{
    private readonly string $directory;

    private readonly string $program;

    public function __construct()
    {
        $this->directory = sys_get_temp_dir().'/orbit-managed-check-seed-'.bin2hex(random_bytes(6));
        $this->program = TaskUnitProgram::read('tasks/check');
        new Process(['git', 'init', '--quiet', $this->directory])->mustRun();
        new Process(['git', '-c', 'user.name=t', '-c', 'user.email=t@t', 'commit', '--quiet', '--allow-empty', '-m', 'start'], $this->directory)->mustRun();
        file_put_contents($this->directory.'/composer.json', json_encode(['scripts' => ['check' => 'true']], JSON_THROW_ON_ERROR));
        file_put_contents($this->directory.'/.gitignore', "ignored/\n");
        file_put_contents($this->directory.'/uncommitted.php', "<?php\n");
        mkdir($this->directory.'/.git/orbit');
        file_put_contents($this->directory.'/.git/orbit/check', $this->program);
        file_put_contents($this->directory.'/.git/orbit/composer.php', file_get_contents(resource_path('tasks/composer')));
        new Process(['chmod', '-R', 'a-w', $this->directory])->mustRun();
    }

    public function checkout(string $directory): string
    {
        $checkout = $directory.'/checkout';
        new Filesystem()->copyDirectory($this->directory, $checkout);
        new Process(['chmod', '-R', 'u+w', $checkout])->mustRun();

        return $checkout;
    }

    /** Metadata publication has separate coverage; run the seeded, unmodified check program here. */
    public function transport(string $checkout): LocalShellSshExecutor
    {
        $transport = new LocalShellSshExecutor;
        $transport->transformInput = function (string $input) use ($checkout): string {
            return preg_replace_callback("/printf '%s' '([A-Za-z0-9+\\/=]+)' \\| base64 -d \\| workspace_metadata '(check|snapshot)'\\n/", function (array $match) use ($checkout): string {
                $payload = json_decode(base64_decode($match[1], true), true, flags: JSON_THROW_ON_ERROR);
                if (base64_decode($payload['script'], true) !== $this->program) {
                    throw new RuntimeException('The managed check seed must contain the current check program.');
                }
                if ($match[2] === 'check') {
                    if ($payload['setup'] !== '[]' || $payload['deliverables'] !== null) {
                        throw new RuntimeException('The managed lifecycle fixture has no setup or deliverables.');
                    }
                    file_put_contents($checkout.'/.git/orbit/check-command', $payload['command']);
                }

                return '';
            }, $input);
        };

        return $transport;
    }

    public function close(): void
    {
        new Process(['chmod', '-R', 'u+w', $this->directory])->mustRun();
        new Filesystem()->deleteDirectory($this->directory);
    }
}
