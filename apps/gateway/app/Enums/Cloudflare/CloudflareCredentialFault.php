<?php

declare(strict_types=1);

namespace App\Enums\Cloudflare;

/**
 * Credential-level Cloudflare failures, as distinct from a provider outage.
 *
 * Only these two states are the operator's to fix; every other Cloudflare
 * failure (5xx, malformed body, network) is provider-side and must not be
 * reported as a credential problem.
 */
enum CloudflareCredentialFault: string
{
    case TokenMissing = 'token_missing';

    case TokenRejected = 'token_rejected';
}
