<?php

declare(strict_types=1);

namespace App\Commands\Proxy;

use App\Commands\Concerns\WithStepTree;
use App\Exceptions\GatewayApiException;
use RuntimeException;

use function Laravel\Prompts\select;
use function Laravel\Prompts\text;

final class ProxyAddCommand extends ProxyGatewayCommand
{
    use WithStepTree;

    private const array REDIRECT_CODES = [301, 302, 307, 308];

    #[\Override]
    protected $signature = 'proxy:add
        {domain? : Proxy route domain}
        {--node= : Serving node}
        {--upstream= : HTTP or HTTPS upstream URL}
        {--redirect= : HTTP or HTTPS redirect URL}
        {--code= : Redirect status code}
        {--force : Replace an existing custom route with different intent}
        {--json : Output JSON}';

    #[\Override]
    protected $description = 'Create or update custom proxy route intent.';

    public function handle(): int
    {
        $domain = $this->resolveDomain();

        if ($domain === null) {
            return $this->failValidation('domain', 'The proxy route domain is required.');
        }

        $node = $this->resolveServingNode();

        if (is_int($node)) {
            return $node;
        }

        $target = $this->resolveTarget();

        if (is_int($target)) {
            return $target;
        }

        $codeResult = $this->resolveCode($target['upstream']);

        if (is_int($codeResult)) {
            return $codeResult;
        }

        $code = $codeResult['code'];

        $payload = $this->filledQuery([
            'domain' => $domain,
            'node' => $node,
            'upstream' => $target['upstream'],
            'redirect' => $target['redirect'],
            'code' => $code,
            'force' => $this->option('force') === true,
        ]);

        if ($this->wantsJson()) {
            try {
                $response = $this->addRoute($payload);
            } catch (GatewayApiException $exception) {
                return $this->renderGatewayFailure($exception);
            }

            return $this->renderSuccess($response);
        }

        return $this->renderAddTree($domain, $payload);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function renderAddTree(string $domain, array $payload): int
    {
        $response = [];

        $outcome = $this->runStepOperation(
            'Adding Proxy Route',
            [
                ['label' => 'Validate proxy route'],
                ['label' => 'Check ownership boundary'],
                ['label' => 'Apply and verify proxy route'],
                ['label' => 'Apply and verify TLS material'],
            ],
            work: function () use ($payload, &$response): array {
                return $response = $this->addRouteForHuman($payload);
            },
            doneFooter: function () use ($domain, &$response): string {
                return "Proxy route '{$domain}' "
                    .($this->action($response) === 'updated' ? 'updated' : 'added');
            },
        );

        if (! $outcome->isCompleted()) {
            return self::FAILURE;
        }

        $this->renderRouteDetail($response);

        return self::SUCCESS;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function addRoute(array $payload): array
    {
        return $this->gatewayPost('/api/proxy-routes', $payload);
    }

    /**
     * Run the gateway write inside the progress tree, re-throwing gateway
     * failures with their operator-facing message so the failed footer renders
     * the documented prose rather than a JSON envelope.
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function addRouteForHuman(array $payload): array
    {
        try {
            return $this->addRoute($payload);
        } catch (GatewayApiException $exception) {
            throw new RuntimeException(
                $exception->gatewayErrorMessage() ?? $exception->getMessage(),
                previous: $exception,
            );
        }
    }

    /**
     * Render the documented route detail block after a successful apply: domain,
     * kind, owner, serving node, target, redirect code, TLS summary, and the
     * backend apply result.
     *
     * @param  array<string, mixed>  $response
     */
    private function renderRouteDetail(array $response): void
    {
        $route = $this->routeData($response);

        if ($route === []) {
            return;
        }

        $this->line('  Domain: '.$this->stringField($route, 'domain'));
        $this->line('  Kind: '.$this->stringField($route, 'kind'));
        $this->line('  Owner: '.$this->ownerSummary($route));
        $this->line('  Serving node: '.$this->stringField($route, 'node'));
        $this->line('  Target: '.$this->targetSummary($route));

        if (is_int($route['redirect_code'] ?? null)) {
            $this->line('  Redirect code: '.$route['redirect_code']);
        }

        $this->line('  TLS: '.$this->tlsSummary($route));
        $this->line('  Backend apply: '.$this->stringField($route, 'status'));
    }

    /**
     * @param  array<string, mixed>  $route
     */
    private function ownerSummary(array $route): string
    {
        $owner = $route['owner'] ?? null;

        if (! is_array($owner)) {
            return '-';
        }

        $type = is_string($owner['type'] ?? null) ? $owner['type'] : '-';
        $name = is_string($owner['name'] ?? null) && $owner['name'] !== '' ? $owner['name'] : null;

        return $name === null ? $type : "{$type} ({$name})";
    }

    /**
     * @param  array<string, mixed>  $route
     */
    private function targetSummary(array $route): string
    {
        $target = $route['target'] ?? null;

        if (! is_array($target)) {
            return '-';
        }

        $type = is_string($target['type'] ?? null) ? $target['type'] : '-';
        $value = is_string($target['value'] ?? null) ? $target['value'] : '-';

        return "{$type} {$value}";
    }

    /**
     * @param  array<string, mixed>  $route
     */
    private function tlsSummary(array $route): string
    {
        $tls = $route['tls'] ?? null;

        if (! is_array($tls)) {
            return '-';
        }

        $managedBy = is_string($tls['managed_by'] ?? null) ? $tls['managed_by'] : '-';
        $trusted = ($tls['trusted_by_gateway_ca'] ?? null) === true
            ? 'trusted by gateway CA'
            : 'not trusted by gateway CA';

        return "managed by {$managedBy}, {$trusted}";
    }

    /**
     * @param  array<string, mixed>  $route
     */
    private function stringField(array $route, string $key): string
    {
        return is_string($route[$key] ?? null) && $route[$key] !== '' ? $route[$key] : '-';
    }

    /**
     * @param  array<string, mixed>  $response
     * @return array<string, mixed>
     */
    private function routeData(array $response): array
    {
        $data = $response['success']['data'] ?? null;
        $route = is_array($data) ? ($data['route'] ?? null) : null;

        return is_array($route) ? $route : [];
    }

    /**
     * @param  array<string, mixed>  $response
     */
    private function action(array $response): ?string
    {
        $meta = $response['success']['meta'] ?? null;
        $action = is_array($meta) ? ($meta['action'] ?? null) : null;

        return is_string($action) ? $action : null;
    }

    private function resolveDomain(): ?string
    {
        $domain = $this->stringArgument('domain');

        if ($domain !== null) {
            return $domain;
        }

        if ($this->isInteractiveInput()) {
            return trim(text(label: 'Domain', required: true));
        }

        return null;
    }

    /**
     * @return array{upstream: ?string, redirect: ?string}|int
     */
    private function resolveTarget(): array|int
    {
        $upstream = $this->stringOption('upstream');
        $redirect = $this->stringOption('redirect');

        if ($upstream !== null && $redirect !== null) {
            return $this->renderTargetSelectionFailure();
        }

        if ($upstream !== null || $redirect !== null) {
            return [
                'upstream' => $upstream,
                'redirect' => $redirect,
            ];
        }

        if (! $this->isInteractiveInput()) {
            return $this->renderTargetSelectionFailure();
        }

        $routeShape = (string) select(
            label: 'Route type',
            options: ['upstream' => 'Upstream', 'redirect' => 'Redirect'],
            default: 'upstream',
        );

        if ($routeShape === 'upstream') {
            return [
                'upstream' => trim(text(label: 'Upstream URL', required: true)),
                'redirect' => null,
            ];
        }

        return [
            'upstream' => null,
            'redirect' => trim(text(label: 'Redirect URL', required: true)),
        ];
    }

    /**
     * @return array{code: ?int}|int
     */
    private function resolveCode(?string $upstream): array|int
    {
        $code = $this->scalarOption('code');

        if ($code === null) {
            return ['code' => null];
        }

        if ($upstream !== null) {
            return $this->failValidation('code', '--code may only be used with --redirect.');
        }

        $redirectCode = ctype_digit($code) ? (int) $code : null;

        if ($redirectCode === null || ! in_array($redirectCode, self::REDIRECT_CODES, true)) {
            return $this->renderFailure('validation_failed', 'Invalid redirect code.', [
                'field' => 'code',
                'allowed' => self::REDIRECT_CODES,
            ]);
        }

        return ['code' => $redirectCode];
    }

    private function renderTargetSelectionFailure(): int
    {
        return $this->renderFailure(
            'validation_failed',
            'Select exactly one of --upstream or --redirect.',
            ['fields' => ['upstream', 'redirect']],
        );
    }
}
