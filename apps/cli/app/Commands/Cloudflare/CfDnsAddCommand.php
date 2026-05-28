<?php

declare(strict_types=1);

namespace App\Commands\Cloudflare;

use App\Exceptions\GatewayApiException;

final class CfDnsAddCommand extends CloudflareGatewayCommand
{
    #[\Override]
    protected $signature = 'cf-dns:add
        {name? : DNS record name}
        {content? : IP address for the record}
        {--type=A : DNS record type, A or AAAA}
        {--zone= : Cloudflare zone ID or domain}
        {--proxied : Enable Cloudflare proxy}
        {--json : Output JSON}';

    #[\Override]
    protected $description = 'Add a Cloudflare address DNS record through the gateway.';

    public function handle(): int
    {
        $name = $this->stringArgument('name');
        $content = $this->stringArgument('content');

        if ($name === null || $content === null) {
            return $this->failValidation($name === null ? 'name' : 'content', 'DNS record name and content are required.');
        }

        $endpointZone = $this->stringOption('zone') ?? $name;

        try {
            $response = $this->gatewayPost('/api/cloudflare/zones/'.rawurlencode($endpointZone).'/dns', [
                'name' => $name,
                'content' => $content,
                'type' => $this->stringOption('type') ?? 'A',
                'proxied' => $this->option('proxied') === true,
            ]);
        } catch (GatewayApiException $exception) {
            return $this->renderGatewayFailure($exception);
        }

        return $this->renderSuccess($response);
    }
}
