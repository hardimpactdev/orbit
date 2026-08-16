<?php

declare(strict_types=1);

namespace App\Services\CodexApp;

/**
 * @mago-expect lint:cyclomatic-complexity
 * @mago-expect lint:kan-defect
 */
final readonly class LocalCodexAppConfigMerger
{
    /**
     * @param  array<string, mixed>  $config
     * @param  array{label: string, ssh_alias: string, remote_path?: string}  $project
     * @return array{0: array<string, mixed>, 1: bool}
     */
    public function merge(array $config, string $mutation, array $project): array
    {
        if ($mutation === 'add') {
            return [$this->addProject($config, $project), false];
        }

        return $this->removeProject($config, $project);
    }

    /**
     * @param  array<string, mixed>  $config
     * @param  array{label: string, ssh_alias: string, remote_path?: string}  $project
     * @return array<string, mixed>
     */
    private function addProject(array $config, array $project): array
    {
        $entry = [
            'remotePath' => $project['remote_path'] ?? '',
            'label' => $project['label'],
        ];
        $connections = $this->remoteConnections($config);

        foreach ($connections as $connectionIndex => $connection) {
            if (($connection['sshAlias'] ?? null) !== $project['ssh_alias']) {
                continue;
            }

            $projects = $this->projects($connection);

            foreach ($projects as $projectIndex => $existingProject) {
                if (($existingProject['label'] ?? null) !== $project['label']) {
                    continue;
                }

                $projects[$projectIndex] = [...$existingProject, ...$entry];
                $connections[$connectionIndex] = [...$connection, 'projects' => $projects];

                return $this->withConnections($config, $connections);
            }

            $projects[] = $entry;
            $connections[$connectionIndex] = [...$connection, 'projects' => $projects];

            return $this->withConnections($config, $connections);
        }

        $connections[] = [
            'sshAlias' => $project['ssh_alias'],
            'projects' => [$entry],
        ];

        return $this->withConnections($config, $connections);
    }

    /**
     * @param  array<string, mixed>  $config
     * @param  array{label: string, ssh_alias: string, remote_path?: string}  $project
     * @return array{0: array<string, mixed>, 1: bool}
     */
    private function removeProject(array $config, array $project): array
    {
        $connections = $this->remoteConnections($config);

        foreach ($connections as $connectionIndex => $connection) {
            if (($connection['sshAlias'] ?? null) !== $project['ssh_alias']) {
                continue;
            }

            $projects = $this->projects($connection);

            foreach ($projects as $projectIndex => $existingProject) {
                if (($existingProject['label'] ?? null) !== $project['label']) {
                    continue;
                }

                unset($projects[$projectIndex]);
                $connections[$connectionIndex] = [
                    ...$connection,
                    'projects' => array_values($projects),
                ];
                $config['remoteConnections'] = $connections;

                return [$config, true];
            }
        }

        return [$config, false];
    }

    /**
     * @param  array<string, mixed>  $config
     * @param  list<array<string, mixed>>  $connections
     * @return array<string, mixed>
     */
    private function withConnections(array $config, array $connections): array
    {
        $config['version'] ??= 1;
        $config['remoteConnections'] = $connections;

        return $config;
    }

    /**
     * @param  array<string, mixed>  $config
     * @return list<array<string, mixed>>
     *
     * @mago-expect analysis:mixed-assignment
     */
    private function remoteConnections(array $config): array
    {
        $connections = $config['remoteConnections'] ?? [];

        if (! is_array($connections)) {
            return [];
        }

        $normalized = [];

        foreach ($connections as $connection) {
            if (! is_array($connection)) {
                continue;
            }

            $normalizedConnection = [];

            foreach ($connection as $key => $value) {
                if (! is_string($key)) {
                    continue;
                }

                $normalizedConnection[$key] = $value;
            }

            $normalized[] = $normalizedConnection;
        }

        return $normalized;
    }

    /**
     * @param  array<string, mixed>  $connection
     * @return list<array<string, mixed>>
     *
     * @mago-expect analysis:mixed-assignment
     */
    private function projects(array $connection): array
    {
        $projects = $connection['projects'] ?? [];

        if (! is_array($projects)) {
            return [];
        }

        $normalized = [];

        foreach ($projects as $project) {
            if (! is_array($project)) {
                continue;
            }

            $normalizedProject = [];

            foreach ($project as $key => $value) {
                if (! is_string($key)) {
                    continue;
                }

                $normalizedProject[$key] = $value;
            }

            $normalized[] = $normalizedProject;
        }

        return $normalized;
    }
}
