<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Http\Gateway\Requests\Cloudflare\CloudflareRequest;
use App\Services\Cloudflare\CloudflareManager;
use App\Services\Nodes\CallerRoleResolver;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Saloon\Enums\Method;

#[Signature('cf-cache-rule:add
    {app? : Orbit app name}
    {--json : Output JSON}')]
#[Description('Add or converge a Cloudflare cache rule for an app')]
final class CfCacheRuleAddCommand extends CloudflareCommand
{
    public function handle(CloudflareManager $cloudflare, CallerRoleResolver $callerRoleResolver): int
    {
        $app = $this->stringArgument('app');

        if ($app === null) {
            return $this->failValidation('app', 'An app name is required.');
        }

        $result = $this->resolveCloudflareResult(
            callerRoleResolver: $callerRoleResolver,
            gatewayRequest: new CloudflareRequest(Method::POST, "/api/cloudflare/cache-rules/{$app}"),
            local: fn (): array => $cloudflare->addCacheRule($app),
            gatewayFailureMessage: 'Gateway connection is required to manage Cloudflare cache rules.',
        );

        if (is_int($result)) {
            return $result;
        }

        return $this->successPayload($result['data'], $result['meta'], function (array $data): void {
            $rule = is_array($data['rule'] ?? null) ? $data['rule'] : [];
            $this->line("Cloudflare cache rule saved for '".(string) ($rule['app'] ?? '')."'.");
        });
    }
}
