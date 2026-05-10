<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Actions\Profile\ShowProfile;
use App\Concerns\LogsCommandActivity;
use App\Contracts\Loggable;
use App\Enums\ActivityLogType;
use App\Http\Gateway\GatewayApiException;
use App\Http\Gateway\GatewayConnector;
use App\Http\Gateway\Requests\Apps\ListAppsRequest;
use App\Http\Gateway\Requests\Apps\ShowAppRequest;
use App\Http\Gateway\Requests\Profile\ShowProfileRequest;
use App\Models\App;
use App\Models\Node;
use App\Models\Workspace;
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
class ProfileCommand extends Command implements Loggable
{
    use LogsCommandActivity;

    private const int LINE_WIDTH = 48;

    private const string DOT_STYLE = "\e[2m";

    private const string RESET_STYLE = "\e[0m";

    private ?string $activityTarget = null;

    private ?string $activityApp = null;

    private ?string $activityNode = null;

    private ?string $activityDomain = null;

    private ?string $activityUri = null;

    private ?string $activityOrigin = null;

    private ?int $activityStatusCode = null;

    public function handle(ShowProfile $profile): int
    {
        $this->bootActivityLog();

        try {
            return $this->executeProfile($profile);
        } finally {
            $this->finishActivityLog();
        }
    }

    public function effect(): ActivityLogType
    {
        return ActivityLogType::Read;
    }

    /**
     * @return array<string, mixed>
     */
    public function properties(): array
    {
        return array_filter([
            'target' => $this->activityTarget ?? $this->stringOption('app') ?? $this->stringArgument('target'),
            'app' => $this->activityApp,
            'node' => $this->activityNode,
            'domain' => $this->activityDomain,
            'uri' => $this->activityUri ?? $this->stringOption('uri') ?? '/',
            'origin' => $this->activityOrigin,
            'auth_mode' => $this->authMode(),
            'status_code' => $this->activityStatusCode,
        ], static fn (mixed $value): bool => $value !== null && $value !== '');
    }

    public function description(): ?string
    {
        if ($this->activityApp !== null) {
            return "Profiled {$this->activityApp}";
        }

        return 'Profile request attempted';
    }

    private function executeProfile(ShowProfile $profile): int
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
        $this->activityUri = $uri;

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
        $this->activityTarget = $selector;
        $selectorWasOmitted = $selector === null;
        $nodeConstraint = $this->stringOption('node');
        $this->activityNode = $nodeConstraint;

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
            $this->activityTarget = $selector;
            $this->activityUri = $uri;
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
                $this->captureActivitySuccess($gatewayResult);

