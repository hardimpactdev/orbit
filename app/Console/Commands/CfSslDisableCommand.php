<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Http\Gateway\Requests\Cloudflare\CloudflareRequest;
use App\Services\Cloudflare\CloudflareManager;
use App\Services\Nodes\CallerRoleResolver;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Saloon\Enums\Method;

#[Signature('cf-ssl:disable
    {zone? : Cloudflare zone ID or domain}
    {--force : Confirm destructive operation without prompting}
    {--json : Output JSON}')]
#[Description('Disable Cloudflare SSL for a zone')]
final class CfSslDisableCommand extends CloudflareCommand
{
    public function handle(CloudflareManager $cloudflare, CallerRoleResolver $callerRoleResolver): int
    {
        $zone = $this->stringArgument('zone');

        if ($zone === null) {
            return $this->failValidation('zone', 'A Cloudflare zone is required.');
        }

        $consent = $this->confirmDestructive('Disabling Cloudflare SSL requires --force in non-interactive mode.');

        if (is_int($consent)) {
            return $consent;
        }

        $result = $this->resolveCloudflareResult(
            callerRoleResolver: $callerRoleResolver,
            gatewayRequest: new CloudflareRequest(
                method: Method::PUT,
                endpoint: "/api/cloudflare/zones/{$zone}/ssl",
                payload: ['mode' => 'off', 'destructive_consent' => true],
            ),
            local: fn (): array => $cloudflare->disableSsl($zone),
            gatewayFailureMessage: 'Gateway connection is required to manage Cloudflare SSL.',
        );

        if (is_int($result)) {
            return $result;
        }

        return $this->successPayload($result['data'], $result['meta'], function (array $data): void {
            $ssl = is_array($data['ssl'] ?? null) ? $data['ssl'] : [];
            $this->line("Cloudflare SSL disabled for '".(string) ($ssl['zone'] ?? '')."'.");
        });
    }
}
