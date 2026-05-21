<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Http\Gateway\Requests\Cloudflare\CloudflareRequest;
use App\Services\Cloudflare\CloudflareManager;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Saloon\Enums\Method;

#[Signature('cf-zone:list {--json : Output JSON}')]
#[Description('List Cloudflare zones visible to the gateway token')]
final class CfZoneListCommand extends CloudflareCommand
{
    public function handle(CloudflareManager $cloudflare): int
    {
        $result = $this->resolveCloudflareResult(
            gatewayRequest: new CloudflareRequest(Method::GET, '/api/cloudflare/zones'),
            local: fn (): array => $cloudflare->listZones(),
            gatewayFailureMessage: 'Gateway connection is required to list Cloudflare zones.',
        );

        if (is_int($result)) {
            return $result;
        }

        return $this->successPayload($result['data'], $result['meta'], function (array $data): void {
            foreach ($data['zones'] ?? [] as $zone) {
                if (is_array($zone)) {
                    $this->line(sprintf('%s  %s  %s', $zone['id'] ?? '', $zone['name'] ?? '', $zone['status'] ?? ''));
                }
            }
        });
    }
}
