<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Contracts\Loggable;
use App\Enums\ActivityLogType;
use App\Http\Gateway\GatewayApiException;
use App\Models\Node;
use App\Services\Cloudflare\CloudflareManager;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class CloudflareController implements Loggable
{
    private ?Node $activitySubject = null;

    public function zones(Request $request, CloudflareManager $cloudflare): JsonResponse
    {
        if ($response = $this->authorizeProviderAdministration($request)) {
            return $response;
        }

        return $this->run(fn (): array => $cloudflare->listZones());
    }

    public function dnsRecords(string $zone, Request $request, CloudflareManager $cloudflare): JsonResponse
    {
        if ($response = $this->authorizeProviderAdministration($request)) {
            return $response;
        }

        return $this->run(fn (): array => $cloudflare->listDnsRecords($zone));
    }

    public function storeDnsRecord(string $zone, Request $request, CloudflareManager $cloudflare): JsonResponse
    {
        if ($response = $this->authorizeProviderAdministration($request)) {
            return $response;
        }

        $name = $this->stringInput($request, 'name');
        $content = $this->stringInput($request, 'content');

        if ($name === null || $content === null) {
            return $this->error('validation_failed', 'DNS record name and content are required.', ['field' => $name === null ? 'name' : 'content'], 422);
        }

        return $this->run(fn (): array => $cloudflare->addDnsRecord(
            name: $name,
            content: $content,
            type: $this->stringInput($request, 'type') ?? 'A',
            zoneIdentifier: $zone,
            proxied: $request->boolean('proxied'),
        ));
    }

    public function removeDnsRecord(string $zone, string $record, Request $request, CloudflareManager $cloudflare): JsonResponse
    {
        if ($response = $this->authorizeProviderAdministration($request)) {
            return $response;
        }

        if (! $request->boolean('destructive_consent')) {
            return $this->error('validation_failed', 'Removing a Cloudflare DNS record requires --force in non-interactive mode.', [
                'field' => 'force',
                'reason' => 'destructive_consent_required',
            ], 422);
        }

        return $this->run(fn (): array => $cloudflare->removeDnsRecord($record, $zone));
    }

    public function flushCache(Request $request, CloudflareManager $cloudflare): JsonResponse
    {
        if ($response = $this->authorizeProviderAdministration($request)) {
            return $response;
        }

        $zone = $this->stringInput($request, 'zone');

        if ($zone === null) {
            return $this->error('validation_failed', 'A Cloudflare zone is required.', ['field' => 'zone'], 422);
        }

        return $this->run(fn (): array => $cloudflare->flushCache($zone));
    }

    public function addCacheRule(string $app, Request $request, CloudflareManager $cloudflare): JsonResponse
    {
        if ($response = $this->authorizeProviderAdministration($request)) {
            return $response;
        }

        return $this->run(fn (): array => $cloudflare->addCacheRule($app));
    }

    public function removeCacheRule(string $app, Request $request, CloudflareManager $cloudflare): JsonResponse
    {
        if ($response = $this->authorizeProviderAdministration($request)) {
            return $response;
        }

        if (! $request->boolean('destructive_consent')) {
            return $this->error('validation_failed', 'Removing a Cloudflare cache rule requires --force in non-interactive mode.', [
                'field' => 'force',
                'reason' => 'destructive_consent_required',
            ], 422);
        }

        return $this->run(fn (): array => $cloudflare->removeCacheRule($app));
    }

    public function updateSsl(string $zone, Request $request, CloudflareManager $cloudflare): JsonResponse
    {
        if ($response = $this->authorizeProviderAdministration($request)) {
            return $response;
        }

        $mode = $this->stringInput($request, 'mode') ?? 'strict';

        if ($mode === 'off' && ! $request->boolean('destructive_consent')) {
            return $this->error('validation_failed', 'Disabling Cloudflare SSL requires --force in non-interactive mode.', [
                'field' => 'force',
                'reason' => 'destructive_consent_required',
            ], 422);
        }

        return $this->run(fn (): array => $mode === 'off'
            ? $cloudflare->disableSsl($zone)
            : $cloudflare->enableSsl($zone, $mode));
    }

    /**
     * @param  callable(): array{data: array<string, mixed>, meta: array<string, mixed>}  $callback
     */
    private function run(callable $callback): JsonResponse
    {
        try {
            $result = $callback();
        } catch (GatewayApiException $exception) {
            return $this->error(
                code: $exception->errorCode() ?? 'cloudflare_unavailable',
                message: $exception->getMessage(),
                meta: $exception->errorMeta(),
                status: $this->statusFor($exception),
            );
        }

        return response()->json([
            'success' => [
                'data' => $result['data'],
                'meta' => $result['meta'],
            ],
        ]);
    }

    private function authorizeProviderAdministration(Request $request): ?JsonResponse
    {
        /** @var mixed $caller */
        $caller = $request->user();

        if (! $caller instanceof Node) {
            return $this->error('authorization_failed', 'Peer identity unknown.', [], 403);
        }

        if (! in_array($caller->role, ['gateway', 'control'], true)) {
            return $this->error('caller_role_not_allowed', 'This command may only be run from a control or gateway node.', [
                'caller_role' => $caller->role,
            ], 403);
        }

        $this->activitySubject = $caller;

        return null;
    }

    private function stringInput(Request $request, string $key): ?string
    {
        $value = $request->input($key);

        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }

    private function statusFor(GatewayApiException $exception): int
    {
        return match ($exception->errorCode()) {
            'authorization_failed', 'caller_role_not_allowed' => 403,
            'cloudflare_unavailable' => 503,
            default => 422,
        };
    }

    /**
     * @param  array<string, mixed>  $meta
     */
    private function error(string $code, string $message, array $meta, int $status): JsonResponse
    {
        return response()->json([
            'error' => [
                'code' => $code,
                'message' => $message,
                'meta' => empty($meta) ? (object) [] : $meta,
            ],
        ], $status);
    }

    public function effect(): ActivityLogType
    {
        return request()->isMethod('GET') ? ActivityLogType::Read : ActivityLogType::Write;
    }

    public function type(): string
    {
        return 'api:'.request()->method().' /'.request()->path();
    }

    public function subject(): ?Model
    {
        return $this->activitySubject;
    }

    /**
     * @return array<string, mixed>
     */
    public function properties(): array
    {
        return [
            'zone' => $this->stringInput(request(), 'zone'),
            'app' => request()->route('app'),
        ];
    }

    public function description(): ?string
    {
        return null;
    }
}
