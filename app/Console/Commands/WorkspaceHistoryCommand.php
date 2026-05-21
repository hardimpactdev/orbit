<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Http\Gateway\GatewayApiException;
use App\Http\Gateway\GatewayConnector;
use App\Http\Gateway\Requests\Workspaces\ShowWorkspaceHistoryRequest;
use App\Http\Gateway\Responses\Workspaces\WorkspaceHistoryResponse;
use App\Models\Workspace;
use App\Services\Workspaces\WorkspaceHistoryPayload;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Throwable;

use function Laravel\Prompts\table;

#[Signature('workspace:history
    {name? : Workspace name}
    {--app= : Parent app slug}
    {--limit= : Maximum number of runs to return}
    {--since= : ISO 8601 lower started_at bound}
    {--until= : ISO 8601 exclusive upper started_at bound}
    {--json : Output JSON}')]
#[Description('Show workspace lifecycle history')]
class WorkspaceHistoryCommand extends Command
{
    private const int DEFAULT_LIMIT = 50;

    private const int MAX_LIMIT = 500;

    public function handle(WorkspaceHistoryPayload $payload): int
    {
        $filters = $this->parseFilters();

        if (is_int($filters)) {
            return $filters;
        }

        $name = $this->stringArgument('name');
        $app = $this->stringOption('app');
        $onGateway = (bool) config('orbit.is_gateway', false);
        $path = null;

        if ($name === null) {
            $name = $this->resolveNameFromCwd($onGateway);
        }

        if ($name === null && ! $onGateway) {
            $path = realpath((string) getcwd()) ?: (string) getcwd();
        }

        if ($name === null && $path === null) {
            return $this->failCommand(
                code: 'validation_failed',
                message: 'Workspace name is required.',
                meta: ['field' => 'name'],
            );
        }

        try {
            $history = $this->fetchHistory($name, $app, $path, $filters, $payload, $onGateway);
        } catch (GatewayApiException $e) {
            return $this->failCommand(
                code: $e->errorCode() ?? 'gateway_unavailable',
                message: $e->getMessage() !== '' ? $e->getMessage() : 'Gateway connection is required to read workspace history.',
                meta: $e->errorMeta(),
            );
        } catch (Throwable) {
            return $this->failCommand(
                code: 'gateway_unavailable',
                message: 'Gateway connection is required to read workspace history.',
                meta: [],
            );
        }

        if ($this->wantsJson()) {
            return $this->jsonSuccess(['runs' => $history['runs']], ['pagination' => $history['pagination']]);
        }

        $this->renderHuman($history);

        return self::SUCCESS;
    }

    /**
     * @param  array{limit: int, since: Carbon|null, until: Carbon|null, limit_capped: bool, raw_since: string|null, raw_until: string|null}  $filters
     * @return array{runs: list<array<string, mixed>>, pagination: array<string, mixed>, workspace: array{app: string|null, name: string|null}}
     */
    private function fetchHistory(?string $name, ?string $app, ?string $path, array $filters, WorkspaceHistoryPayload $payload, bool $onGateway): array
    {
        if (! $onGateway) {
            /** @var WorkspaceHistoryResponse $dto */
            $dto = app(GatewayConnector::class)
                ->send(new ShowWorkspaceHistoryRequest(
                    name: $name,
                    app: $app,
                    path: $path,
                    limit: $filters['limit'],
                    since: $filters['raw_since'],
                    until: $filters['raw_until'],
                ))
                ->dto();

            return [
                'runs' => $dto->runs,
                'pagination' => $dto->pagination,
                'workspace' => [
                    'app' => $dto->runs[0]['app'] ?? $app,
                    'name' => $dto->runs[0]['workspace'] ?? $name,
                ],
            ];
        }

        if ($name === null) {
            throw new GatewayApiException('Workspace name is required.', 'validation_failed', [
                'field' => 'name',
            ]);
        }

        $workspace = $this->resolveWorkspace($name, $app);

        if (! $workspace instanceof Workspace) {
            throw new GatewayApiException("Workspace not found: {$name}", 'workspace.not_found', [
                'name' => $name,
            ]);
        }

        $history = $payload->forWorkspace(
            workspace: $workspace,
            limit: $filters['limit'],
            since: $filters['since'],
            until: $filters['until'],
            limitCapped: $filters['limit_capped'],
        );

        return [
            'runs' => $history['runs'],
            'pagination' => $history['pagination'],
            'workspace' => [
                'app' => $workspace->app?->name,
                'name' => $workspace->name,
            ],
        ];
    }

