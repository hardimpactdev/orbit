<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Http\Gateway\Requests\Cloudflare\CloudflareRequest;
use App\Services\Cloudflare\CloudflareManager;
use App\Services\Nodes\CallerRoleResolver;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Saloon\Enums\Method;

use function Laravel\Prompts\text;

#[Signature('cf-cache:flush
    {--zone= : Cloudflare zone ID, domain, or app name}
    {--json : Output JSON}')]
#[Description('Flush Cloudflare cache for a zone')]
final class CfCacheFlushCommand extends CloudflareCommand
{
    public function handle(CloudflareManager $cloudflare, CallerRoleResolver $callerRoleResolver): int
    {
        $zone = $this->stringOption('zone');

        if ($zone === null && $this->isInteractiveInput()) {
            $zone = text(label: 'Cloudflare zone', required: true);
        }

        if ($zone === null) {
            return $this->failValidation('zone', 'A Cloudflare zone is required.');
        }

        $result = $this->resolveCloudflareResult(
            callerRoleResolver: $callerRoleResolver,
            gatewayRequest: new CloudflareRequest(Method::POST, '/api/cloudflare/cache/flush', ['zone' => $zone]),
            local: fn (): array => $cloudflare->flushCache($zone),
            gatewayFailureMessage: 'Gateway connection is required to flush Cloudflare cache.',
        );

        if (is_int($result)) {
            return $result;
        }

        return $this->successPayload($result['data'], $result['meta'], function (array $data): void {
            $cache = is_array($data['cache'] ?? null) ? $data['cache'] : [];
            $this->line("Cloudflare cache flushed for '".(string) ($cache['zone'] ?? '')."'.");
        });
    }
}
