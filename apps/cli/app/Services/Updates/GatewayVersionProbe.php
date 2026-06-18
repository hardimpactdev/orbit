<?php

declare(strict_types=1);

namespace App\Services\Updates;

use App\Services\GatewayApiClient;
use Throwable;

/**
 * Reads the gateway's current Orbit version for the gateway-first version gate.
 *
 * This is the only gateway interaction `orbit update` performs and it is
 * strictly read-only (`GET /api/status`). Any failure — no gateway configured,
 * unreachable, malformed response, or an unparseable / `0.0.0` version — is
 * treated as an unknown version (`null`) so the gate imposes no ceiling. The
 * caller decides what "unknown" means; for `update` it means "proceed".
 */
final readonly class GatewayVersionProbe
{
    private const string ENDPOINT = '/api/status';

    public function __construct(
        private GatewayApiClient $gateway,
    ) {}

    /**
     * Resolve the gateway's current version, or `null` when it cannot be
     * determined or is the placeholder `0.0.0`.
     */
    public function currentVersion(): ?string
    {
        try {
            // GatewayApiException (a RuntimeException) and any transport error
            // are all treated as an unknown gateway version.
            $response = $this->gateway->get(self::ENDPOINT);
        } catch (Throwable) {
            return null;
        }

        $version = $this->extractVersion($response);

        if ($version === null || $version === '0.0.0') {
            return null;
        }

        return $version;
    }

    /**
     * @param  array<string, mixed>  $response
     */
    private function extractVersion(array $response): ?string
    {
        $success = $response['success'] ?? null;
        $data = is_array($success) ? ($success['data'] ?? null) : $response;

        if (! is_array($data)) {
            return null;
        }

        $version = $data['version'] ?? null;

        if (is_array($data['gateway'] ?? null) && isset($data['gateway']['version'])) {
            $version = $data['gateway']['version'];
        }

        if (! is_string($version)) {
            return null;
        }

        $version = ltrim(trim($version), 'v');

        return $version === '' ? null : $version;
    }
}
