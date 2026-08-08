<?php

declare(strict_types=1);

namespace App\Services\Nodes;

use App\Data\Doctor\DriftEntry;
use App\Enums\DriftKind;
use App\Models\Node;
use App\Services\Gateway\GatewayConfigRootOwnershipRepairer;
use App\Services\Gateway\GatewayHostPathPrefix;
use App\Services\Nodes\Roles\NodeRoleAssignments;
use Illuminate\Support\Facades\File;

/**
 * Verifies that the gateway host's Orbit runtime user can actually read the CLI
 * config it resolves at `$HOME/.config/orbit/config.json`.
 *
 * This is the unstated precondition of the whole force_remote_host lane. The
 * gateway dispatches host-owned work by running the host CLI as that user, and
 * `forceRemoteHostScript()` deliberately unsets `ORBIT_CONFIG_PATH`, so the CLI
 * resolves config from the runtime user's home. When that file is unreadable the
 * CLI cannot reach the gateway API, operation-token verification cannot happen,
 * and every force_remote_host operation fails — while node Doctor otherwise
 * reports the gateway healthy.
 *
 * The check reads the host view under `ORBIT_HOST_PATH_PREFIX` rather than
 * exercising force_remote_host, so it stays discriminating: it reports the
 * unreadable config itself instead of an opaque downstream transport failure.
 *
 * @mago-expect lint:cyclomatic-complexity
 */
final readonly class GatewayCliConfigAccessProbe
{
    private const string ConfigFile = 'config.json';

    public function __construct(
        private GatewayConfigRootOwnershipRepairer $ownershipRepairer = new GatewayConfigRootOwnershipRepairer,
        private NodeRoleAssignments $roleAssignments = new NodeRoleAssignments,
    ) {}

    /**
     * @return list<DriftEntry>
     */
    public function diff(Node $node): array
    {
        $paths = $this->hostPaths($node);

        if ($paths === null) {
            return [];
        }

        [$hostConfigRoot, $configRoot] = $paths;

        if (! File::isDirectory($hostConfigRoot)) {
            return [];
        }

        $owner = $this->canonicalOwner($node);

        if ($owner === null) {
            return [];
        }

        [$ownerUid, $ownerGid] = $owner;

        $unreadable = $this->firstUnreadablePath($hostConfigRoot, $configRoot, $ownerUid, $ownerGid);

        if ($unreadable === null) {
            return [];
        }

        return [
            new DriftEntry(
                family: 'node',
                key: 'node.gateway_cli_config_unreadable',
                kind: DriftKind::Divergent,
                summary: "Gateway host Orbit user cannot read its CLI config at {$unreadable['path']}.",
                detail: [
                    'path' => $unreadable['path'],
                    'expected_owner' => "{$ownerUid}:{$ownerGid}",
                    'actual_owner' => "{$unreadable['uid']}:{$unreadable['gid']}",
                    'mode' => decoct($unreadable['mode']),
                    'requires' => $unreadable['directory'] ? 'traverse' : 'read',
                ],
            ),
        ];
    }

    public function restore(Node $node, DriftEntry $entry): void
    {
        $paths = $this->hostPaths($node);

        if ($paths === null) {
            return;
        }

        [, $configRoot] = $paths;

        // Chown the writable container path. The host view under
        // ORBIT_HOST_PATH_PREFIX is mounted read-only in production, so using it
        // as the target fails with EROFS; repair() prepends the prefix itself
        // when resolving canonical ownership.
        $this->ownershipRepairer->repair($configRoot);
    }

    /**
     * @return array{path: string, uid: int, gid: int, mode: int, directory: bool}|null
     */
    private function firstUnreadablePath(
        string $hostConfigRoot,
        string $configRoot,
        int $ownerUid,
        int $ownerGid,
    ): ?array {
        $candidates = [
            [$hostConfigRoot, $configRoot, true],
            ["{$hostConfigRoot}/".self::ConfigFile, "{$configRoot}/".self::ConfigFile, false],
        ];

        foreach ($candidates as [$hostPath, $reportedPath, $isDirectory]) {
            if (! File::exists($hostPath)) {
                continue;
            }

            $uid = fileowner($hostPath);
            $gid = filegroup($hostPath);
            $mode = fileperms($hostPath);

            if (! is_int($uid) || ! is_int($gid) || ! is_int($mode)) {
                continue;
            }

            if (! PosixReadAccess::permits([$ownerUid, $ownerGid], [$uid, $gid], $mode & 0o777, $isDirectory)) {
                return [
                    'path' => $reportedPath,
                    'uid' => $uid,
                    'gid' => $gid,
                    'mode' => $mode & 0o777,
                    'directory' => $isDirectory,
                ];
            }
        }

        return null;
    }

    /**
     * The canonical host owner, taken from the runtime user's home directory as
     * the host sees it — the same source the container entrypoint uses.
     *
     * @return array{0: int, 1: int}|null
     */
    private function canonicalOwner(Node $node): ?array
    {
        $prefix = GatewayHostPathPrefix::resolve();

        if ($prefix === null) {
            return null;
        }

        $home = $prefix.$this->hostHome($node);

        if (! File::isDirectory($home)) {
            return null;
        }

        $uid = fileowner($home);
        $gid = filegroup($home);

        return is_int($uid) && is_int($gid) ? [$uid, $gid] : null;
    }

    /**
     * @return array{0: string, 1: string}|null
     */
    private function hostPaths(Node $node): ?array
    {
        if (! $this->roleAssignments->nodeIsGateway($node)) {
            return null;
        }

        $prefix = GatewayHostPathPrefix::resolve();

        if ($prefix === null) {
            return null;
        }

        $configRoot = $this->hostHome($node).'/.config/orbit';

        return [$prefix.$configRoot, $configRoot];
    }

    private function hostHome(Node $node): string
    {
        return NodeHostPaths::homeDirectoryFor($node->platform, $node->user);
    }
}
