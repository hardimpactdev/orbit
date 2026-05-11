<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Http\Gateway\GatewayApiException;
use App\Http\Gateway\GatewayConnector;
use App\Http\Gateway\Requests\Apps\ListAppsRequest;
use App\Http\Gateway\Responses\Apps\AppListResponse;
use App\Models\App;
use App\Models\Node;
use App\Models\Workspace;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Throwable;

use function Laravel\Prompts\table;

#[Signature('app:list
    {--node= : Filter by owning node}
    {--environment= : Filter by environment (development|production)}
    {--json : Output as JSON}')]
#[Description('List apps registered on the gateway')]
class AppListCommand extends Command
{
    private const array VALID_ENVIRONMENTS = ['development', 'production'];

    public function handle(): int
    {
        $node = $this->option('node');
        $environment = $this->option('environment');

        if (is_string($environment) && $environment !== '' && ! in_array($environment, self::VALID_ENVIRONMENTS, true)) {
            return $this->failValidation(
                field: 'environment',
                value: $environment,
                allowed: self::VALID_ENVIRONMENTS,
            );
        }

        try {
            $apps = $this->fetchApps(
                node: is_string($node) && $node !== '' ? $node : null,
                environment: is_string($environment) && $environment !== '' ? $environment : null,
            );
        } catch (Throwable) {
            return $this->failGatewayError(
                code: 'gateway_unavailable',
                message: 'Gateway connection is required to list apps.',
                meta: [],
            );
        }

        if ($apps instanceof GatewayApiException) {
            return $this->failGatewayError(
                code: $apps->errorCode() ?? 'gateway_unavailable',
                message: $apps->getMessage() !== ''
                    ? $apps->getMessage()
                    : 'Gateway connection is required to list apps.',
                meta: $apps->errorMeta(),
            );
        }

        if ($this->wantsJson()) {
            return $this->jsonSuccess(['apps' => $apps]);
        }

        $this->renderHuman($apps);

        return self::SUCCESS;
    }

    /**
     * @return list<array<string, mixed>>|GatewayApiException
     */
    private function fetchApps(?string $node, ?string $environment): array|GatewayApiException
    {
        if ($this->isGatewayCaller()) {
            return $this->fetchLocalApps($node, $environment);
        }

        try {
            $dto = app(GatewayConnector::class)
                ->send(new ListAppsRequest(node: $node, environment: $environment))
                ->dto();
        } catch (GatewayApiException $e) {
            return $e;
        }

        /** @var AppListResponse $dto */
        return $dto->apps;
    }

    private function isGatewayCaller(): bool
    {
        return $this->callerRole() === 'gateway';
    }

    private function callerRole(): string
    {
        $localRole = Node::query()
            ->where('is_local', true)
            ->where('status', 'active')
            ->value('role');

        if (! is_string($localRole) || $localRole === '') {
            return 'control';
        }

        if (! in_array($localRole, ['gateway', 'app', 'control'], true)) {
            return 'unknown';
        }

        return $localRole;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function fetchLocalApps(?string $node, ?string $environment): array
    {
        return $this->fetchLocalAppModels($node, $environment)
            ->map(fn (App $app): array => $this->appPayload($app))
            ->all();
    }

    /**
     * @return Collection<int, App>
     */
    private function fetchLocalAppModels(?string $node, ?string $environment): Collection
    {
        return App::query()
            ->with(['node', 'workspaces'])
            ->when($node !== null, fn (Builder $query): Builder => $query->whereHas('node', fn (Builder $query): Builder => $query->where('name', $node)))
            ->when($environment !== null, fn (Builder $query): Builder => $query->where('environment', $environment))
            ->get()
            ->sort(fn (App $first, App $second): int => [
                mb_strtolower((string) $first->node?->name),
                mb_strtolower($first->name),
            ] <=> [
                mb_strtolower((string) $second->node?->name),
                mb_strtolower($second->name),
            ])
            ->values();
    }

    /**
     * @return array<string, mixed>
     */
    private function appPayload(App $app): array
    {
        return [
            'name' => $app->name,
            'node' => $app->node?->name,
            'environment' => $app->environment,
            'url' => $app->url(),
            'path' => $app->path,
            'root' => $app->document_root,
            'repository' => $app->repository,
            'php_version' => $app->php_version,
            'adopted' => $app->adopted,
            'workspaces' => $this->workspacePayloads($app),
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function workspacePayloads(App $app): array
    {
        $appHost = parse_url($app->url(), PHP_URL_HOST);

        return $app->workspaces
            ->sortBy(fn (Workspace $workspace): string => mb_strtolower($workspace->name))
            ->map(fn (Workspace $workspace): array => [
                'name' => $workspace->name,
                'url' => is_string($appHost) && $appHost !== ''
                    ? "https://{$workspace->name}.{$appHost}"
                    : $workspace->url(),
                'lifecycle_status' => $workspace->lifecycle_status->value,
            ])
            ->values()
            ->all();
    }

    /**
     * @param  list<array<string, mixed>>  $apps
     */
    private function renderHuman(array $apps): void
    {
        if ($apps === []) {
            $this->line('No apps found.');

            return;
        }

        $currentNode = (string) ($apps[0]['node'] ?? 'without node');
        $rows = [];

        foreach ($apps as $app) {
            $node = (string) ($app['node'] ?? 'without node');

            if ($node !== $currentNode) {
                $this->renderNodeTable($currentNode, $rows);
                $this->newLine();
                $rows = [];
            }

            $currentNode = $node;
            $rows[] = [
                $app['name'],
                $app['environment'],
                $app['url'],
                'expected',
            ];

            foreach ($app['workspaces'] ?? [] as $workspace) {
                if (! is_array($workspace)) {
                    continue;
                }

                $rows[] = [
                    '├─ '.(string) ($workspace['name'] ?? ''),
                    $app['environment'],
                    $workspace['url'] ?? '-',
                    $workspace['lifecycle_status'] ?? '-',
                ];
            }
        }

        $this->renderNodeTable($currentNode, $rows);
    }

    /**
     * @param  list<array<int, mixed>>  $rows
     */
    private function renderNodeTable(string $node, array $rows): void
    {
        $this->line("Node: {$node}");
        table(['NAME', 'ENVIRONMENT', 'URL', 'STATUS'], $rows);
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
     * @param  list<string>  $allowed
     */
    private function failValidation(string $field, string $value, array $allowed): int
    {
        $message = "Invalid value for --{$field}: '{$value}'. Allowed values: ".implode(', ', $allowed).'.';

        if ($this->wantsJson()) {
            $this->line(json_encode([
                'error' => [
                    'code' => 'validation_failed',
                    'message' => $message,
                    'meta' => [
                        'field' => $field,
                        'value' => $value,
                        'allowed' => $allowed,
                    ],
                ],
            ], JSON_THROW_ON_ERROR));

            return self::FAILURE;
        }

        $this->error($message);

        return self::FAILURE;
    }

    /**
     * @param  array<string, mixed>  $meta
     */
    private function failGatewayError(string $code, string $message, array $meta): int
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

    private function wantsJson(): bool
    {
        return (bool) $this->option('json');
    }
}
