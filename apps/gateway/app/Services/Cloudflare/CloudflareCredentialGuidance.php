<?php

declare(strict_types=1);

namespace App\Services\Cloudflare;

use App\Enums\Cloudflare\CloudflareCredentialFault;

/**
 * Single source of remediation for Cloudflare credential faults.
 *
 * The token is gateway environment state with no rotation command
 * (`cf-concepts.md`: "External provider secret stored on the gateway as
 * `CLOUDFLARE_API_TOKEN`"), so every surface that reports a credential fault
 * has to spell out where it lives and what it needs. Shared by the API client
 * (error payloads) and the doctor probe (drift detail) so the two can never
 * drift apart.
 */
final readonly class CloudflareCredentialGuidance
{
    public const string EnvVar = 'CLOUDFLARE_API_TOKEN';

    public const string ConfigKey = 'orbit.cloudflare.api_token';

    public const string TokenDashboard = 'https://dash.cloudflare.com/profile/api-tokens';

    /**
     * Minimum Cloudflare token permissions for the endpoints the cf-* commands
     * call: /zones, /zones/{id}/dns_records, /zones/{id}/purge_cache,
     * /zones/{id}/settings/ssl, and /zones/{id}/rulesets.
     *
     * @var list<string>
     */
    public const array RequiredScopes = [
        'Zone:Read',
        'DNS:Edit',
        'Cache Purge:Purge',
        'Zone Settings:Edit',
    ];

    public static function remediation(CloudflareCredentialFault $fault): string
    {
        $action = match ($fault) {
            CloudflareCredentialFault::TokenMissing => 'Set',
            CloudflareCredentialFault::TokenRejected => 'Rotate',
        };

        return sprintf(
            '%s %s in the gateway environment, then restart the gateway. Create the token at %s with at least: %s.',
            $action,
            self::EnvVar,
            self::TokenDashboard,
            implode(', ', self::RequiredScopes),
        );
    }

    /**
     * @return array<string, mixed>
     */
    public static function meta(CloudflareCredentialFault $fault): array
    {
        return [
            'reason' => $fault->value,
            'remediation' => self::remediation($fault),
            'env_var' => self::EnvVar,
            'config_key' => self::ConfigKey,
            'required_scopes' => self::RequiredScopes,
            'token_dashboard' => self::TokenDashboard,
        ];
    }
}
