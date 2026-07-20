<?php

declare(strict_types=1);

namespace App\Services\AgentIde;

use App\Contracts\AgentIdeMessageAdapter;
use App\Models\AppInstance;
use App\Models\Node;
use App\Models\Project;
use App\Models\Workspace;
use App\Services\Apps\AppAgentIdeDefaults;
use App\Services\Workspaces\WorkspacePlacement;
use Orbit\Sdk\Laravel\GatewayApiException;

final readonly class AgentIdeMessageDelivery
{
    public function __construct(
        private AppAgentIdeDefaults $appAgentIdeDefaults,
        private AgentIdeAdapterRegistry $registry,
        private WorkspacePlacement $placement,
    ) {}

    /**
     * @return array{agent_ide: array<string, mixed>}
     */
    public function deliverToInstance(AppInstance $instance, string $message): array
    {
        $instance->loadMissing('project');
        $project = $instance->project;
        $node = $this->placement->nodeForInstance($instance);

        if (! $project instanceof Project) {
            throw new GatewayApiException(
                message: "Instance '{$instance->name}' not found or not visible.",
                errorCode: 'target_not_found',
                errorMeta: ['instance' => $instance->name],
            );
        }

        $selector = "{$project->name}.{$instance->name}";
        $adapter = $this->appAgentIdeDefaults->payloadFor($instance, $node);
        $adapterName = $adapter['effective_adapter'];

        if ($adapterName === null) {
            throw new GatewayApiException(
                message: "No Agent IDE adapter is configured for instance {$selector}.",
                errorCode: 'no_effective_adapter',
                errorMeta: ['project' => $project->name, 'instance' => $instance->name, 'workspace' => null],
            );
        }

        if (! $this->registry->isRegisteredAdapter($adapterName)) {
            throw new GatewayApiException(
                message: "Agent IDE adapter {$adapterName} is not registered.",
                errorCode: 'no_effective_adapter',
                errorMeta: [
                    'project' => $project->name,
                    'instance' => $instance->name,
                    'workspace' => null,
                    'adapter' => $adapterName,
                ],
            );
        }

        $adapterTarget = [
            'app' => $project->name,
            'workspace' => null,
            'node' => (string) $node?->name,
        ];
        $messageAdapter = $this->messageAdapter();
        $session = $messageAdapter->activeSession($adapterTarget, $adapterName);

        if ($session === null) {
            throw new GatewayApiException(
                message: "No active Agent IDE session found for instance {$selector}.",
                errorCode: 'no_active_session',
                errorMeta: [
                    'project' => $project->name,
                    'instance' => $instance->name,
                    'workspace' => null,
                    'adapter' => $adapterName,
                ],
            );
        }

        try {
            $messageAdapter->deliver($adapterTarget, $adapterName, $session, $message);
        } catch (GatewayApiException $exception) {
            throw $this->publicException($exception, $project, $instance);
        }

        return [
            'agent_ide' => [
                'adapter' => $adapterName,
                'source' => $adapter['source'],
                'target' => $this->publicTarget($project, $instance, null, $node),
                'session' => $session,
                'delivery' => [
                    'status' => 'sent',
                    'message_bytes' => strlen($message),
                    'input' => 'argument',
                ],
            ],
        ];
    }

    /**
     * @return array{agent_ide: array<string, mixed>}
     */
    public function deliverToWorkspace(string $selector, string $message): array
    {
        $workspace = $this->resolveWorkspace($selector);

        if (! $workspace instanceof Workspace) {
            throw new GatewayApiException(
                message: "Workspace '{$selector}' not found or not visible.",
                errorCode: 'target_not_found',
                errorMeta: ['workspace' => $selector],
            );
        }

        $workspace->loadMissing(['project', 'appInstance']);
        $project = $workspace->project;
        $instance = $workspace->appInstance;

        if (! $project instanceof Project || ! $instance instanceof AppInstance) {
            throw new GatewayApiException(
                message: "Workspace '{$selector}' not found or not visible.",
                errorCode: 'target_not_found',
                errorMeta: ['workspace' => $selector],
            );
        }

        $node = $this->placement->nodeForInstance($instance);
        $adapter = $this->workspaceAdapterPayload($workspace, $instance, $node);
        $adapterName = $adapter['effective_adapter'];

        if ($adapterName === null) {
            throw new GatewayApiException(
                message: "No Agent IDE adapter is configured for workspace {$workspace->name}.",
                errorCode: 'no_effective_adapter',
                errorMeta: [
                    'project' => $project->name,
                    'instance' => $instance->name,
                    'workspace' => $workspace->name,
                ],
            );
        }

        if (! $this->registry->isRegisteredAdapter($adapterName)) {
            throw new GatewayApiException(
                message: "Agent IDE adapter {$adapterName} is not registered.",
                errorCode: 'no_effective_adapter',
                errorMeta: [
                    'project' => $project->name,
                    'instance' => $instance->name,
                    'workspace' => $workspace->name,
                    'adapter' => $adapterName,
                ],
            );
        }

        $adapterTarget = [
            'app' => $project->name,
            'workspace' => $workspace->name,
            'node' => (string) $node?->name,
        ];
        $messageAdapter = $this->messageAdapter();
        $session = $messageAdapter->activeSession($adapterTarget, $adapterName);

        if ($session === null) {
            throw new GatewayApiException(
                message: "No active Agent IDE session found for workspace {$workspace->name}.",
                errorCode: 'no_active_session',
                errorMeta: [
                    'project' => $project->name,
                    'instance' => $instance->name,
                    'workspace' => $workspace->name,
                    'adapter' => $adapterName,
                ],
            );
        }

        try {
            $messageAdapter->deliver($adapterTarget, $adapterName, $session, $message);
        } catch (GatewayApiException $exception) {
            throw $this->publicException($exception, $project, $instance, $workspace);
        }

        return [
            'agent_ide' => [
                'adapter' => $adapterName,
                'source' => $adapter['source'],
                'target' => $this->publicTarget($project, $instance, $workspace, $node),
                'session' => $session,
                'delivery' => [
                    'status' => 'sent',
                    'message_bytes' => strlen($message),
                    'input' => 'argument',
                ],
            ],
        ];
    }

    private function resolveWorkspace(string $selector): ?Workspace
    {
        $matches = Workspace::query()
            ->with(['project', 'appInstance'])
            ->where('name', $selector)
            ->get();

        return $matches->count() === 1 ? $matches->first() : null;
    }

    /**
     * @return array{source: string, effective_adapter: string|null}
     */
    private function workspaceAdapterPayload(Workspace $workspace, AppInstance $instance, ?Node $node): array
    {
        if (is_string($workspace->agent_ide) && $workspace->agent_ide !== '') {
            return [
                'source' => 'workspace',
                'effective_adapter' => $workspace->agent_ide === 'none' ? null : $workspace->agent_ide,
            ];
        }

        $adapter = $this->appAgentIdeDefaults->payloadFor($instance, $node);

        return [
            'source' => $adapter['source'],
            'effective_adapter' => $adapter['effective_adapter'],
        ];
    }

    /**
     * @return array{project: string, instance: string, workspace: string|null, node: string}
     */
    private function publicTarget(
        Project $project,
        AppInstance $instance,
        ?Workspace $workspace,
        ?Node $node,
    ): array {
        return [
            'project' => $project->name,
            'instance' => $instance->name,
            'workspace' => $workspace?->name,
            'node' => (string) $node?->name,
        ];
    }

    private function publicException(
        GatewayApiException $exception,
        Project $project,
        AppInstance $instance,
        ?Workspace $workspace = null,
    ): GatewayApiException {
        $meta = $exception->errorMeta();
        unset($meta['app'], $meta['app_instance']);

        return new GatewayApiException(
            message: $exception->getMessage(),
            errorCode: $exception->errorCode(),
            errorMeta: array_merge([
                'project' => $project->name,
                'instance' => $instance->name,
                'workspace' => $workspace?->name,
            ], $meta),
            errorData: $exception->errorData(),
            previous: $exception,
        );
    }

    private function messageAdapter(): AgentIdeMessageAdapter
    {
        return app()->bound(AgentIdeMessageAdapter::class)
            ? app(AgentIdeMessageAdapter::class)
            : new NullAgentIdeMessageAdapter;
    }
}
