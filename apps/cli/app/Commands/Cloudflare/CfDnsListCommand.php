<?php

declare(strict_types=1);

namespace App\Commands\Cloudflare;

use App\Commands\Concerns\ResolvesHostContext;
use App\Commands\GatewayCommand;
use App\Exceptions\GatewayApiException;

final class CfDnsListCommand extends GatewayCommand
{
    use ResolvesHostContext;

    #[\Override]
    protected $signature = 'cf-dns:list {zone? : Cloudflare zone ID or domain} {--json}';

    #[\Override]
    protected $description = 'List Cloudflare DNS records for a zone.';

    public function handle(): int
    {
        $zone = $this->stringArgument('zone');

        if ($zone === null) {
            return $this->renderFailure('validation_failed', 'The zone argument is required.', ['field' => 'zone']);
        }

        try {
            $response = $this->gatewayGet('/api/cloudflare/zones/'.rawurlencode($zone).'/dns');
        } catch (GatewayApiException $exception) {
            return $this->renderGatewayFailure($exception);
        }

        return $this->renderSuccess($response);
    }
}
