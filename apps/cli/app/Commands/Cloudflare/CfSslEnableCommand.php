<?php

declare(strict_types=1);

namespace App\Commands\Cloudflare;

use App\Exceptions\GatewayApiException;

final class CfSslEnableCommand extends CloudflareGatewayCommand
{
    #[\Override]
    protected $signature = 'cf-ssl:enable
        {zone? : Cloudflare zone ID or domain}
        {--mode=strict : SSL mode, strict or full}
        {--json : Output JSON}';

    #[\Override]
    protected $description = 'Enable Cloudflare SSL mode for a zone through the gateway.';

    public function handle(): int
    {
        $zone = $this->requiredArgument('zone', 'zone', 'A Cloudflare zone is required.');

        if (is_int($zone)) {
            return $zone;
        }

        try {
            $response = $this->gatewayPut('/api/cloudflare/zones/'.rawurlencode($zone).'/ssl', [
                'mode' => $this->stringOption('mode') ?? 'strict',
            ]);
        } catch (GatewayApiException $exception) {
            return $this->renderGatewayFailure($exception);
        }

        return $this->renderSuccess($response);
    }
}
