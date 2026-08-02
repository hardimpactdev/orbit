<?php

declare(strict_types=1);

namespace App\Services\Tools;

final readonly class ToolReconfigurer
{
    public function __construct(
        private ToolCatalog $catalog,
        private ToolRegistry $registry,
        private ToolScriptDispatcher $toolScriptDispatcher,
        private RelatedToolProcessRemover $relatedProcessRemover,
    ) {}

    /**
     * @return array<string, mixed>|ToolRegistryFailure
     */
    public function reconfigure(
        string $tool,
        ?string $node = null,
        ?string $app = null,
        array $config = [],
        ?string $password = null,
    ): array|ToolRegistryFailure {
        if (! $this->catalog->supports($tool)) {
            return ToolRegistryFailure::unsupportedAction($tool, 'reconfigure');
        }

        if (! $this->catalog->hasCapability($tool, 'reconfigure')) {
            return ToolRegistryFailure::unsupportedAction($tool, 'reconfigure');
        }

        $model = $this->registry->show(tool: $tool, node: $node, app: $app);

        if ($model instanceof ToolRegistryFailure) {
            return $model;
        }

        $model->loadMissing('node');

        if ($model->node === null) {
            return ToolRegistryFailure::remoteActionFailed($tool, '', 'reconfigure', 1, 'Target node is missing.');
        }

        $mergedConfig = [];

        foreach (array_merge(is_array($model->config) ? $model->config : [], $config) as $key => $value) {
            $mergedConfig[(string) $key] = $value;
        }

        if ($password !== null) {
            $mergedConfig['password'] = $password;
        }

        /** @var array<string, mixed> $mergedConfig */
        $scriptConfig = $this->catalog->scriptConfig($tool, $model->node, $mergedConfig);
        $script = $this->catalog->reconfigureScript($tool, $scriptConfig);

        if ($script === null) {
            return ToolRegistryFailure::unsupportedAction($tool, 'reconfigure');
        }

        $model->config = $mergedConfig;

        if ($password !== null) {
            $existingCreds = is_array($model->credentials) ? $model->credentials : [];
            $model->credentials = array_merge($existingCreds, ['password' => $password]);
        }

        $model->save();

        $result = $this->toolScriptDispatcher->runForRegistry(
            node: $model->node,
            tool: $tool,
            action: 'reconfigure',
            script: $script,
        );

        if ($result instanceof ToolRegistryFailure) {
            return $result;
        }

        if (! $result->successful()) {
            return ToolRegistryFailure::remoteActionFailed(
                $tool,
                $model->node->name,
                'reconfigure',
                $result->exitCode,
                trim($result->stderr),
            );
        }

        // Related processes load credentials/env at unit start; restart so
        // reconfigure file/env changes (e.g. Hermes public URL) take effect.
        $process = $this->relatedProcessRemover->restartIfPresent($model->node, $tool);

        if ($process instanceof ToolRegistryFailure) {
            return $process;
        }

        $payload = [
            'name' => $tool,
            'node' => $model->node->name,
            'action' => 'reconfigured',
        ];

        if ($process !== null) {
            $payload['process'] = $process;
        }

        return $payload;
    }
}
