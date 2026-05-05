<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Actions\Profile\ShowProfile;
use App\Http\Gateway\GatewayApiException;
use App\Http\Gateway\GatewayConnector;
use App\Http\Gateway\Requests\Apps\ListAppsRequest;
use App\Http\Gateway\Requests\Apps\ShowAppRequest;
use App\Http\Gateway\Requests\Profile\ShowProfileRequest;
use App\Models\App;
use App\Models\Node;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Throwable;

use function Laravel\Prompts\datatable;

#[Signature('profile
    {target? : Domain, app hostname, full URL, or absolute app path}
    {--app= : App name or hostname to profile}
    {--node= : Constrain app resolution to a node}
    {--uri=/ : Request URI to profile}
    {--as-first-user : Authenticate the profiled request as the first user}
    {--user= : Authenticate the profiled request as the given primary key}
    {--json : Output as JSON}')]
#[Description('Profile one Orbit-managed app HTTP request')]
class ProfileCommand extends Command
{
    public function handle(ShowProfile $profile): int
    {
        $callerRole = $this->callerRole();

        if ($callerRole === 'unknown') {
            return $this->failCommand(
                code: 'local_context_invalid',
                message: 'Local node role setting is invalid.',
                meta: [
                    'setting' => 'general.local_node_role',
                    'reason' => 'unsupported_value',
                    'caller_role' => 'unknown',
                ],
            );
        }

        $target = $this->stringArgument('target');
        $appOption = $this->stringOption('app');

        if ($target !== null && $appOption !== null) {
            return $this->failCommand(
                code: 'validation_failed',
                message: 'Use either target or --app, not both.',
                meta: [
                    'field' => 'target',
                    'reason' => 'conflicts_with_app',
                ],
            );
        }

        if ((bool) $this->option('as-first-user') && $this->stringOption('user') !== null) {
            return $this->failCommand(
                code: 'validation_failed',
                message: 'Use either --as-first-user or --user, not both.',
                meta: [
                    'field' => 'auth',
                    'reason' => 'conflicting_auth_modes',
                ],
            );
        }

        $uri = $this->normalizedUri();

        if ($uri === null) {
            return $this->failCommand(
                code: 'validation_failed',
                message: 'Profile URI must be a non-empty path.',
                meta: [
                    'field' => 'uri',
                    'reason' => 'invalid_path',
                ],
            );
        }

        $selector = $appOption ?? $target;
        $selectorWasOmitted = $selector === null;
        $nodeConstraint = $this->stringOption('node');

        if ($callerRole === 'gateway' && $nodeConstraint !== null && ! Node::query()->where('name', $nodeConstraint)->where('status', 'active')->exists()) {
            return $this->failCommand(
                code: 'validation_failed',
                message: "Node '{$nodeConstraint}' not found.",
                meta: [
                    'field' => 'node',
                    'reason' => 'unknown_node',
                ],
            );
        }

        if ($selector !== null && preg_match('#^https?://#i', $selector) === 1) {
            $parsed = $this->parseUrlTarget($selector, $uri);

            if ($parsed === null) {
                return $this->failCommand(
                    code: 'validation_failed',
                    message: "Could not parse host from URL '{$selector}'.",
                    meta: [
                        'field' => 'target',
                        'reason' => 'invalid_url',
                    ],
                );
            }

            [$selector, $uri] = $parsed;
        }

        if ($selector === null && in_array($callerRole, ['gateway', 'app'], true)) {
            $cwd = getcwd();
            $selector = is_string($cwd) ? $cwd : null;
        }

        if ($selector === null && $this->canPromptForApp()) {
            $selector = $this->promptForApp($callerRole, $nodeConstraint);
        }

        if ($selector === null) {
            return $this->failCommand(
                code: 'validation_failed',
                message: 'Specify a target or --app.',
                meta: [
                    'field' => 'target',
                    'reason' => 'missing_required_input',
                ],
            );
        }

        if ($callerRole === 'app') {
            $gatewayResult = $this->profileThroughGateway($selector, $uri, $nodeConstraint);

            if (
                $selectorWasOmitted
                && $gatewayResult instanceof GatewayApiException
                && $gatewayResult->errorCode() === 'app.not_found'
                && $this->canPromptForApp()
            ) {
                $selected = $this->promptForApp($callerRole, $nodeConstraint);
                $gatewayResult = $selected !== null
                    ? $this->profileThroughGateway($selected, $uri, $nodeConstraint)
                    : $gatewayResult;
            }

            if ($gatewayResult instanceof GatewayApiException) {
                return $this->failCommand(
                    code: $gatewayResult->errorCode() ?? 'gateway_unavailable',
                    message: $gatewayResult->getMessage() !== '' ? $gatewayResult->getMessage() : 'Gateway connection is required to profile this target.',
                    meta: $gatewayResult->errorMeta(),
                );
            }

            if ($this->wantsJson()) {
                return $this->jsonSuccess($gatewayResult);
            }

            $this->renderHuman($gatewayResult);

            return self::SUCCESS;
        }

        if ($callerRole === 'gateway') {
            $app = $this->resolveLocalApp($selector, $nodeConstraint);

            if (! $app instanceof App) {
                if ($selectorWasOmitted && $this->canPromptForApp()) {
                    $selected = $this->promptForApp($callerRole, $nodeConstraint);
                    $app = $selected !== null ? $this->resolveLocalApp($selected, $nodeConstraint) : null;
                }

                if ($app instanceof App) {
                    $url = $this->profileUrl($app, $uri);
                    $targetPayload = [
                        'app' => $app->name,
                        'workspace' => null,
                        'node' => $app->node?->name,
                        'domain' => $this->appDomain($app),
                    ];
                    $origin = 'gateway';
                } else {
                    return $this->failCommand(
                        code: 'target_not_found',
                        message: 'No linked app found for the requested profile target.',
                        meta: ['target' => $selector],
                    );
                }
            } else {
                $url = $this->profileUrl($app, $uri);
                $targetPayload = [
                    'app' => $app->name,
                    'workspace' => null,
                    'node' => $app->node?->name,
                    'domain' => $this->appDomain($app),
                ];
                $origin = 'gateway';
            }
        } else {
            $gatewayResult = $this->fetchGatewayApp($selector);

            if ($gatewayResult instanceof GatewayApiException) {
                return $this->failCommand(
                    code: $gatewayResult->errorCode() === 'app.not_found' ? 'target_not_found' : ($gatewayResult->errorCode() ?? 'gateway_unavailable'),
                    message: $gatewayResult->errorCode() === 'app.not_found'
                        ? 'No linked app found for the requested profile target.'
                        : ($gatewayResult->getMessage() !== '' ? $gatewayResult->getMessage() : 'Gateway connection is required to resolve this profile target.'),
                    meta: $gatewayResult->errorMeta(),
                );
            }

            $appPayload = $gatewayResult['app'];
            $url = $this->profileUrlFromPayload($appPayload, $uri);
            $targetPayload = [
                'app' => is_string($appPayload['name'] ?? null) ? $appPayload['name'] : $selector,
                'workspace' => null,
                'node' => is_string($appPayload['node'] ?? null) ? $appPayload['node'] : null,
                'domain' => $this->domainFromPayload($appPayload, $selector),
            ];
            $origin = 'caller';
        }

        $result = $profile->handle(
            url: $url,
            authMode: $this->authMode(),
            target: $targetPayload,
            origin: $origin,
            user: $this->stringOption('user'),
        );

        if (($result['success'] ?? false) !== true) {
            if ($callerRole === 'control') {
                $gatewayResult = $this->profileThroughGateway($selector, $uri, $nodeConstraint);

                if (! $gatewayResult instanceof GatewayApiException) {
                    if ($this->wantsJson()) {
                        return $this->jsonSuccess($gatewayResult);
                    }

                    $this->renderHuman($gatewayResult);

                    return self::SUCCESS;
                }
            }

            $error = $result['error'] ?? [];

            return $this->failCommand(
                code: is_string($error['code'] ?? null) ? $error['code'] : 'profile_request_failed',
                message: is_string($error['message'] ?? null) ? $error['message'] : 'Failed to complete profile request.',
                meta: is_array($error['meta'] ?? null) ? $error['meta'] : [],
                data: is_array($error['data'] ?? null) ? $error['data'] : [],
            );
        }

        $data = $result['data'] ?? [];

        if (! is_array($data)) {
            return $this->failCommand(
                code: 'profile_request_failed',
                message: 'Failed to complete profile request.',
                meta: [],
            );
        }

        if ($this->wantsJson()) {
            return $this->jsonSuccess($data);
        }

        $this->renderHuman($data);

        return self::SUCCESS;
    }

