<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Http\Gateway\Requests\Cloudflare\CloudflareRequest;
use App\Services\Cloudflare\CloudflareManager;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Saloon\Enums\Method;

#[Signature('cf-ssl:enable
    {zone? : Cloudflare zone ID or domain}
    {--mode=strict : SSL mode, strict or full}
    {--json : Output JSON}')]
#[Description('Enable Cloudflare SSL mode for a zone')]
final class CfSslEnableCommand extends CloudflareCommand
{
    public function handle(CloudflareManager $cloudflare): int
    {
        $zone = $this->stringArgument('zone');

        if ($zone === null) {
            return $this->failValidation('zone', 'A Cloudflare zone is required.');
        }

        $mode = $this->stringOption('mode') ?? 'strict';

        $result = $this->resolveCloudflareResult(
            gatewayRequest: new CloudflareRequest(Method::PUT, "/api/cloudflare/zones/{$zone}/ssl", ['mode' => $mode]),
            local: fn (): array => $cloudflare->enableSsl($zone, $mode),
            gatewayFailureMessage: 'Gateway connection is required to manage Cloudflare SSL.',
        );

        if (is_int($result)) {
            return $result;
        }

        return $this->successPayload($result['data'], $result['meta'], function (array $data): void {
            $ssl = is_array($data['ssl'] ?? null) ? $data['ssl'] : [];
            $this->line("Cloudflare SSL set to '".(string) ($ssl['mode'] ?? '')."' for '".(string) ($ssl['zone'] ?? '')."'.");
        });
    }
}
