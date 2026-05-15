<?php

declare(strict_types=1);

namespace App\Services\Tools;

use App\Contracts\RemoteShell;
use App\Models\App;
use App\Models\Node;
use App\Models\NodeTool;

final readonly class ToolInstaller
{
    public function __construct(
        private ToolCatalog $catalog,
        private ToolRegistry $registry,
        private RemoteShell $remoteShell,
    ) {}

    /**
     * @return array<string, mixed>|ToolRegistryFailure
     */
    public function install(
        string $tool,
        ?string $node = null,
        ?string $app = null,
        string $expectedState = 'installed',
        array $config = [],
    ): array|ToolRegistryFailure {
        if (! $this->catalog->supports($tool)) {
            return ToolRegistryFailure::unsupportedAction($tool, 'install');
        }

        if (! $this->catalog->hasCapability($tool, 'install')) {
            return ToolRegistryFailure::unsupportedAction($tool, 'install');
        }

        $targetNode = $this->resolveTargetNode($node, $app);

        if ($targetNode instanceof ToolRegistryFailure) {
            return $targetNode;
        }

        $script = $this->catalog->installScript($tool, $config);

        if ($script === null) {
            return ToolRegistryFailure::unsupportedAction($tool, 'install');
        }

        $row = NodeTool::query()->updateOrCreate(
            [
                'node_id' => $targetNode->id,
                'name' => $tool,
            ],
            [
                'expected_version' => null,
                'expected_state' => $expectedState,
                'config' => $config === [] ? null : $config,
            ],
        );

        $result = $this->remoteShell->run($targetNode, $script, ['throw' => false]);

        if (! $result->successful()) {
            return ToolRegistryFailure::remoteActionFailed(
                $tool,
                $targetNode->name,
                'install',
                $result->exitCode,
                trim($result->stderr),
            );
        }

        $credentialsScript = $this->catalog->credentialsScript($tool, $config);

        if ($credentialsScript !== null) {
            $credResult = $this->remoteShell->run($targetNode, $credentialsScript, ['throw' => false]);

            if ($credResult->successful()) {
                $parsed = json_decode(trim($credResult->stdout), true);

                if (is_array($parsed)) {
                    $row->credentials = ['fields' => $parsed];
                    $row->save();
                }
            }
        }

        $row->refresh();

        return [
            'name' => $tool,
            'node' => $targetNode->name,
            'state' => $row->expected_state,
            'version' => $row->expected_version,
        ];
    }

    private function resolveTargetNode(?string $node, ?string $app): Node|ToolRegistryFailure
    {
        $validation = $this->registry->validateFilters($node, $app);

        if ($validation instanceof ToolRegistryFailure) {
            return $validation;
        }

        if ($node !== null) {
            $resolved = Node::query()
                ->where('name', $node)
                ->where('role', 'app')
                ->where('status', 'active')
                ->first();

            if ($resolved instanceof Node) {
                return $resolved;
            }
        }

        if ($app !== null) {
            $appModel = App::query()
                ->with('node')
                ->where(function ($query) use ($app): void {
                    $query->where('name', $app)
                        ->orWhere('domain', $app);
                })
                ->first();

            if ($appModel instanceof App && $appModel->node instanceof Node) {
                return $appModel->node;
            }
        }

        return ToolRegistryFailure::validation(
            'target',
            '',
            'A node or app target is required.',
        );
    }
}
