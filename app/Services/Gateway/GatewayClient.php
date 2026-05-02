<?php

declare(strict_types=1);

namespace App\Services\Gateway;

use App\Models\LocalGatewaySettings;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

final class GatewayClient
{
    public static function make(): PendingRequest
    {
        $settings = LocalGatewaySettings::current();

        // Note: X-Orbit-Request-Id is currently a random UUID per-request.
        // Future slices will add real correlation header support that preserves
        // the incoming ID from a parent caller when available.
        return Http::baseUrl($settings->gateway_url ?? '')
            ->withOptions([
                'verify' => $settings->ca_pem_path,
                'allow_redirects' => false,
            ])
            ->acceptJson()
            ->withHeaders([
                'X-Orbit-Request-Id' => (string) Str::uuid(),
            ]);
    }
}
