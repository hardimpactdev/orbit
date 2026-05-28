<?php

declare(strict_types=1);

namespace App\Commands\Cloudflare;

use App\Exceptions\GatewayApiException;

final class CfSslDisableCommand extends CloudflareGatewayCommand
{
    #[\Override]
    protected $signature = 'cf-ssl:disable
        {zone? : Cloudflare zone ID or domain}
        {--force : Confirm destructive operation without prompting}
        {--json : Output JSON}';

    #[\Override]
    protected $description = 'Disable Cloudflare SSL for a zone through the gateway.';

    public function handle(): int
    {
        $zone = $this->requiredArgument('zone', 'zone', 'A Cloudflare zone is required.');

        if (is_int($zone)) {
            return $zone;
        }

        $consent = $this->confirmDestructive('Disabling Cloudflare SSL requires --force in non-interactive mode.');

        if (is_int($consent)) {
            return $consent;
        }

        try {
            $response = $this->gatewayPut('/api/cloudflare/zones/'.rawurlencode($zone).'/ssl/disable', [
                'destructive_consent' => true,
            ]);
        } catch (GatewayApiException $exception) {
            return $this->renderGatewayFailure($exception);
        }

        return $this->renderSuccess($response);
    }
}
