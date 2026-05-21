<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Concerns\PromptsForRegistryEntities;
use App\Console\Commands\Concerns\RendersShowDetails;
use App\Exceptions\PromptAborted;
use App\Http\Gateway\GatewayApiException;
use App\Http\Gateway\GatewayConnector;
use App\Http\Gateway\Requests\Apps\ShowAppRequest;
use App\Http\Gateway\Responses\Apps\AppShowResponse;
use App\Models\App;
use App\Services\Apps\AppAgentIdeDefaults;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Throwable;

#[Signature('app:show
    {app? : App name or hostname to inspect}
    {--json : Output JSON}')]
#[Description('Show one app from the gateway registry')]
class AppShowCommand extends Command
{
    use PromptsForRegistryEntities;
    use RendersShowDetails;

    public function handle(): int
    {
        $executionContext = (bool) config('orbit.is_gateway', false) ? 'gateway' : 'control';

        $app = $this->resolveAppSelector($executionContext);

        if ($app instanceof GatewayApiException) {
            return $this->failCommand(
                code: $app->errorCode() ?? 'gateway_unavailable',
                message: $app->getMessage() !== '' ? $app->getMessage() : 'Gateway connection is required to list apps.',
                meta: $app->errorMeta(),
            );
        }

        if ($app === null) {
            return $this->failCommand(
                code: 'validation_failed',
                message: 'App name is required.',
                meta: ['field' => 'app'],
            );
        }

        $result = $executionContext === 'gateway'
            ? $this->fetchLocalApp($app)
            : $this->fetchGatewayApp($app);

        if ($result instanceof GatewayApiException) {
            return $this->failCommand(
                code: $result->errorCode() ?? 'gateway_unavailable',
                message: $result->getMessage() !== ''
                    ? $result->getMessage()
                    : 'Gateway connection is required to show app details.',
                meta: $result->errorMeta(),
            );
        }

        if ($result === null) {
            return $this->failCommand(
                code: 'app.not_found',
                message: "App '{$app}' not found or not visible.",
                meta: ['app' => $app],
            );
        }

        if ($this->wantsJson()) {
            return $this->jsonSuccess($result);
        }

        $this->renderHuman($result);

        return self::SUCCESS;
    }

    private function resolveAppSelector(string $executionContext): string|GatewayApiException|null
    {
        $app = $this->argument('app');

        if (is_string($app) && trim($app) !== '') {
            return trim($app);
        }

        $fromCwd = $this->resolveAppFromCwd();

        if ($fromCwd !== null) {
            return $fromCwd;
        }

        if ($this->isInteractiveInput()) {
            return $this->promptApp($executionContext);
        }

        return null;
    }

    private function resolveAppFromCwd(): ?string
    {
        $cwd = getcwd();

        if ($cwd === false) {
            return null;
        }

        $normalizedCwd = realpath($cwd) ?: $cwd;

        $app = App::query()
            ->get()
            ->first(function (App $app) use ($normalizedCwd): bool {
                $path = realpath($app->path) ?: $app->path;

                return $normalizedCwd === $path
                    || str_starts_with($normalizedCwd, rtrim($path, '/').'/');
            });

        return $app?->name;
    }

    protected function isInteractiveInput(): bool
    {
        return ! $this->wantsJson() && $this->input->isInteractive();
    }

    protected function promptApp(string $executionContext): string|GatewayApiException
    {
        try {
            return $this->promptForVisibleApp(label: 'Select an app');
        } catch (PromptAborted) {
            return new GatewayApiException('Operation cancelled.', 'validation_failed', []);
        }
    }

    /**
     * @return array{app: array<string, mixed>, details: array<string, mixed>}|null
     */
    private function fetchLocalApp(string $selector): ?array
    {
        $app = $this->resolveVisibleLocalApp($selector);

        if (! $app instanceof App) {
            return null;
        }

        return [
            'app' => $this->appPayload($app),
            'details' => $this->detailsPayload($app),
        ];
    }

