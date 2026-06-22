<?php

declare(strict_types=1);

namespace App\Services\CodexApp;

final readonly class CodexAppConfigMerger
{
    /**
     * @param  array<string, mixed>  $config
     * @return array<string, mixed>
     */
    public function addProject(array $config, string $label, string $sshAlias, string $remotePath): array
    {
        $project = [
            'remotePath' => $remotePath,
            'label' => $label,
        ];

        $connections = $this->remoteConnections($config);
        $connectionUpdated = false;

        foreach ($connections as $index => $connection) {
            if (($connection['sshAlias'] ?? null) !== $sshAlias) {
                continue;
            }

            $projects = $this->projects($connection);
            $projectUpdated = false;

            foreach ($projects as $projectIndex => $existingProject) {
                if (($existingProject['label'] ?? null) !== $label) {
                    continue;
                }

                $projects[$projectIndex] = [
                    ...$existingProject,
                    ...$project,
                ];
                $projectUpdated = true;
            }

            if (! $projectUpdated) {
                $projects[] = $project;
            }

            $connections[$index] = [
                ...$connection,
                'sshAlias' => $sshAlias,
                'projects' => $projects,
            ];
            $connectionUpdated = true;
        }

        if (! $connectionUpdated) {
            $connections[] = [
                'sshAlias' => $sshAlias,
                'projects' => [$project],
            ];
        }

        $config['version'] ??= 1;
        $config['remoteConnections'] = $connections;

        return $config;
    }

    /**
     * @param  array<string, mixed>  $config
     * @return array<string, mixed>
     */
    public function removeProject(array $config, string $label, string $sshAlias): array
    {
        $connections = $this->remoteConnections($config);

        foreach ($connections as $index => $connection) {
            if (($connection['sshAlias'] ?? null) !== $sshAlias) {
                continue;
            }

            $connections[$index] = [
                ...$connection,
                'projects' => array_values(array_filter(
                    $this->projects($connection),
                    static fn (array $project): bool => ($project['label'] ?? null) !== $label,
                )),
            ];
        }

        $config['remoteConnections'] = $connections;

        return $config;
    }

    /**
     * @param  array<string, mixed>  $config
     */
    public function hasProject(array $config, string $label, string $sshAlias): bool
    {
        foreach ($this->remoteConnections($config) as $connection) {
            if (($connection['sshAlias'] ?? null) !== $sshAlias) {
                continue;
            }

            foreach ($this->projects($connection) as $project) {
                if (($project['label'] ?? null) === $label) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * @param  array<string, mixed>  $config
     * @return list<array<string, mixed>>
     */
    public function remoteConnections(array $config): array
    {
        $connections = $config['remoteConnections'] ?? [];

        if (! is_array($connections)) {
            return [];
        }

        $normalized = [];

        foreach ($connections as $connection) {
            if (is_array($connection)) {
                $normalized[] = $connection;
            }
        }

        return $normalized;
    }

    /**
     * @param  array<string, mixed>  $connection
     * @return list<array<string, mixed>>
     */
    public function projects(array $connection): array
    {
        $projects = $connection['projects'] ?? [];

        if (! is_array($projects)) {
            return [];
        }

        $normalized = [];

        foreach ($projects as $project) {
            if (is_array($project)) {
                $normalized[] = $project;
            }
        }

        return $normalized;
    }
}
