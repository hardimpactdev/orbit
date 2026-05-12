<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Http\Gateway\Requests\Cloudflare\CloudflareRequest;
use App\Services\Cloudflare\CloudflareManager;
use App\Services\Nodes\CallerRoleResolver;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Saloon\Enums\Method;

#[Signature('cf-dns:list
    {zone? : Cloudflare zone ID or domain}
    {--json : Output JSON}')]
#[Description('List Cloudflare DNS records for a zone')]
final class CfDnsListCommand extends CloudflareCommand
{
    public function handle(CloudflareManager $cloudflare, CallerRoleResolver $callerRoleResolver): int
    {
        $zone = $this->stringArgument('zone');

        if ($zone === null) {
            return $this->failValidation('zone', 'A Cloudflare zone is required.');
        }

        $result = $this->resolveCloudflareResult(
            callerRoleResolver: $callerRoleResolver,
            gatewayRequest: new CloudflareRequest(Method::GET, "/api/cloudflare/zones/{$zone}/dns"),
            local: fn (): array => $cloudflare->listDnsRecords($zone),
            gatewayFailureMessage: 'Gateway connection is required to list Cloudflare DNS records.',
        );

        if (is_int($result)) {
            return $result;
        }

        return $this->successPayload($result['data'], $result['meta'], function (array $data): void {
            foreach ($data['records'] ?? [] as $record) {
                if (is_array($record)) {
                    $this->line(sprintf('%s  %s  %s  %s', $record['id'] ?? '', $record['type'] ?? '', $record['name'] ?? '', $record['content'] ?? ''));
                }
            }
        });
    }
}
