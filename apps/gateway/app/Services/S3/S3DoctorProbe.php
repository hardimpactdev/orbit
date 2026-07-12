<?php

declare(strict_types=1);

namespace App\Services\S3;

use App\Data\Doctor\DriftEntry;
use App\Data\Nodes\RoleSettings\S3RoleSettings;
use App\Enums\DriftKind;
use App\Models\Node;
use App\Models\NodeRoleAssignment;
use App\Models\NodeTool;
use InvalidArgumentException;

/**
 * Provides node-family and tool-family doctor drift checks for the `s3` role.
 *
 * Node family owns:
 *  - WireGuard address presence (node.s3.wireguard_missing)
 *  - data_path setting validity (node.s3_data_path_invalid)
 *
 * Tool family owns:
 *  - SeaweedFS tool row existence (tool.seaweedfs.row_missing)
 *  - SeaweedFS credential completeness (tool.seaweedfs.credentials_missing)
 *
 * Concrete SeaweedFS runtime state and bind posture belong to the canonical
 * `seaweedfs` process row and are probed by the process doctor family.
 */
final readonly class S3DoctorProbe
{
    /**
     * Node-family drift checks for an active s3 role assignment.
     *
     * @return list<DriftEntry>
     */
    public function nodeDrift(Node $node, NodeRoleAssignment $assignment): array
    {
        $drift = [];

        $drift = array_merge($drift, $this->checkWireGuardAddress($node, $assignment));
        $drift = array_merge($drift, $this->checkDataPath($node, $assignment));

        return $drift;
    }

    /**
     * Tool-family drift checks for an active s3 role assignment.
     *
     * @return list<DriftEntry>
     */
    public function toolDrift(Node $node, NodeRoleAssignment $assignment): array
    {
        $drift = [];

        $seaweedfsTool = $this->seaweedfsToolFor($node);

        if (! $seaweedfsTool instanceof NodeTool) {
            $drift[] = new DriftEntry(
                family: 'tool',
                key: 'tool.seaweedfs.row_missing',
                kind: DriftKind::Missing,
                summary: "No seaweedfs tool row exists on s3 node {$node->name}.",
                detail: [
                    'role' => $assignment->role,
                    'node' => $node->name,
                ],
            );

            return $drift;
        }

        return array_merge($drift, $this->checkCredentials($node, $assignment, $seaweedfsTool));
    }

    /**
     * @return list<DriftEntry>
     */
    private function checkWireGuardAddress(Node $node, NodeRoleAssignment $assignment): array
    {
        $address = trim((string) $node->wireguard_address);

        if ($address !== '') {
            return [];
        }

        return [
            new DriftEntry(
                family: 'node',
                key: 'node.s3.wireguard_missing',
                kind: DriftKind::Missing,
                summary: "Active s3 role node {$node->name} has a missing or empty WireGuard address.",
                detail: [
                    'role' => $assignment->role,
                    'node' => $node->name,
                ],
            ),
        ];
    }

    /**
     * @return list<DriftEntry>
     */
    private function checkDataPath(Node $node, NodeRoleAssignment $assignment): array
    {
        $settings = is_array($assignment->settings) ? $assignment->settings : [];

        try {
            S3RoleSettings::fromArray($settings);

            return [];
        } catch (InvalidArgumentException) {
            return [
                new DriftEntry(
                    family: 'node',
                    key: 'node.s3_data_path_invalid',
                    kind: DriftKind::Missing,
                    summary: "Active s3 role assignment on node {$node->name} has a missing, relative, or invalid data_path setting.",
                    detail: [
                        'role' => $assignment->role,
                        'node' => $node->name,
                        'data_path' => $settings['data_path'] ?? null,
                    ],
                ),
            ];
        }
    }

    /**
     * @return list<DriftEntry>
     */
    private function checkCredentials(Node $node, NodeRoleAssignment $assignment, NodeTool $seaweedfsTool): array
    {
        if ($this->hasCompleteCredentials($seaweedfsTool)) {
            return [];
        }

        return [
            new DriftEntry(
                family: 'tool',
                key: 'tool.seaweedfs.credentials_missing',
                kind: DriftKind::Missing,
                summary: "SeaweedFS tool row on node {$node->name} is missing service-level credentials.",
                detail: [
                    'role' => $assignment->role,
                    'node' => $node->name,
                    'tool' => 'seaweedfs',
                ],
            ),
        ];
    }

    private function hasCompleteCredentials(NodeTool $seaweedfsTool): bool
    {
        $credentials = $seaweedfsTool->credentials;

        if (! is_array($credentials)) {
            return false;
        }

        $fields = $credentials['fields'] ?? null;

        if (! is_array($fields)) {
            return false;
        }

        $accessKeyId = $fields['access_key_id'] ?? null;
        $secretAccessKey = $fields['secret_access_key'] ?? null;

        return is_string($accessKeyId) && $accessKeyId !== '' && is_string($secretAccessKey) && $secretAccessKey !== '';
    }

    private function seaweedfsToolFor(Node $node): ?NodeTool
    {
        return NodeTool::query()
            ->where('node_id', $node->id)
            ->where('name', 'seaweedfs')
            ->first();
    }
}
