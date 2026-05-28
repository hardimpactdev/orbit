<?php

declare(strict_types=1);

namespace App\Commands\Cloudflare;

use App\Exceptions\GatewayApiException;

final class CfDnsRemoveCommand extends CloudflareGatewayCommand
{
    #[\Override]
    protected $signature = 'cf-dns:remove
        {record-id? : Cloudflare DNS record ID}
        {--zone= : Cloudflare zone ID or domain}
        {--force : Confirm destructive operation without prompting}
        {--json : Output JSON}';

    #[\Override]
    protected $description = 'Remove a Cloudflare address DNS record through the gateway.';

    public function handle(): int
    {
        $recordId = $this->requiredArgument('record-id', 'record_id', 'A Cloudflare DNS record ID and zone are required.');

        if (is_int($recordId)) {
            return $recordId;
        }

        $zone = $this->stringOption('zone');

        if ($zone === null) {
            return $this->failValidation('zone', 'A Cloudflare DNS record ID and zone are required.');
        }

        $consent = $this->confirmDestructive('Removing a Cloudflare DNS record requires --force in non-interactive mode.');

        if (is_int($consent)) {
            return $consent;
        }

        try {
            $response = $this->gatewayDelete(
                '/api/cloudflare/zones/'.rawurlencode($zone).'/dns/'.rawurlencode($recordId),
                ['destructive_consent' => true],
            );
        } catch (GatewayApiException $exception) {
            return $this->renderGatewayFailure($exception);
        }

        return $this->renderSuccess($response);
    }
}
