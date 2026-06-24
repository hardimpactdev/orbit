<?php

declare(strict_types=1);

namespace App\Services\Platform;

final readonly class LocalPlatformDetector
{
    public function current(): string
    {
        if (PHP_OS_FAMILY === 'Darwin') {
            return $this->macOsIdentifier($this->commandOutput('sw_vers -productVersion'));
        }

        if (PHP_OS_FAMILY === 'Linux') {
            $contents = is_file('/etc/os-release') ? (string) file_get_contents('/etc/os-release') : '';

            return $this->linuxIdentifier($contents);
        }

        return strtolower(PHP_OS_FAMILY);
    }

    public function macOsIdentifier(string $version): string
    {
        return 'macos_'.str_replace('.', '-', trim($version));
    }

    public function linuxIdentifier(string $osRelease): string
    {
        $values = [];

        foreach (explode("\n", $osRelease) as $line) {
            if (! str_contains($line, '=')) {
                continue;
            }

            [$key, $value] = explode('=', $line, 2);
            $values[$key] = trim($value, "\"'");
        }

        $id = strtolower($values['ID'] ?? 'linux');
        $version = str_replace('.', '-', strtolower($values['VERSION_ID'] ?? ''));

        return $version === '' ? $id : "{$id}_{$version}";
    }

    private function commandOutput(string $command): string
    {
        $output = shell_exec($command);

        return is_string($output) ? trim($output) : '';
    }
}