                return $this->jsonSuccess($gatewayResult);
            }

            $this->captureActivitySuccess($gatewayResult);
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
                    $workspace = $this->resolveWorkspaceFromSelector($selector);
                    $url = $workspace instanceof Workspace
                        ? $workspace->url().$uri
                        : $this->profileUrl($app, $uri);
                    $targetPayload = [
                        'app' => $app->name,
                        'workspace' => $workspace?->name,
                        'node' => $app->node?->name,
                        'domain' => $workspace instanceof Workspace
                            ? parse_url($workspace->url(), PHP_URL_HOST) ?? $this->appDomain($app)
                            : $this->appDomain($app),
                    ];
                    $origin = 'gateway';
                    $this->captureActivityTarget($targetPayload, $origin);
                } else {
                    return $this->failCommand(
                        code: 'target_not_found',
                        message: 'No linked app found for the requested profile target.',
                        meta: ['target' => $selector],
                    );
                }
            } else {
                $workspace = $this->resolveWorkspaceFromSelector($selector);
                $url = $workspace instanceof Workspace
                    ? $workspace->url().$uri
                    : $this->profileUrl($app, $uri);
                $targetPayload = [
                    'app' => $app->name,
                    'workspace' => $workspace?->name,
                    'node' => $app->node?->name,
                    'domain' => $workspace instanceof Workspace
                        ? parse_url($workspace->url(), PHP_URL_HOST) ?? $this->appDomain($app)
                        : $this->appDomain($app),
                ];
                $origin = 'gateway';
                $this->captureActivityTarget($targetPayload, $origin);
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
            $this->captureActivityTarget($targetPayload, $origin);
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
                        $this->captureActivitySuccess($gatewayResult);

                        return $this->jsonSuccess($gatewayResult);
                    }

                    $this->captureActivitySuccess($gatewayResult);
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
            $this->captureActivitySuccess($data);

            return $this->jsonSuccess($data);
        }

        $this->captureActivitySuccess($data);
        $this->renderHuman($data);

        return self::SUCCESS;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function captureActivitySuccess(array $data): void
    {
        $target = is_array($data['target'] ?? null) ? $data['target'] : [];
        $request = is_array($data['request'] ?? null) ? $data['request'] : [];

        $this->captureActivityTarget($target, is_string($data['origin'] ?? null) ? $data['origin'] : $this->activityOrigin);
        $this->activityStatusCode = is_numeric($request['status'] ?? null) ? (int) $request['status'] : null;
    }

    /**
     * @param  array<string, mixed>  $target
     */
    private function captureActivityTarget(array $target, ?string $origin): void
    {
        $this->activityApp = is_string($target['app'] ?? null) ? $target['app'] : $this->activityApp;
        $this->activityNode = is_string($target['node'] ?? null) ? $target['node'] : $this->activityNode;
        $this->activityDomain = is_string($target['domain'] ?? null) ? $target['domain'] : $this->activityDomain;
        $this->activityOrigin = $origin;
    }

    private function finishActivityLog(): void
    {
        try {
            $this->finalizeActivityLog();
        } catch (Throwable) {
            // Activity logging must not change the documented profile result.
        }
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

    private function resolveWorkspaceFromSelector(?string $selector): ?Workspace
    {
        if (! is_string($selector) || $selector === '' || ! str_starts_with($selector, '/')) {
            return null;
        }

        $normalizedSelector = realpath($selector) ?: $selector;

        return Workspace::query()
            ->get()
            ->first(function (Workspace $workspace) use ($normalizedSelector): bool {
                $workspacePath = realpath($workspace->path) ?: $workspace->path;

                return $workspacePath === $normalizedSelector || str_starts_with($normalizedSelector, rtrim($workspacePath, '/').'/');
            });
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
        $responseHeaders = is_array($data['response_headers'] ?? null) ? $data['response_headers'] : [];
        $method = is_string($request['method'] ?? null) && $request['method'] !== '' ? $request['method'] : 'GET';
        $url = is_string($request['url'] ?? null) ? $request['url'] : '';
        $status = $request['status'] ?? '-';
        $totalMs = $this->timingMs($timings, 'total_ms');
        $dnsMs = $this->timingMs($timings, 'dns_ms');
        $connectTotalMs = $this->timingMs($timings, 'connect_ms');
        $tlsMs = $this->timingMs($timings, 'tls_ms');
        $waitingMs = max(0.0, $this->timingMs($timings, 'ttfb_ms') - $connectTotalMs - $tlsMs);
        $bytes = is_numeric($request['bytes'] ?? null) ? (int) $request['bytes'] : 0;
        $downloadSuffix = ' - '.$this->formatBytes($bytes);

        $this->newLine();
        $this->line("{$method} {$url} {$status} in ".$this->formatMs($totalMs).'ms');
        $this->newLine();

        $this->dottedLine('DNS', $this->formatMs($dnsMs).'ms');
        $this->dottedLine('Connect', $this->formatMs(max(0.0, $connectTotalMs - $dnsMs)).'ms');
        $this->dottedLine('TLS', $this->formatMs($tlsMs).'ms');

        if (is_array($data['toolbar'] ?? null)) {
            $this->renderEnrichedTimeline($waitingMs, $timings, $data['toolbar'], $responseHeaders, $downloadSuffix);
        } else {
            $this->dottedLine('Waiting for response', $this->formatMs($waitingMs).'ms');
            $this->dottedLine('Download response', $this->formatMs($this->timingMs($timings, 'download_ms')).'ms', suffix: $downloadSuffix);
            $this->dottedLine('Total', $this->formatMs($totalMs).'ms');
        }

        $this->newLine();
    }

    /**
     * @param  array<string, mixed>  $timings
     * @param  array<string, mixed>  $toolbar
     * @param  array<string, mixed>  $responseHeaders
     */
    private function renderEnrichedTimeline(float $waitingMs, array $timings, array $toolbar, array $responseHeaders, string $downloadSuffix): void
    {
        $this->dottedLine('Waiting for response', $this->formatMs($waitingMs).'ms');

        $anchors = is_array($toolbar['timing_anchors'] ?? null) ? $toolbar['timing_anchors'] : [];
        $caddyStart = $this->numericValue($anchors, 'caddy_start_ms');
        $phpStart = $this->numericValue($anchors, 'php_start_ms');
        $laravelStart = $this->numericValue($anchors, 'laravel_start_ms');
        $profilerEnd = $this->numericValue($anchors, 'profiler_end_ms');
        $collectedAt = $this->numericValue($anchors, 'collected_at_ms');
        $accountedMs = 0.0;

        if ($caddyStart !== null && $phpStart !== null) {
            $caddyMs = max(0.0, $phpStart - $caddyStart);
            $accountedMs += $caddyMs;
            $this->dottedLine('Caddy in', $this->formatMs($caddyMs).'ms', indent: 2, dim: true);
        }

        if ($phpStart !== null && $laravelStart !== null) {
            $phpBootMs = max(0.0, $laravelStart - $phpStart);
            $accountedMs += $phpBootMs;
            $this->dottedLine('PHP-FPM', $this->formatMs($phpBootMs).'ms', indent: 2, dim: true);
        }

        $profiler = is_array($toolbar['profiler'] ?? null) ? $toolbar['profiler'] : [];
        $stages = is_array($profiler['stages'] ?? null) ? $profiler['stages'] : [];

        foreach ($stages as $stage) {
            if (! is_array($stage) || ! is_string($stage['label'] ?? null)) {
                continue;
            }

            $duration = $this->stageDurationMs($stage);

            if ($duration === null) {
                continue;
            }

            $accountedMs += $duration;
            $this->dottedLine($stage['label'], $this->formatMs($duration).'ms', indent: 2, dim: true);
        }

        if ($profilerEnd !== null && $collectedAt !== null) {
            $toolbarMs = max(0.0, $collectedAt - $profilerEnd);
            $accountedMs += $toolbarMs;
            $this->dottedLine('Toolbar', $this->formatMs($toolbarMs).'ms', indent: 2, dim: true);
        }

        $caddyEnd = $this->headerFloat($responseHeaders, 'x-caddy-end');

        if ($caddyEnd !== null && $collectedAt !== null) {
            $caddyOutMs = max(0.0, $caddyEnd - $collectedAt);
            $accountedMs += $caddyOutMs;
            $this->dottedLine('Caddy out', $this->formatMs($caddyOutMs).'ms', indent: 2, dim: true);
        }

        if ($accountedMs > 0.0) {
            $this->dottedLine('Transport', $this->formatMs(max(0.0, $waitingMs - $accountedMs)).'ms', indent: 2, dim: true);
        }

        $this->dottedLine('Download response', $this->formatMs($this->timingMs($timings, 'download_ms')).'ms', suffix: $downloadSuffix);
        $this->dottedLine('Total', $this->formatMs($this->timingMs($timings, 'total_ms')).'ms');

        $queries = is_array($toolbar['queries'] ?? null) ? $toolbar['queries'] : [];
        $querySummary = $this->toolbarQuerySummary($queries);

        if ($querySummary !== null) {
            $this->newLine();
            $this->line($querySummary);
        }
    }

    /**
     * @param  array<string, mixed>  $queries
     */
    private function toolbarQuerySummary(array $queries): ?string
    {
        $count = (int) ($queries['count'] ?? 0);

        if ($count <= 0) {
            return null;
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

        return implode(', ', $parts);
    }

    /**
     * @param  array<string, mixed>  $values
     */
    private function numericValue(array $values, string $key): ?float
    {
        return is_numeric($values[$key] ?? null) ? (float) $values[$key] : null;
    }

    /**
     * @param  array<string, mixed>  $stage
     */
    private function stageDurationMs(array $stage): ?float
    {
        if (is_numeric($stage['duration_ms'] ?? null)) {
            return (float) $stage['duration_ms'];
        }

        if (is_numeric($stage['duration'] ?? null)) {
            return (float) $stage['duration'];
        }

        if (is_string($stage['duration'] ?? null)) {
            $duration = rtrim($stage['duration'], 'ms');

            return is_numeric($duration) ? (float) $duration : null;
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $headers
     */
    private function headerFloat(array $headers, string $name): ?float
    {
        $value = $headers[$name] ?? $headers[strtolower($name)] ?? $headers[mb_convert_case($name, MB_CASE_TITLE)] ?? null;

        return is_numeric($value) ? (float) $value : null;
    }

    /**
     * @param  array<string, mixed>  $timings
     */
    private function timingMs(array $timings, string $key): float
    {
        return is_numeric($timings[$key] ?? null) ? (float) $timings[$key] : 0.0;
    }

    private function formatMs(float $value): string
    {
        return number_format($value, 2, '.', '');
    }

    private function formatBytes(int $bytes): string
    {
        if ($bytes >= 1_048_576) {
            return number_format($bytes / 1_048_576, 2).'MB';
        }

        if ($bytes >= 1024) {
            return number_format($bytes / 1024, 1).'KB';
        }

        return "{$bytes}B";
    }

    private function dottedLine(string $label, string $value, int $indent = 0, bool $dim = false, string $suffix = ''): void
    {
        $prefix = str_repeat('  ', $indent);
        $labelWithPrefix = "{$prefix}{$label} ";
        $valuePart = " {$value}";
        $dotsNeeded = max(1, self::LINE_WIDTH - mb_strlen($labelWithPrefix) - mb_strlen($valuePart));
        $dots = str_repeat('.', $dotsNeeded);

        if ($dim && $this->output->isDecorated()) {
            $this->output->writeln(self::DOT_STYLE.$labelWithPrefix.$dots.$valuePart.self::RESET_STYLE.$suffix);

            return;
        }

        $formattedDots = $this->output->isDecorated()
            ? self::DOT_STYLE.$dots.self::RESET_STYLE
            : $dots;

        $this->output->writeln($labelWithPrefix.$formattedDots.$valuePart.$suffix);
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
