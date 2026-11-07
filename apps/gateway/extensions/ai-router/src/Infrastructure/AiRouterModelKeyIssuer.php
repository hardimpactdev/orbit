<?php

declare(strict_types=1);

namespace Orbit\Extensions\AiRouter\Infrastructure;

use App\Domain\Shared\ResourceOperationException;
use App\Domain\TaskVms\ModelKeyIssuer;
use App\Domain\TaskVms\TaskVmConfig;
use App\Domain\TaskVms\TaskVmError;
use App\Domain\TaskVms\TaskVmException;
use App\Domain\TaskVms\TaskVmSettings;
use Orbit\Extensions\AiRouter\Domain\AiRouterSecrets;
use Orbit\Extensions\AiRouter\Domain\AiRouterState;

/** Supplies existing task VM model-router material from the CLIProxyAPI of the ai-router extension, with its management key and one anchor key. */
final readonly class AiRouterModelKeyIssuer implements ModelKeyIssuer
{
    public function __construct(
        private TaskVmSettings $settings,
        private AiRouterState $proxy,
        private AiRouterSecrets $secrets,
    ) {}

    public function assertReady(): void
    {
        $key = $this->proxy->managementKey();
        if ($key === null || $key === '') {
            throw new ResourceOperationException(
                'ai_router.disabled',
                'Set up the ai-router extension with ai-router:setup first. Task VMs get their model keys from its CLIProxyAPI.',
                409,
            );
        }
    }

    public function origin(): string
    {
        $origin = $this->settings->modelProxyOrigin ?? throw TaskVmConfig::invalid('model_proxy_origin is required to prepare a task VM.');
        if (rtrim((string) $this->proxy->routerUrl(), '/') !== $origin) {
            throw TaskVmConfig::invalid('model_proxy_origin must be the CLIProxyAPI URL of the ai-router extension.');
        }

        return $origin;
    }

    public function ownerNodeId(): int
    {
        return $this->proxy->nodeId() ?? throw new TaskVmException(TaskVmError::ModelKeyFailed, 'The model router has no owning Node.', 409);
    }

    public function privateMaterial(string $origin, int $ownerNodeId): array
    {
        if ($ownerNodeId !== $this->ownerNodeId()) {
            throw new ResourceOperationException('operation.execution_forbidden', 'The admitted Node does not own these model-router credentials.', 422);
        }

        return ['management_key' => $this->managementKey(), 'anchor_key' => $this->secrets->anchor($origin)];
    }

    private function managementKey(): string
    {
        $key = $this->proxy->managementKey();
        if ($key === null || $key === '') {
            throw new TaskVmException(TaskVmError::ModelKeyFailed, 'Set up the ai-router extension: it holds the CLIProxyAPI management key.', 409);
        }

        return $key;
    }
}