    /**
     * @return array<string, mixed>|GatewayApiException
     */
    private function profileThroughGateway(string $selector, string $uri, ?string $nodeConstraint): array|GatewayApiException
    {
        try {
            $dto = app(GatewayConnector::class)
                ->send(new ShowProfileRequest(
                    target: $selector,
                    uri: $uri,
                    authMode: $this->authMode(),
                    user: $this->stringOption('user'),
                    node: $nodeConstraint,
                ))
                ->dto();
        } catch (GatewayApiException $e) {
            return $e;
        } catch (Throwable) {
            return new GatewayApiException(
                message: 'Gateway connection is required to profile this target.',
                errorCode: 'gateway_unavailable',
                errorMeta: [],
            );
        }

        return $dto->data;
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
                message: 'Gateway connection is required to resolve this profile target.',
                errorCode: 'gateway_unavailable',
                errorMeta: [],
            );
        }

        return [
            'app' => $dto->app,
            'details' => $dto->details,
        ];
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

    private function canPromptForApp(): bool
    {
        return ! $this->wantsJson() && $this->input->isInteractive();
    }

    private function promptForApp(string $callerRole, ?string $nodeConstraint): ?string
    {
        $apps = $this->fetchPromptApps($callerRole, $nodeConstraint);

        if ($apps === [] || $apps instanceof GatewayApiException) {
            return null;
        }

        $rows = [];

        foreach ($apps as $app) {
            $name = is_string($app['name'] ?? null) && $app['name'] !== '' ? $app['name'] : null;

            if ($name === null) {
                continue;
            }

            $host = $this->domainFromPayload($app, $name);
            $rows[$name] = [
                $host,
                is_string($app['node'] ?? null) ? $app['node'] : '-',
                is_string($app['repository'] ?? null) ? $app['repository'] : '-',
            ];
        }

        if ($rows === []) {
            return null;
        }

        $selected = datatable(
            headers: ['Host', 'Node', 'Repository'],
            rows: $rows,
            label: 'Select an app to profile',
            hint: 'Press / to search',
        );

        return is_string($selected) && $selected !== '' ? $selected : null;
    }

    /**
     * @return list<array<string, mixed>>|GatewayApiException
     */
    private function fetchPromptApps(string $callerRole, ?string $nodeConstraint): array|GatewayApiException
    {
        if ($callerRole === 'gateway') {
            return App::query()
                ->with('node')
                ->when($nodeConstraint !== null, fn (Builder $query): Builder => $query->whereHas('node', fn (Builder $query): Builder => $query->where('name', $nodeConstraint)))
                ->get()
                ->sort(fn (App $first, App $second): int => [
                    mb_strtolower((string) $first->node?->name),
                    mb_strtolower($first->name),
                ] <=> [
                    mb_strtolower((string) $second->node?->name),
                    mb_strtolower($second->name),
                ])
                ->values()
                ->map(fn (App $app): array => [
                    'name' => $app->name,
                    'node' => $app->node?->name,
                    'url' => $app->url(),
                    'repository' => $app->repository,
                ])
                ->all();
        }

        try {
            $dto = app(GatewayConnector::class)
                ->send(new ListAppsRequest(node: $nodeConstraint))
                ->dto();
        } catch (GatewayApiException $e) {
            return $e;
        } catch (Throwable) {
            return new GatewayApiException(
                message: 'Gateway connection is required to list profile targets.',
                errorCode: 'gateway_unavailable',
                errorMeta: [],
            );
        }

        return $dto->apps;
    }

    private function stringArgument(string $name): ?string
    {
        $value = $this->argument($name);

        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }

    private function stringOption(string $name): ?string
    {
        $value = $this->option($name);

        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }

    private function normalizedUri(): ?string
    {
        $uri = $this->stringOption('uri') ?? '/';

        if ($uri === '') {
            return null;
        }

        return str_starts_with($uri, '/') ? $uri : "/{$uri}";
    }

    /**
     * @return array{string, string}|null
     */
    private function parseUrlTarget(string $target, string $currentUri): ?array
    {
        $parts = parse_url($target);

        if (! is_array($parts) || ! is_string($parts['host'] ?? null) || $parts['host'] === '') {
            return null;
        }

        $uri = $currentUri;

        if (($this->stringOption('uri') ?? '/') === '/') {
            $path = is_string($parts['path'] ?? null) && $parts['path'] !== '' ? $parts['path'] : '/';
            $query = is_string($parts['query'] ?? null) && $parts['query'] !== '' ? "?{$parts['query']}" : '';
            $uri = "{$path}{$query}";
        }

        return [$parts['host'], $uri];
    }

    private function resolveLocalApp(string $selector, ?string $nodeConstraint): ?App
    {
        $query = App::query()->with('node');

        if (str_starts_with($selector, '/')) {
            $normalizedSelector = realpath($selector) ?: $selector;

            return $query->get()->first(function (App $app) use ($normalizedSelector, $nodeConstraint): bool {
                if ($nodeConstraint !== null && $app->node?->name !== $nodeConstraint) {
                    return false;
                }

                $path = realpath($app->path) ?: $app->path;

                return $normalizedSelector === $path
                    || str_starts_with($normalizedSelector, rtrim($path, '/').'/');
            });
        }

        $nameMatch = (clone $query)
            ->where('name', $selector)
            ->when($nodeConstraint !== null, fn (Builder $query): Builder => $query->whereHas('node', fn (Builder $query): Builder => $query->where('name', $nodeConstraint)))
            ->first();

        if ($nameMatch instanceof App) {
            return $nameMatch;
        }

        return $query
            ->where('domain', $selector)
            ->when($nodeConstraint !== null, fn (Builder $query): Builder => $query->whereHas('node', fn (Builder $query): Builder => $query->where('name', $nodeConstraint)))
            ->first();
    }

    private function profileUrl(App $app, string $uri): string
    {
        return rtrim($app->url(), '/').$uri;
    }

    /**
     * @param  array<string, mixed>  $app
     */
    private function profileUrlFromPayload(array $app, string $uri): string
    {
        $url = is_string($app['url'] ?? null) && $app['url'] !== ''
            ? $app['url']
            : 'https://'.$this->domainFromPayload($app, is_string($app['name'] ?? null) ? $app['name'] : 'app');

        return rtrim($url, '/').$uri;
    }

    private function appDomain(App $app): string
    {
        $domain = $app->domain;

        if (is_string($domain) && $domain !== '') {
            return $domain;
        }

        return parse_url($app->url(), PHP_URL_HOST) ?: $app->name;
    }

    /**
     * @param  array<string, mixed>  $app
     */
    private function domainFromPayload(array $app, string $fallback): string
    {
        if (is_string($app['domain'] ?? null) && $app['domain'] !== '') {
            return $app['domain'];
        }

        if (is_string($app['url'] ?? null)) {
            $host = parse_url($app['url'], PHP_URL_HOST);

            if (is_string($host) && $host !== '') {
                return $host;
            }
        }

        return $fallback;
    }

    private function authMode(): string
    {
        if ($this->stringOption('user') !== null) {
            return 'user';
        }

        if ((bool) $this->option('as-first-user')) {
            return 'first-user';
        }

        return 'guest';
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function renderHuman(array $data): void
    {
        $request = is_array($data['request'] ?? null) ? $data['request'] : [];
        $timings = is_array($data['timings'] ?? null) ? $data['timings'] : [];
        $url = is_string($request['url'] ?? null) ? $request['url'] : '';
        $status = $request['status'] ?? '-';
        $totalMs = is_numeric($timings['total_ms'] ?? null) ? (float) $timings['total_ms'] : 0.0;

        $this->line('┌ Profiling '.($request['uri'] ?? '/'));
        $this->line('● Resolved target');
        $this->line('● Authorized profile read');
        $this->line('● Sent request');
        $this->line('● Collected timing data');
        $this->line("└ Profiled {$url} in ".$this->formatMs($totalMs).'ms');
        $this->newLine();
        $this->line("GET {$url} {$status} in ".$this->formatMs($totalMs).'ms');
        $this->line('DNS '.$this->formatMsValue($timings, 'dns_ms'));
        $this->line('Connect '.$this->formatMsValue($timings, 'connect_ms'));
        $this->line('TLS '.$this->formatMsValue($timings, 'tls_ms'));
        $this->line('Waiting for response '.$this->formatMsValue($timings, 'ttfb_ms'));
        $this->renderToolbarHuman(is_array($data['toolbar'] ?? null) ? $data['toolbar'] : null);
        $this->line('Download response '.$this->formatMsValue($timings, 'download_ms'));
        $this->line('Total '.$this->formatMs($totalMs).'ms');
    }

    /**
     * @param  array<string, mixed>|null  $toolbar
     */
    private function renderToolbarHuman(?array $toolbar): void
    {
        if ($toolbar === null) {
            return;
        }

        $anchors = is_array($toolbar['timing_anchors'] ?? null) ? $toolbar['timing_anchors'] : [];
        $caddyStart = $this->numericValue($anchors, 'caddy_start_ms');
        $phpStart = $this->numericValue($anchors, 'php_start_ms');
        $laravelStart = $this->numericValue($anchors, 'laravel_start_ms');
        $profilerEnd = $this->numericValue($anchors, 'profiler_end_ms');
        $collectedAt = $this->numericValue($anchors, 'collected_at_ms');

        if ($caddyStart !== null && $phpStart !== null) {
            $this->line('  Caddy in '.$this->formatMs(max(0.0, $phpStart - $caddyStart)).'ms');
        }

        if ($phpStart !== null && $laravelStart !== null) {
            $this->line('  PHP-FPM '.$this->formatMs(max(0.0, $laravelStart - $phpStart)).'ms');
        }

        $profiler = is_array($toolbar['profiler'] ?? null) ? $toolbar['profiler'] : [];
        $stages = is_array($profiler['stages'] ?? null) ? $profiler['stages'] : [];

        foreach ($stages as $stage) {
            if (! is_array($stage) || ! is_string($stage['label'] ?? null)) {
                continue;
            }

            $duration = is_numeric($stage['duration_ms'] ?? null)
                ? (float) $stage['duration_ms']
                : null;

            if ($duration === null) {
                continue;
            }

            $this->line('  '.$stage['label'].' '.$this->formatMs($duration).'ms');
        }

        if ($profilerEnd !== null && $collectedAt !== null) {
            $this->line('  Toolbar '.$this->formatMs(max(0.0, $collectedAt - $profilerEnd)).'ms');
        }

        $this->renderToolbarQueries(is_array($toolbar['queries'] ?? null) ? $toolbar['queries'] : []);
    }

    /**
     * @param  array<string, mixed>  $queries
     */
    private function renderToolbarQueries(array $queries): void
    {
        $count = (int) ($queries['count'] ?? 0);

        if ($count <= 0) {
            return;
        }

        $parts = ["{$count} queries"];
        $slowCount = (int) ($queries['slow_count'] ?? 0);
        $duplicateCount = (int) ($queries['duplicate_count'] ?? 0);

        if ($slowCount > 0) {
            $parts[] = "{$slowCount} slow";
        }

        if ($duplicateCount > 0) {
            $parts[] = "{$duplicateCount} duplicate";
        }

        $this->line(implode(', ', $parts));
    }

    /**
     * @param  array<string, mixed>  $values
     */
    private function numericValue(array $values, string $key): ?float
    {
        return is_numeric($values[$key] ?? null) ? (float) $values[$key] : null;
    }

    /**
     * @param  array<string, mixed>  $timings
     */
    private function formatMsValue(array $timings, string $key): string
    {
        $value = is_numeric($timings[$key] ?? null) ? (float) $timings[$key] : 0.0;

        return $this->formatMs($value).'ms';
    }

    private function formatMs(float $value): string
    {
        return number_format($value, 2, '.', '');
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function jsonSuccess(array $data): int
    {
        $this->line(json_encode([
            'success' => [
                'data' => $data,
                'meta' => [
                    'warnings' => [],
                ],
            ],
        ], JSON_THROW_ON_ERROR));

        return self::SUCCESS;
    }

    /**
     * @param  array<string, mixed>  $meta
     * @param  array<string, mixed>  $data
     */
    private function failCommand(string $code, string $message, array $meta, array $data = []): int
    {
        if ($this->wantsJson()) {
            $payload = [
                'error' => [
                    'code' => $code,
                    'message' => $message,
                ],
            ];

            if ($data !== []) {
                $payload['error']['data'] = $data;
            }

            $payload['error']['meta'] = empty($meta) ? (object) [] : $meta;

            $this->line(json_encode($payload, JSON_THROW_ON_ERROR));

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
