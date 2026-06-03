<?php

declare(strict_types=1);

namespace App\E2E\Support;

final readonly class E2EProvisionCheckpointManifest
{
    public const int SchemaVersion = 1;

    /**
     * @param  array<string, mixed>  $fingerprint
     * @param  list<array{role: string, name: string, snapshot: string}>  $checkpoints
     * @return array<string, mixed>
     */
    public static function create(E2ETopologyKind $kind, array $fingerprint, array $checkpoints, bool $complete): array
    {
        $checkpointMap = [];

        foreach ($checkpoints as $checkpoint) {
            $role = $checkpoint['role'];
            $checkpointMap[$role] = [
                'role' => $role,
                'name' => $checkpoint['name'],
                'snapshot' => $checkpoint['snapshot'],
                'fingerprint' => $fingerprint['role_fingerprints'][$role] ?? null,
                'created_at' => gmdate(DATE_ATOM),
            ];
        }

        return [
            'schema_version' => self::SchemaVersion,
            'topology_kind' => $kind->value,
            'artifact_set' => E2ETopologyArtifactNamespace::artifactSet(),
            'complete' => $complete,
            'created_at' => gmdate(DATE_ATOM),
            'fingerprints' => $fingerprint['fingerprints'] ?? [],
            'role_dag' => $fingerprint['role_dag'] ?? [],
            'checkpoints' => $checkpointMap,
        ];
    }

    /**
     * @param  array<string, mixed>|null  $manifest
     * @param  array<string, mixed>  $currentFingerprint
     * @param  \Closure(string, string): bool  $snapshotExists
     * @return list<string>
     */
    public static function validRoles(?array $manifest, array $currentFingerprint, \Closure $snapshotExists): array
    {
        return array_column(self::validCheckpoints($manifest, $currentFingerprint, $snapshotExists), 'role');
    }

    /**
     * @param  array<string, mixed>|null  $manifest
     * @param  array<string, mixed>  $currentFingerprint
     * @param  \Closure(string, string): bool  $snapshotExists
     * @return list<array{role: string, name: string, snapshot: string}>
     */
    public static function validCheckpoints(?array $manifest, array $currentFingerprint, \Closure $snapshotExists): array
    {
        if ($manifest === null) {
            return [];
        }

        if (($manifest['schema_version'] ?? null) !== self::SchemaVersion) {
            return [];
        }

        if (($manifest['topology_kind'] ?? null) !== ($currentFingerprint['topology_kind'] ?? null)) {
            return [];
        }

        if (($manifest['role_dag'] ?? null) !== ($currentFingerprint['role_dag'] ?? null)) {
            return [];
        }

        $roleFingerprints = $currentFingerprint['role_fingerprints'] ?? [];
        $checkpoints = $manifest['checkpoints'] ?? [];

        if (! is_array($roleFingerprints) || ! is_array($checkpoints)) {
            return [];
        }

        $valid = [];

        foreach ($checkpoints as $role => $checkpoint) {
            if (! is_string($role) || ! is_array($checkpoint)) {
                continue;
            }

            $currentRoleFingerprint = $roleFingerprints[$role] ?? null;

            if (! is_string($currentRoleFingerprint) || ($checkpoint['fingerprint'] ?? null) !== $currentRoleFingerprint) {
                continue;
            }

            $name = $checkpoint['name'] ?? null;
            $snapshot = $checkpoint['snapshot'] ?? null;

            if (! is_string($name) || ! is_string($snapshot)) {
                continue;
            }

            if (! $snapshotExists($name, $snapshot)) {
                continue;
            }

            $valid[] = [
                'role' => $role,
                'name' => $name,
                'snapshot' => $snapshot,
            ];
        }

        return $valid;
    }

    /**
     * @param  list<array{role: string, name: string, snapshot: string}>  $validCheckpoints
     * @return list<string>
     */
    public static function missingRoles(E2ETopologyKind $kind, array $validCheckpoints): array
    {
        $validRoles = array_column($validCheckpoints, 'role');

        return array_values(array_diff(IncusTopologyTemplate::rolesFor($kind), $validRoles));
    }

    /**
     * @param  array<string, mixed>|null  $manifest
     * @return list<array{role: string, name: string, snapshot: string}>
     */
    public static function checkpoints(?array $manifest): array
    {
        $checkpoints = $manifest['checkpoints'] ?? [];

        if (! is_array($checkpoints)) {
            return [];
        }

        $result = [];

        foreach ($checkpoints as $checkpoint) {
            if (! is_array($checkpoint)) {
                continue;
            }

            $role = $checkpoint['role'] ?? null;
            $name = $checkpoint['name'] ?? null;
            $snapshot = $checkpoint['snapshot'] ?? null;

            if (! is_string($role) || ! is_string($name) || ! is_string($snapshot)) {
                continue;
            }

            $result[] = [
                'role' => $role,
                'name' => $name,
                'snapshot' => $snapshot,
            ];
        }

        return $result;
    }
}
