<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Http\Gateway\Requests\Cloudflare\CloudflareRequest;
use App\Services\Cloudflare\CloudflareManager;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Saloon\Enums\Method;

#[Signature('cf-dns:add
    {name? : DNS record name}
    {content? : IP address for the record}
    {--type=A : DNS record type, A or AAAA}
    {--zone= : Cloudflare zone ID or domain}
    {--proxied : Enable Cloudflare proxy}
    {--json : Output JSON}')]
#[Description('Add a Cloudflare address DNS record')]
final class CfDnsAddCommand extends CloudflareCommand
{
    public function handle(CloudflareManager $cloudflare): int
    {
        $name = $this->stringArgument('name');
        $content = $this->stringArgument('content');

        if ($name === null || $content === null) {
            return $this->failValidation($name === null ? 'name' : 'content', 'DNS record name and content are required.');
        }

        $type = $this->stringOption('type') ?? 'A';
        $zone = $this->stringOption('zone');
        $proxied = $this->option('proxied') === true;
        $endpointZone = $zone ?? $name;

        $result = $this->resolveCloudflareResult(
            gatewayRequest: new CloudflareRequest(Method::POST, "/api/cloudflare/zones/{$endpointZone}/dns", [
                'name' => $name,
                'content' => $content,
                'type' => $type,
                'proxied' => $proxied,
            ]),
            local: fn (): array => $cloudflare->addDnsRecord($name, $content, $type, $zone, $proxied),
            gatewayFailureMessage: 'Gateway connection is required to add Cloudflare DNS records.',
        );

        if (is_int($result)) {
            return $result;
        }

        return $this->successPayload($result['data'], $result['meta'], function (array $data): void {
            $record = is_array($data['record'] ?? null) ? $data['record'] : [];
            $this->line("Cloudflare DNS record '".(string) ($record['name'] ?? '')."' saved.");
        });
    }
}
