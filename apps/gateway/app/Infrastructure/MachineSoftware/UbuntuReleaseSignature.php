<?php

declare(strict_types=1);

namespace App\Infrastructure\MachineSoftware;

use RuntimeException;
use Symfony\Component\Process\Process;

final readonly class UbuntuReleaseSignature
{
    public function __construct(private string $keyring = '/usr/share/keyrings/ubuntu-archive-keyring.gpg') {}

    /** Uses the OS-maintained Ubuntu archive trust root; never imports a key supplied by the recipe. */
    public function verify(string $file): string
    {
        $process = new Process(['gpgv', '--keyring', $this->keyring, '--output', '-', $file]);
        $process->setTimeout(30);
        $process->run();
        if (! $process->isSuccessful()) {
            throw new RuntimeException('Ubuntu InRelease signature verification failed: '.trim($process->getErrorOutput()));
        }

        return $process->getOutput();
    }
}
