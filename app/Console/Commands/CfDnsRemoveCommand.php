<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Http\Gateway\Requests\Cloudflare\CloudflareRequest;
use App\Services\Cloudflare\CloudflareManager;
use App\Services\Nodes\CallerRoleResolver;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Saloon\Enums\Method;

#[Signature('cf-dns:remove
    {record-id? : Cloudflare DNS record ID}
    {--zone= : Cloudflare zone ID or domain}
    {--force : Confirm destructive operation without prompting}
    {--json : Output JSON}')]
#[Description('Remove a Cloudflare address DNS record')]
final class CfDnsRemoveCommand extends CloudflareCommand
{
    public function handle(CloudflareManager $cloudflare, CallerRoleResolver $callerRoleResolver): int
    {
        $recordId = $this->stringArgument('record-id');
        $zone = $this->stringOption('zone');

        if ($recordId === null || $zone === null) {
            return $this->failValidation($recordId === null ? 'record_id' : 'zone', 'A Cloudflare DNS record ID and zone are required.');
        }

        $consent = $this->confirmDestructive('Removing a Cloudflare DNS record requires --force in non-interactive mode.');

        if (is_int($consent)) {
            return $consent;
        }

        $result = $this->resolveCloudflareResult(
            callerRoleResolver: $callerRoleResolver,
            gatewayRequest: new CloudflareRequest(
                method: Method::DELETE,
                endpoint: "/api/cloudflare/zones/{$zone}/dns/{$recordId}",
                queryParameters: ['destructive_consent' => true],
            ),
            local: fn (): array => $cloudflare->removeDnsRecord($recordId, $zone),
            gatewayFailureMessage: 'Gateway connection is required to remove Cloudflare DNS records.',
        );

        if (is_int($result)) {
            return $result;
        }

        return $this->successPayload($result['data'], $result['meta'], function (array $data): void {
            $record = is_array($data['record'] ?? null) ? $data['record'] : [];
            $this->line("Cloudflare DNS record '".(string) ($record['id'] ?? '')."' removed.");
        });
    }
}
