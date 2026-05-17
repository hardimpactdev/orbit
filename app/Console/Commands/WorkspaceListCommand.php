<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Http\Gateway\GatewayApiException;
use App\Http\Gateway\GatewayConnector;
use App\Http\Gateway\Requests\Workspaces\ListWorkspacesRequest;
use App\Http\Gateway\Responses\Workspaces\WorkspaceListResponse;
use App\Models\App;
use App\Models\Node;
use App\Models\Workspace;
use App\Services\Nodes\Roles\NodeRoleAssignments;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Throwable;

use function Laravel\Prompts\table;

#[Signature('workspace:list
    {--app= : Filter by parent app}
    {--node= : Filter by owning node}
    {--json : Output JSON}')]
#[Description('List workspaces registered on the gateway')]
class WorkspaceListCommand extends Command
{
    public function handle(): int
    {
        $app = $this->stringOption('app');
        $node = $this->stringOption('node');

        if ($this->hasInvalidScalarFilter($app)) {
            return $this->failValidation('app', (string) $app, "Unknown app: '{$app}'.");
        }

        if ($this->hasInvalidScalarFilter($node)) {
            return $this->failValidation('node', (string) $node, "Unknown node: '{$node}'.");
        }

        try {
            $workspaces = $this->fetchWorkspaces($app, $node);
        } catch (GatewayApiException $e) {
            return $this->failCommand(
                code: $e->errorCode() ?? 'gateway_unavailable',
                message: $e->getMessage() !== '' ? $e->getMessage() : 'Gateway connection is required to list workspaces.',
                meta: $e->errorMeta(),
            );
        } catch (Throwable) {
            return $this->failCommand(
                code: 'gateway_unavailable',
                message: 'Gateway connection is required to list workspaces.',
                meta: [],
            );
        }

        if ($this->wantsJson()) {
            return $this->jsonSuccess(['workspaces' => $workspaces]);
        }

        $this->renderHuman($workspaces);

        return self::SUCCESS;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function fetchWorkspaces(?string $app, ?string $node): array
    {
        if ((bool) config('orbit.is_gateway', false)) {
            $this->validateLocalFilters($app, $node);

            return $this->fetchLocalWorkspaces($app, $node);
        }

        /** @var WorkspaceListResponse $dto */
        $dto = app(GatewayConnector::class)
            ->send(new ListWorkspacesRequest(app: $app, node: $node))
            ->dto();

        return $dto->workspaces;
    }

    private function validateLocalFilters(?string $app, ?string $node): void
    {
        if ($app !== null && ! App::query()->where('name', $app)->exists()) {
            throw new GatewayApiException("Unknown app: '{$app}'.", 'validation_failed', [
                'field' => 'app',
                'value' => $app,
            ]);
        }

        if ($node !== null && ! Node::query()->where('name', $node)->whereIn('id', app(NodeRoleAssignments::class)->activeAppHostNodeIds())->exists()) {
            throw new GatewayApiException("Unknown node: '{$node}'.", 'validation_failed', [
                'field' => 'node',
                'value' => $node,
            ]);
        }
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function fetchLocalWorkspaces(?string $app, ?string $node): array
    {
        return $this->fetchLocalWorkspaceModels($app, $node)
            ->map(fn (Workspace $workspace): array => $this->workspacePayload($workspace))
            ->all();
    }

    /**
     * @return Collection<int, Workspace>
     */
    private function fetchLocalWorkspaceModels(?string $app, ?string $node): Collection
    {
        return Workspace::query()
            ->with(['app.node'])
            ->when($app !== null, fn (Builder $query): Builder => $query->whereHas('app', fn (Builder $query): Builder => $query->where('name', $app)))
            ->when($node !== null, fn (Builder $query): Builder => $query->whereHas('app.node', fn (Builder $query): Builder => $query->where('name', $node)))
            ->get()
            ->sort(fn (Workspace $first, Workspace $second): int => [
                mb_strtolower((string) $first->app?->node?->name),
                mb_strtolower((string) $first->app?->name),
                mb_strtolower($first->name),
            ] <=> [
                mb_strtolower((string) $second->app?->node?->name),
                mb_strtolower((string) $second->app?->name),
                mb_strtolower($second->name),
            ])
            ->values();
    }

    /**
     * @return array<string, mixed>
     */
    private function workspacePayload(Workspace $workspace): array
    {
        return [
            'name' => $workspace->name,
            'app' => $workspace->app?->name,
            'node' => $workspace->app?->node?->name,
            'url' => $workspace->url(),
            'lifecycle_status' => $workspace->lifecycle_status->value,
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $workspaces
     */
    private function renderHuman(array $workspaces): void
    {
        if ($workspaces === []) {
            $this->line('No workspaces found.');

            return;
        }

        $currentNode = (string) ($workspaces[0]['node'] ?? 'without node');
        $currentApp = (string) ($workspaces[0]['app'] ?? 'without app');
        $rows = [];

        foreach ($workspaces as $workspace) {
            $node = (string) ($workspace['node'] ?? 'without node');
            $app = (string) ($workspace['app'] ?? 'without app');

            if ($node !== $currentNode || $app !== $currentApp) {
                $this->renderWorkspaceTable($currentNode, $currentApp, $rows);
                $this->newLine();
                $rows = [];
            }

            $currentNode = $node;
            $currentApp = $app;
            $rows[] = [
                $workspace['name'],
                $workspace['url'],
                $workspace['lifecycle_status'],
            ];
        }

        $this->renderWorkspaceTable($currentNode, $currentApp, $rows);
    }

    /**
     * @param  list<array<int, mixed>>  $rows
     */
    private function renderWorkspaceTable(string $node, string $app, array $rows): void
    {
        $this->line("Node: {$node}");
        $this->line("App: {$app}");
        table(['WORKSPACE', 'URL', 'LIFECYCLE STATUS'], $rows);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function jsonSuccess(array $data): int
    {
        $this->line(json_encode([
            'success' => [
                'data' => $data,
            ],
        ], JSON_THROW_ON_ERROR));

        return self::SUCCESS;
    }

    /**
     * @param  array<string, mixed>  $meta
     */
    private function failCommand(string $code, string $message, array $meta): int
    {
        if ($this->wantsJson()) {
            $this->line(json_encode([
                'error' => [
                    'code' => $code,
                    'message' => $message,
                    'meta' => empty($meta) ? (object) [] : $meta,
                ],
            ], JSON_THROW_ON_ERROR));

            return self::FAILURE;
        }

        $this->error($message);

        return self::FAILURE;
    }

    private function failValidation(string $field, string $value, string $message): int
    {
        return $this->failCommand(
            code: 'validation_failed',
            message: $message,
            meta: [
                'field' => $field,
                'value' => $value,
            ],
        );
    }

    private function hasInvalidScalarFilter(?string $value): bool
    {
        return $value !== null && str_contains($value, ',');
    }

    private function stringOption(string $key): ?string
    {
        $value = $this->option($key);

        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }

    private function wantsJson(): bool
    {
        return $this->option('json') === true;
    }
}
