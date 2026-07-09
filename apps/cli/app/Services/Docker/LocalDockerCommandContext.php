<?php

declare(strict_types=1);

namespace App\Services\Docker;

final readonly class LocalDockerCommandContext
{
    /**
     * @param  list<string>  $command
     * @return array<string, string>
     */
    public function environmentFor(array $command): array
    {
        if (($command[0] ?? null) !== 'docker') {
            return [];
        }

        if ($this->hasEnvironmentValue('DOCKER_HOST') || $this->hasEnvironmentValue('DOCKER_CONTEXT')) {
            return [];
        }

        $orbstackSocket = $this->orbstackDockerSocket();

        if ($orbstackSocket === null) {
            return [];
        }

        return [
            'DOCKER_HOST' => "unix://{$orbstackSocket}",
        ];
    }

    public function networkAlreadyExists(string $output, string $network): bool
    {
        $output = strtolower($output);
        $network = strtolower($network);

        return (
            str_contains($output, 'already exists')
            && (str_contains($output, $network) || str_contains($output, 'network with name'))
        );
    }

    private function hasEnvironmentValue(string $key): bool
    {
        $value = getenv($key);

        return is_string($value) && trim($value) !== '';
    }

    private function orbstackDockerSocket(): ?string
    {
        $home = getenv('HOME');

        if (! is_string($home) || trim($home) === '' || str_contains($home, "\0")) {
            return null;
        }

        $socket = rtrim(string: $home, characters: '/').'/.orbstack/run/docker.sock';

        return file_exists($socket) ? $socket : null;
    }
}
