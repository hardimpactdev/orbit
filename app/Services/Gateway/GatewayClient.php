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