    private function resolveWorkspace(string $name, ?string $app): ?Workspace
    {
        $matches = Workspace::query()
            ->with('app')
            ->where('name', $name)
            ->when($app !== null, fn (Builder $query): Builder => $query->whereHas('app', fn (Builder $query): Builder => $query->where('name', $app)))
            ->get();

        if ($app === null && $matches->count() > 1) {
            throw new GatewayApiException("Ambiguous workspace: {$name}. Specify --app.", 'workspace.ambiguous_name', [
                'name' => $name,
                'apps' => $matches->map(fn (Workspace $workspace): ?string => $workspace->app?->name)->filter()->values()->all(),
            ]);
        }

        return $matches->first();
    }

    /**
     * @return array{limit: int, since: Carbon|null, until: Carbon|null, limit_capped: bool, raw_since: string|null, raw_until: string|null}|int
     */
    private function parseFilters(): array|int
    {
        $limitValue = $this->stringOption('limit');
        $limit = self::DEFAULT_LIMIT;
        $limitCapped = false;

        if ($limitValue !== null) {
            if (! ctype_digit($limitValue) || (int) $limitValue < 1) {
                return $this->failCommand(
                    code: 'validation_failed',
                    message: 'Invalid value for --limit: must be a positive integer.',
                    meta: [
                        'field' => 'limit',
                        'value' => $limitValue,
                    ],
                );
            }

            $limit = (int) $limitValue;

            if ($limit > self::MAX_LIMIT) {
                $limit = self::MAX_LIMIT;
                $limitCapped = true;
            }
        }

        $sinceValue = $this->stringOption('since');
        $untilValue = $this->stringOption('until');
        $since = $this->parseDateFilter('since', $sinceValue);

        if (is_int($since)) {
            return $since;
        }

        $until = $this->parseDateFilter('until', $untilValue);

        if (is_int($until)) {
            return $until;
        }

        return [
            'limit' => $limit,
            'since' => $since,
            'until' => $until,
            'limit_capped' => $limitCapped,
            'raw_since' => $sinceValue,
            'raw_until' => $untilValue,
        ];
    }

    private function parseDateFilter(string $field, ?string $value): Carbon|int|null
    {
        if ($value === null) {
            return null;
        }

        try {
            return Carbon::parse($value);
        } catch (Throwable) {
            return $this->failCommand(
                code: 'validation_failed',
                message: "Invalid value for --{$field}: must be an ISO 8601 datetime.",
                meta: [
                    'field' => $field,
                    'value' => $value,
                ],
            );
        }
    }

    private function resolveNameFromCwd(bool $onGateway): ?string
    {
        if (! $onGateway) {
            return null;
        }

        $cwd = realpath((string) getcwd()) ?: (string) getcwd();

        return Workspace::query()
            ->get()
            ->first(function (Workspace $workspace) use ($cwd): bool {
                $workspacePath = realpath($workspace->path) ?: $workspace->path;

                return $workspacePath === $cwd || str_starts_with($cwd, "{$workspacePath}/");
            })?->name;
    }

    /**
     * @param  array{runs: list<array<string, mixed>>, pagination: array<string, mixed>, workspace: array{app: string|null, name: string|null}}  $history
     */
    private function renderHuman(array $history): void
    {
        $workspace = $history['workspace'];
        $runs = $history['runs'];
        $this->line("Workspace History: {$workspace['app']}/{$workspace['name']}");
        $this->newLine();

        if ($runs === []) {
            $this->line('No workspace history found.');

            return;
        }

        table(['ID', 'ACTION', 'STATUS', 'STARTED', 'DURATION'], array_map(
            fn (array $run): array => [
                $run['id'],
                $run['action'],
                $run['status'],
                $this->startedAt($run['started_at'] ?? null),
                $this->duration($run['started_at'] ?? null, $run['finished_at'] ?? null),
            ],
            $runs,
        ));
    }

    private function startedAt(?string $startedAt): string
    {
        if ($startedAt === null) {
            return '---';
        }

        return Carbon::parse($startedAt)->diffForHumans();
    }

    private function duration(?string $startedAt, ?string $finishedAt): string
    {
        if ($startedAt === null || $finishedAt === null) {
            return '---';
        }

        $seconds = (int) Carbon::parse($startedAt)->diffInSeconds(Carbon::parse($finishedAt));

        if ($seconds < 60) {
            return "{$seconds}s";
        }

        $minutes = intdiv($seconds, 60);

        if ($minutes < 60) {
            return "{$minutes}m";
        }

        return intdiv($minutes, 60).'h';
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  array<string, mixed>  $meta
     */
    private function jsonSuccess(array $data, array $meta): int
    {
        $this->line(json_encode([
            'success' => [
                'data' => $data,
                'meta' => $meta,
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

    private function stringArgument(string $key): ?string
    {
        $value = $this->argument($key);

        return is_string($value) && trim($value) !== '' ? trim($value) : null;
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
