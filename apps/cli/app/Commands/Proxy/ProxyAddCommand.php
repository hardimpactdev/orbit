<?php

declare(strict_types=1);

namespace App\Commands\Proxy;

use App\Exceptions\GatewayApiException;

use function Laravel\Prompts\select;
use function Laravel\Prompts\text;

final class ProxyAddCommand extends ProxyGatewayCommand
{
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

        try {
            $response = $this->gatewayPost('/api/proxy-routes', $this->filledQuery([
                'domain' => $domain,
                'node' => $node,
                'upstream' => $target['upstream'],
                'redirect' => $target['redirect'],
                'code' => $code,
                'force' => $this->option('force') === true,
            ]));
        } catch (GatewayApiException $exception) {
            return $this->renderGatewayFailure($exception);
        }

        return $this->renderSuccess($response);
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
