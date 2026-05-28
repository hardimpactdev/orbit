<?php

declare(strict_types=1);

namespace App\Commands\Cloudflare;

use App\Commands\GatewayCommand;
use App\Exceptions\GatewayApiException;

final class CfZoneListCommand extends GatewayCommand
{
    #[\Override]
    protected $signature = 'cf-zone:list {--json}';

    #[\Override]
    protected $description = 'List Cloudflare zones visible to the gateway token.';

    public function handle(): int
    {
        try {
            $response = $this->gatewayGet('/api/cloudflare/zones');
        } catch (GatewayApiException $exception) {
            return $this->renderGatewayFailure($exception);
        }

        return $this->renderSuccess($response);
    }
}