    /**
     * @return array{app: array<string, mixed>, details: array<string, mixed>}|GatewayApiException
     */
    private function fetchGatewayApp(string $selector): array|GatewayApiException
    {
        try {
            $dto = app(GatewayConnector::class)
                ->send(new ShowAppRequest($selector))
                ->dto();
        } catch (GatewayApiException $e) {
            return $e;
        } catch (Throwable) {
            return new GatewayApiException(
                message: 'Gateway connection is required to show app details.',
                errorCode: 'gateway_unavailable',
                errorMeta: [],
            );
        }

        /** @var AppShowResponse $dto */
        return [
            'app' => $dto->app,
            'details' => $dto->details,
        ];
    }

    private function resolveVisibleLocalApp(string $selector): ?App
    {
        $baseQuery = App::query()->with('node');

        $nameMatch = (clone $baseQuery)
            ->where('name', $selector)
            ->first();

        if ($nameMatch instanceof App) {
            return $nameMatch;
        }

        return $baseQuery
            ->where('domain', $selector)
            ->first();
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
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function detailsPayload(App $app): array
    {
        return [
            'domain' => $this->domain($app),
            'document_root' => $app->documentRootPath(),
            'node' => [
                'name' => $app->node?->name,
                'host' => $app->node?->host,
            ],
            'agent_ide' => $this->agentIdePayload($app),
            'workspaces' => [],
            'processes' => [],
            'routes' => [
                [
                    'host' => $this->domain($app),
                    'kind' => 'app',
                    'owner' => 'app',
                ],
            ],
        ];
    }

    private function domain(App $app): ?string
    {
        if (is_string($app->domain) && $app->domain !== '') {
            return $app->domain;
        }

        $host = parse_url($app->url(), PHP_URL_HOST);

        return is_string($host) && $host !== '' ? $host : null;
    }

    /**
     * @return array{adapter: string|null, inherited_from: string, workspace_discovery: string|null}
     */
    private function agentIdePayload(App $app): array
    {
        $agentIde = app(AppAgentIdeDefaults::class)->payloadFor($app);
        $effectiveAdapter = $agentIde['effective_adapter'];

        return [
            'adapter' => $effectiveAdapter,
            'inherited_from' => $agentIde['source'],
            'workspace_discovery' => $effectiveAdapter === null ? null : $this->workspaceDiscovery($effectiveAdapter),
        ];
    }

    private function workspaceDiscovery(string $adapter): string
    {
        return in_array($adapter, ['opencode', 'polyscope'], true) ? 'available' : 'unsupported';
    }

    /**
     * @param  array{app: array<string, mixed>, details: array<string, mixed>}  $payload
     */
    private function renderHuman(array $payload): void
    {
        $app = $payload['app'];
        $details = $payload['details'];
        $node = is_array($details['node'] ?? null) ? $details['node'] : [];

        $this->renderShowDetails("App: {$app['name']}", [
            'Domain' => $details['domain'] ?? null,
            'Environment' => $app['environment'] ?? null,
            'Node' => $this->nodeLabel($node, $app['node'] ?? null),
            'Repository' => $app['repository'] ?? null,
            'PHP' => $app['php_version'] ?? null,
            'Path' => $app['path'] ?? null,
            'Root' => $details['document_root'] ?? null,
            'Workspaces' => $this->itemLabels($details['workspaces'] ?? []),
            'Processes' => $this->itemLabels($details['processes'] ?? []),
            'Routes' => $this->itemLabels($details['routes'] ?? []),
        ]);
    }

    /**
     * @param  array<string, mixed>  $node
     */
    private function nodeLabel(array $node, mixed $fallback): string
    {
        $name = is_string($node['name'] ?? null) ? $node['name'] : $fallback;
        $host = is_string($node['host'] ?? null) ? $node['host'] : null;

        if (! is_string($name) || $name === '') {
            return '—';
        }

        return $host === null || $host === '' ? $name : "{$name} ({$host})";
    }

    private function itemLabels(mixed $items): string
    {
        if (! is_array($items) || $items === []) {
            return '—';
        }

        $labels = [];

        foreach ($items as $item) {
            if (! is_array($item)) {
                continue;
            }

            $label = $item['name'] ?? $item['host'] ?? null;

            if (is_string($label) && $label !== '') {
                $labels[] = $label;
            }
        }

        return $labels === [] ? '—' : implode(', ', $labels);
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

    private function wantsJson(): bool
    {
        return (bool) $this->option('json');
    }
}
