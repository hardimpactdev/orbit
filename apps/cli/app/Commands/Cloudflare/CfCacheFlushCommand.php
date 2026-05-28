<?php

declare(strict_types=1);

namespace App\Commands\Cloudflare;

use App\Exceptions\GatewayApiException;

final class CfCacheFlushCommand extends CloudflareGatewayCommand
{
    #[\Override]
    protected $signature = 'cf-cache:flush
        {--zone= : Cloudflare zone ID, domain, or app name}
        {--json : Output JSON}';

    #[\Override]
    protected $description = 'Flush Cloudflare cache for a zone through the gateway.';

    public function handle(): int
    {
        $zone = $this->stringOption('zone');

        if ($zone === null && ! $this->wantsJson() && $this->input->isInteractive()) {
            $answer = $this->ask('Cloudflare zone');
            $zone = is_string($answer) && trim($answer) !== '' ? trim($answer) : null;
        }

        if ($zone === null) {
            return $this->failValidation('zone', 'A Cloudflare zone is required.');
        }

        try {
            $response = $this->gatewayPost('/api/cloudflare/cache/flush', ['zone' => $zone]);
        } catch (GatewayApiException $exception) {
            return $this->renderGatewayFailure($exception);
        }

        return $this->renderSuccess($response);
    }
}
