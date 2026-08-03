<?php

declare(strict_types=1);

namespace App\Services\Tools;

use App\Models\Node;
use App\Models\NodeTool;

/**
 * @mago-expect lint:cyclomatic-complexity
 */
final readonly class ToolRemover
{
    /**
     * @mago-expect lint:excessive-parameter-list
     */
    public function __construct(
        private ToolCatalog $catalog,
        private ToolRegistry $registry,
        private ToolScriptDispatcher $toolScriptDispatcher,
        private StaleToolIntentRemover $staleIntentRemover,
        private RelatedToolProcessRemover $relatedProcessRemover,
        private LegacyOpenClawRuntimeCleanup $legacyOpenClawCleanup,
    ) {}

    /**
     * @return array<string, mixed>|ToolRegistryFailure
     */
    public function remove(string $tool, ?string $node = null, ?string $app = null): array|ToolRegistryFailure
    {
        // OpenClaw is no longer a first-party catalog tool, but residual runtime
        // may still exist after prior removals. Always use the removal-only
        // migration path for this exact tool slug.
        if ($this->legacyOpenClawCleanup->applies($tool)) {
            return $this->legacyOpenClawCleanup->remove(node: $node, app: $app);
        }

        $stored = $this->registry->findStored(tool: $tool, node: $node, app: $app);

        if ($this->isStaleStoredTool($tool, $stored)) {
            /** @var NodeTool $stored */
            return $this->staleIntentRemover->withRecord($tool, $stored);
        }

        $model = $this->registry->show(tool: $tool, node: $node, app: $app);

        if ($model instanceof ToolRegistryFailure) {
            return $this->staleIntentRemover->withoutRecord($tool, $node, $app) ?? $model;
        }

        $model->loadMissing('node');

        if ($model->node === null) {
            return ToolRegistryFailure::remoteActionFailed($tool, '', 'remove', 1, 'Target node is missing.');
        }

        if (! $this->catalog->supports($tool) || ! $this->catalog->supportsNode($tool, $model->node)) {
            return $this->staleIntentRemover->withRecord($tool, $model);
        }

        if (! $this->catalog->hasCapability($tool, 'remove')) {
            return ToolRegistryFailure::unsupportedAction($tool, 'remove');
        }

        return $this->removeInstalledTool($tool, $model);
    }

    private function isStaleStoredTool(string $tool, mixed $stored): bool
    {
        return (
            $stored instanceof NodeTool
            && $stored->node instanceof Node
            && (! $this->catalog->supports($tool) || ! $this->catalog->supportsNode($tool, $stored->node))
        );
    }

    /**
     * @return array<string, mixed>|ToolRegistryFailure
     */
    private function removeInstalledTool(string $tool, NodeTool $model): array|ToolRegistryFailure
    {
        /** @var Node $node */
        $node = $model->node;

        // Stop and delete the related process unit before the tool remove script
        // so a restarting systemd unit cannot race the binary/home cleanup.
        $process = $this->relatedProcessRemover->removeIfPresent($node, $tool);

        if ($process instanceof ToolRegistryFailure) {
            return $process;
        }

        $scriptResult = $this->runRemoveScript($tool, $node, is_array($model->config) ? $model->config : []);

        if ($scriptResult instanceof ToolRegistryFailure) {
            return $scriptResult;
        }

        $model->credentials = null;
        $model->save();
        $model->delete();

        // Drop tool-owned proxy intent for this tool after the NodeTool row is
        // gone so doctor/proxy cannot treat an orphan route as healthy owner.
        $routesRemoved = $this->staleIntentRemover->removeOwnedProxyRoutesFor($tool, $node);

        $payload = [
            'name' => $tool,
            'node' => $node->name,
        ];

        if ($routesRemoved > 0) {
            $payload['routes_removed'] = $routesRemoved;
        }

        if ($process !== null) {
            $payload['process'] = $process;

            // Surface process runtime-unit extras from RemoveProcess so operators
            // can doctor --family=process without silent drop.
            $warnings = $process['warnings'] ?? null;

            if (is_array($warnings) && $warnings !== []) {
                $payload['warnings'] = $warnings;
            }
        }

        return $payload;
    }

    /**
     * @param  array<array-key, mixed>  $config
     */
    private function runRemoveScript(string $tool, Node $node, array $config): ?ToolRegistryFailure
    {
        $script = $this->catalog->removeScript($tool, $config);

        if ($script === null) {
            return ToolRegistryFailure::unsupportedAction($tool, 'remove');
        }

        $result = $this->toolScriptDispatcher->runForRegistry(
            node: $node,
            tool: $tool,
            action: 'remove',
            script: $script,
        );

        if ($result instanceof ToolRegistryFailure) {
            return $result;
        }

        if (! $result->successful()) {
            return ToolRegistryFailure::remoteActionFailed(
                $tool,
                $node->name,
                'remove',
                $result->exitCode,
                trim($result->stderr),
            );
        }

        return null;
    }
}
