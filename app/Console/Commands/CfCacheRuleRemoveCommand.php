<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Http\Gateway\Requests\Cloudflare\CloudflareRequest;
use App\Services\Cloudflare\CloudflareManager;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Saloon\Enums\Method;

#[Signature('cf-cache-rule:remove
    {app? : Orbit app name}
    {--force : Confirm destructive operation without prompting}
    {--json : Output JSON}')]
#[Description('Remove a Cloudflare cache rule for an app')]
final class CfCacheRuleRemoveCommand extends CloudflareCommand
{
    public function handle(CloudflareManager $cloudflare): int
    {
        $app = $this->stringArgument('app');

        if ($app === null) {
            return $this->failValidation('app', 'An app name is required.');
        }

        $consent = $this->confirmDestructive('Removing a Cloudflare cache rule requires --force in non-interactive mode.');

        if (is_int($consent)) {
            return $consent;
        }

        $result = $this->resolveCloudflareResult(
            gatewayRequest: new CloudflareRequest(
                method: Method::DELETE,
                endpoint: "/api/cloudflare/cache-rules/{$app}",
                queryParameters: ['destructive_consent' => true],
            ),
            local: fn (): array => $cloudflare->removeCacheRule($app),
            gatewayFailureMessage: 'Gateway connection is required to manage Cloudflare cache rules.',
        );

        if (is_int($result)) {
            return $result;
        }

        return $this->successPayload($result['data'], $result['meta'], function (array $data): void {
            $rule = is_array($data['rule'] ?? null) ? $data['rule'] : [];
            $this->line("Cloudflare cache rule removed for '".(string) ($rule['app'] ?? '')."'.");
        });
    }
}
