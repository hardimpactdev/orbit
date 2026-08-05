<?php

declare(strict_types=1);

namespace App\Services\Tools;

use App\Data\RemoteShell\RemoteShellResult;
use App\Models\Node;
use App\Models\NodeTool;
use SensitiveParameter;

/**
 * @mago-expect lint:cyclomatic-complexity
 */
final readonly class ToolReconfigurer
{
    public function __construct(
        private ToolCatalog $catalog,
        private ToolRegistry $registry,
        private ToolScriptDispatcher $toolScriptDispatcher,
        private RelatedToolProcessRemover $relatedProcessRemover,
    ) {}

    /**
     * @param  array<array-key, mixed>  $config
     * @return array<string, mixed>|ToolRegistryFailure
     */
    public function reconfigure(
        string $tool,
        ?string $node = null,
        ?string $app = null,
        array $config = [],
        #[SensitiveParameter]
        ?string $password = null,
    ): array|ToolRegistryFailure {
        if (! $this->catalog->supports($tool) || ! $this->catalog->hasCapability($tool, 'reconfigure')) {
            return ToolRegistryFailure::unsupportedAction($tool, 'reconfigure');
        }

        $model = $this->registry->show(tool: $tool, node: $node, app: $app);

        if ($model instanceof ToolRegistryFailure) {
            return $model;
        }

        $model->loadMissing('node');
        $targetNode = $model->node;

        if (! $targetNode instanceof Node) {
            return ToolRegistryFailure::remoteActionFailed($tool, '', 'reconfigure', 1, 'Target node is missing.');
        }

        $mergedConfig = $this->mergedConfig($model, $config, $password);
        $scriptConfig = $this->catalog->scriptConfig($tool, $targetNode, $mergedConfig);
        $script = $this->catalog->reconfigureScript($tool, $scriptConfig);

        if ($script === null) {
            return ToolRegistryFailure::unsupportedAction($tool, 'reconfigure');
        }

        $this->persistReconfigureIntent($model, $mergedConfig, $password);

        $result = $this->toolScriptDispatcher->runForRegistry(
            node: $targetNode,
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
                $targetNode->name,
                'reconfigure',
                $result->exitCode,
                trim($result->stderr),
            );
        }

        $credentialsRefresh = $this->refreshCredentialsFromScript(
            tool: $tool,
            model: $model,
            targetNode: $targetNode,
            scriptConfig: $scriptConfig,
        );

        if ($credentialsRefresh instanceof ToolRegistryFailure) {
            return $credentialsRefresh;
        }

        return $this->completeReconfigure($tool, $targetNode);
    }

    /**
     * @param  array<array-key, mixed>  $config
     * @return array<string, mixed>
     */
    private function mergedConfig(
        NodeTool $model,
        array $config,
        #[SensitiveParameter]
        ?string $password,
    ): array {
        $mergedConfig = [];
        $existingConfig = is_array($model->config) ? $model->config : [];

        foreach (array_merge($existingConfig, $config) as $key => $value) {
            $mergedConfig[(string) $key] = $value;
        }

        if ($password !== null) {
            $mergedConfig['password'] = $password;
        }

        return $mergedConfig;
    }

    /**
     * @param  array<string, mixed>  $mergedConfig
     */
    private function persistReconfigureIntent(
        NodeTool $model,
        array $mergedConfig,
        #[SensitiveParameter]
        ?string $password,
    ): void {
        $model->config = $mergedConfig;

        if ($password !== null) {
            $existingCreds = is_array($model->credentials) ? $model->credentials : [];
            $model->credentials = array_merge($existingCreds, ['password' => $password]);
        }

        $model->save();
    }

    /**
     * When the catalog provides a credentialsScript, re-run it after a successful
     * reconfigure and replace stored credential fields. Never put credential values
     * into the reconfigure success payload or failure messages.
     *
     * @param  array<string, mixed>  $scriptConfig
     */
    private function refreshCredentialsFromScript(
        string $tool,
        NodeTool $model,
        Node $targetNode,
        array $scriptConfig,
    ): ?ToolRegistryFailure {
        $credentialsScript = $this->catalog->credentialsScript($tool, $scriptConfig);

        if ($credentialsScript === null) {
            return null;
        }

        $credResult = $this->toolScriptDispatcher->runForRegistry(
            node: $targetNode,
            tool: $tool,
            action: 'credentials',
            script: $credentialsScript,
        );

        if ($credResult instanceof ToolRegistryFailure) {
            return $credResult;
        }

        if (! $credResult->successful()) {
            return $this->credentialsScriptFailed($tool, $targetNode, $credResult);
        }

        $fields = $this->parsedCredentialFields($credResult->stdout);

        if ($fields === null) {
            return ToolRegistryFailure::remoteActionFailed(
                $tool,
                $targetNode->name,
                'credentials',
                1,
                'Credentials script did not return a JSON object.',
            );
        }

        $model->credentials = ['fields' => $fields];
        $model->save();

        return null;
    }

    private function credentialsScriptFailed(
        string $tool,
        Node $targetNode,
        RemoteShellResult $credResult,
    ): ToolRegistryFailure {
        $stderr = trim($credResult->stderr);

        return ToolRegistryFailure::remoteActionFailed(
            $tool,
            $targetNode->name,
            'credentials',
            $credResult->exitCode,
            $stderr !== '' ? $stderr : 'Credentials script failed.',
        );
    }

    /**
     * @return array<string, mixed>|null
     */
    private function parsedCredentialFields(string $stdout): ?array
    {
        /** @var mixed $parsed */
        $parsed = json_decode(trim($stdout), associative: true);

        if (! is_array($parsed) || $parsed === [] || array_is_list($parsed)) {
            return null;
        }

        /** @var array<string, mixed> $parsed */
        return $parsed;
    }

    /**
     * Related processes load credentials/env at unit start; restart so
     * reconfigure file/env changes (e.g. Hermes public URL) take effect.
     *
     * @return array<string, mixed>|ToolRegistryFailure
     */
    private function completeReconfigure(string $tool, Node $targetNode): array|ToolRegistryFailure
    {
        $process = $this->relatedProcessRemover->restartIfPresent($targetNode, $tool);

        if ($process instanceof ToolRegistryFailure) {
            return $process;
        }

        $payload = [
            'name' => $tool,
            'node' => $targetNode->name,
            'action' => 'reconfigured',
        ];

        if ($process !== null) {
            $payload['process'] = $process;
        }

        return $payload;
    }
}
