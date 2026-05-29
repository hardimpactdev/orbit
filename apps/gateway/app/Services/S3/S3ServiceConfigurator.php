<?php

declare(strict_types=1);

namespace App\Services\S3;

use App\Data\Nodes\RoleSettings\S3RoleSettings;
use App\Models\Node;
use App\Models\NodeRoleAssignment;
use App\Models\NodeTool;

/**
 * Applies RustFS runtime config and tool metadata for an S3-role node.
 *
 * Credential strategy:
 *  - Delegates credential resolution to S3ServiceConfigResolver, which
 *    preserves existing credentials when present.
 *  - Writes credentials back to the rustfs tool row only when they were not
 *    already stored — never overwrites an existing access_key_id or
 *    secret_access_key.
 */
final readonly class S3ServiceConfigurator
{
    public function __construct(
        private S3ServiceConfigResolver $configResolver,
        private S3RuntimeContainerRenderer $containerRenderer,
    ) {}

    /**
     * Resolve the S3 service config, persist/update the rustfs NodeTool row,
     * and render the RustFS runtime container.
     *
     * Returns a value object containing both the resolved service config and
     * the rendered runtime container, so that convergence and routing tasks
     * can use them without re-resolving.
     */
    public function configure(Node $node, NodeRoleAssignment $assignment): S3ServiceConfiguratorResult
    {
        $rustfsTool = $this->findRustfsTool($node);

        $credentialsAlreadyStored = $this->hasStoredCredentials($rustfsTool);

        $serviceConfig = $this->configResolver->resolve($node, $assignment, $rustfsTool);

        $settings = S3RoleSettings::fromArray(
            is_array($assignment->settings) ? $assignment->settings : [],
        );

        $runtimeContainer = $this->containerRenderer->render($node, $settings);

        $rustfsTool = $this->persistRustfsTool($node, $serviceConfig, $runtimeContainer);

        if (! $credentialsAlreadyStored) {
            $this->writeCredentials($rustfsTool, $serviceConfig);
        }

        return new S3ServiceConfiguratorResult(
            serviceConfig: $serviceConfig,
            runtimeContainer: $runtimeContainer,
            rustfsTool: $rustfsTool,
        );
    }

    /**
     * Remove the rustfs tool row. The data path on the host is role-owned
     * persistent data and is never touched here — host purge is handled
     * outside the configurator by provisioning scripts.
     */
    public function remove(Node $node, bool $purgeData = false): void
    {
        NodeTool::query()
            ->where('node_id', $node->id)
            ->where('name', 'rustfs')
            ->delete();
    }

    private function findRustfsTool(Node $node): ?NodeTool
    {
        /** @var NodeTool|null */
        return NodeTool::query()
            ->where('node_id', $node->id)
            ->where('name', 'rustfs')
            ->first();
    }

    /**
     * Returns true when the rustfs tool row already has a complete credentials
     * fields array — both access_key_id and secret_access_key are non-empty.
     * This is the same predicate as S3ServiceConfigResolver::extractStoredCredentials.
     */
    private function hasStoredCredentials(?NodeTool $rustfsTool): bool
    {
        if ($rustfsTool === null) {
            return false;
        }

        $raw = $rustfsTool->credentials;

        if (! is_array($raw)) {
            return false;
        }

        $fields = $raw['fields'] ?? null;

        if (! is_array($fields)) {
            return false;
        }

        $accessKeyId = $fields['access_key_id'] ?? null;
        $secretAccessKey = $fields['secret_access_key'] ?? null;

        return is_string($accessKeyId) && $accessKeyId !== ''
            && is_string($secretAccessKey) && $secretAccessKey !== '';
    }

    private function persistRustfsTool(Node $node, S3ServiceConfig $serviceConfig, S3RuntimeContainer $runtimeContainer): NodeTool
    {
        $toolConfig = [
            'data_path' => $serviceConfig->dataPath,
            'service_host' => S3ServiceConfig::ServiceHost,
            'backend_host' => "{$serviceConfig->nodeName}.s3.orbit",
            'container_name' => $runtimeContainer->name(),
            'runtime' => 'docker-container',
            'public_hosts' => $serviceConfig->publicHosts,
        ];

        /** @var NodeTool */
        return NodeTool::query()->updateOrCreate(
            [
                'node_id' => $node->id,
                'name' => 'rustfs',
            ],
            [
                'expected_state' => 'running',
                'expected_version' => null,
                'config' => $toolConfig,
            ],
        );
    }

    /**
     * Write the resolved credentials to the rustfs tool row. Only called when
     * credentials were not already stored on the row.
     *
     * Uses the established array_merge pattern: merges into existing top-level
     * credentials structure (if any) and sets the fields key without disturbing
     * other credential metadata.
     */
    private function writeCredentials(NodeTool $rustfsTool, S3ServiceConfig $serviceConfig): void
    {
        $existing = is_array($rustfsTool->credentials) ? $rustfsTool->credentials : [];

        $rustfsTool->credentials = array_merge($existing, [
            'fields' => [
                'access_key_id' => $serviceConfig->accessKeyId,
                'secret_access_key' => $serviceConfig->secretAccessKey,
                'region' => S3ServiceConfig::Region,
                'endpoint' => S3ServiceConfig::ServiceEndpoint,
                'bucket_style' => 'path',
            ],
        ]);

        $rustfsTool->save();
    }
}
