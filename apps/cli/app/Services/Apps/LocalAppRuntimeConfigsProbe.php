<?php

declare(strict_types=1);

namespace App\Services\Apps;

final readonly class LocalAppRuntimeConfigsProbe
{
    /**
     * @return array<string, mixed>
     */
    public function probe(): array
    {
        $root = $this->userConfigRoot();

        if ($root === null) {
            return $this->error('HOME is invalid');
        }

        $directory = "{$root}/apps";

        if (! file_exists($directory)) {
            return [
                'status' => 'absent',
                'paths' => [],
                'error' => '',
                'stdout' => "orbit-config-dir:absent\n",
            ];
        }

        if (! is_dir($directory)) {
            return $this->error("{$directory} is not a directory");
        }

        if (! is_readable($directory)) {
            return $this->error("{$directory} is not readable");
        }

        $globbedPaths = glob("{$directory}/*.ini");
        $paths = array_values(array_filter(
            $globbedPaths === false ? [] : $globbedPaths,
            is_file(...),
        ));
        sort($paths);

        return [
            'status' => 'present',
            'paths' => $paths,
            'error' => '',
            'stdout' => "orbit-config-dir:present\n".$this->pathOutput($paths),
        ];
    }

    private function userConfigRoot(): ?string
    {
        $home = $_SERVER['HOME'] ?? $_ENV['HOME'] ?? getenv('HOME');
        $home = is_string($home) ? rtrim($home, characters: '/') : '';

        if (
            $home !== ''
            && str_starts_with($home, '/')
            && preg_match('/[\x00-\x1F\x7F]/', $home) !== 1
            && preg_match('#(?:^|/)\.\.(?:/|$)#', $home) !== 1
        ) {
            return "{$home}/.config/orbit";
        }

        return null;
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
