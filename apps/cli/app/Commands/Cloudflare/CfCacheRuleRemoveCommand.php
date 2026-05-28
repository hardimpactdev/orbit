<?php

declare(strict_types=1);

namespace App\Commands\Cloudflare;

use App\Exceptions\GatewayApiException;

final class CfCacheRuleRemoveCommand extends CloudflareGatewayCommand
{
    #[\Override]
    protected $signature = 'cf-cache-rule:remove
        {app? : Orbit app name}
        {--force : Confirm destructive operation without prompting}
        {--json : Output JSON}';

    #[\Override]
    protected $description = 'Remove a Cloudflare cache rule for an app through the gateway.';

    public function handle(): int
    {
        $app = $this->requiredArgument('app', 'app', 'An app name is required.');

        if (is_int($app)) {
            return $app;
        }

        $consent = $this->confirmDestructive('Removing a Cloudflare cache rule requires --force in non-interactive mode.');

        if (is_int($consent)) {
            return $consent;
        }

        try {
            $response = $this->gatewayDelete('/api/cloudflare/cache-rules/'.rawurlencode($app), [
                'destructive_consent' => true,
            ]);
        } catch (GatewayApiException $exception) {
            return $this->renderGatewayFailure($exception);
        }

        return $this->renderSuccess($response);
    }
}
