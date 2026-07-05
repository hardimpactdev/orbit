<?php

declare(strict_types=1);

namespace App\Services\Apps;

use Symfony\Component\Process\Process;

final readonly class LocalAppRuntimeConfigsProbe
{
    private const string DIRECTORY = '/etc/orbit/apps';

    /**
     * @return array<string, mixed>
     */
    public function probe(): array
    {
        $directoryCheck = new Process(['sudo', 'test', '-d', self::DIRECTORY]);
        $directoryCheck->setTimeout(10);
        $directoryCheck->run();

        if ($directoryCheck->getExitCode() === 1 && trim($directoryCheck->getErrorOutput()) === '') {
            return [
                'status' => 'absent',
                'paths' => [],
                'error' => '',
                'stdout' => "orbit-config-dir:absent\n",
            ];
        }

        if ($directoryCheck->getExitCode() !== 0) {
            $error = trim($directoryCheck->getErrorOutput());
            $error = $error !== '' ? $error : 'sudo test failed';

            return $this->error($error);
        }

        $find = new Process([
            'sudo',
            'find',
            self::DIRECTORY,
            '-maxdepth',
            '1',
            '-type',
            'f',
            '-name',
            '*.ini',
            '-print',
        ]);
        $find->setTimeout(15);
        $find->run();

        if ($find->getExitCode() !== 0) {
            $error = trim($find->getErrorOutput());
            $error = $error !== '' ? $error : "sudo find failed (ec={$find->getExitCode()})";

            return $this->error($error);
        }

        $paths = array_values(array_filter(
            array_map(trim(...), explode("\n", trim($find->getOutput()))),
            static fn (string $path): bool => $path !== '',
        ));

        return [
            'status' => 'present',
            'paths' => $paths,
            'error' => '',
            'stdout' => "orbit-config-dir:present\n".$this->pathOutput($paths),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function error(string $error): array
    {
        return [
            'status' => 'error',
            'paths' => [],
            'error' => $error,
            'stdout' => "orbit-config-dir:error {$error}\n",
        ];
    }

    /**
     * @param  list<string>  $paths
     */
    private function pathOutput(array $paths): string
    {
        if ($paths === []) {
            return '';
        }

        return implode("\n", $paths)."\n";
    }
}
