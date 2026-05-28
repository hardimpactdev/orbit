<?php

declare(strict_types=1);

namespace App\Commands\Cloudflare;

use App\Exceptions\GatewayApiException;

final class CfCacheRuleAddCommand extends CloudflareGatewayCommand
{
    #[\Override]
    protected $signature = 'cf-cache-rule:add
        {app? : Orbit app name}
        {--json : Output JSON}';

    #[\Override]
    protected $description = 'Add or converge a Cloudflare cache rule for an app through the gateway.';

    public function handle(): int
    {
        $app = $this->requiredArgument('app', 'app', 'An app name is required.');

        if (is_int($app)) {
            return $app;
        }

        try {
            $response = $this->gatewayPost('/api/cloudflare/cache-rules/'.rawurlencode($app));
        } catch (GatewayApiException $exception) {
            return $this->renderGatewayFailure($exception);
        }

        return $this->renderSuccess($response);
    }
}
