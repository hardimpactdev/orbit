<?php

declare(strict_types=1);

namespace App\Services\Cloudflare;

use App\Data\Doctor\DriftEntry;
use App\Enums\Cloudflare\CloudflareCredentialFault;
use App\Enums\DriftKind;
use App\Models\Node;
use App\Services\Extensions\GatewayExtensionState;
use Orbit\Sdk\Laravel\GatewayApiException;
use Throwable;

/**
 * Gateway-scoped Cloudflare credential health, reported in the tool family.
 *
 * A fleet could previously lose every cf-* command to an expired token while
 * doctor still reported healthy, because nothing observed the provider
 * credential. Findings are report-only: rotating an external secret is operator
 * work, so there is no restore action (same shape as
 * `tool.seaweedfs.credentials_missing`). This deliberately reuses the existing
 * `tool` family rather than introducing the `cf` family that
 * `domains/12_cf/README.md` rules out.
 */
final readonly class CloudflareCredentialDoctorProbe
{
    public function __construct(
        private CloudflareClientFactory $clients,
        private GatewayExtensionState $extensions,
    ) {}

    public function family(): string
    {
        return 'tool';
    }

    /**
     * Cloudflare is optional infrastructure; only probe once an operator has
     * turned the gateway extension on.
     */
    public function shouldProbe(): bool
    {
        try {
            return $this->extensions->enabled('cloudflare');
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * @return list<DriftEntry>
     */
    public function drift(Node $node): array
    {
        if (! $this->shouldProbe()) {
            return [];
        }

        $token = config(CloudflareCredentialGuidance::ConfigKey);

        if (! is_string($token) || trim($token) === '') {
            return [$this->fault($node, CloudflareCredentialFault::TokenMissing)];
        }

        try {
            $this->clients->make()->verifyCredentials();

            return [];
        } catch (GatewayApiException $exception) {
            $reason = $exception->errorMeta()['reason'] ?? null;

            if ($reason === CloudflareCredentialFault::TokenRejected->value) {
                return [$this->fault($node, CloudflareCredentialFault::TokenRejected, $exception)];
            }

            return [$this->unverifiable($node, $exception)];
        } catch (Throwable $exception) {
            return [$this->unverifiable($node, $exception)];
        }
    }

    private function fault(Node $node, CloudflareCredentialFault $fault, ?Throwable $exception = null): DriftEntry
    {
        $missing = $fault === CloudflareCredentialFault::TokenMissing;

        return new DriftEntry(
            family: $this->family(),
            key: $missing ? 'tool.cloudflare.credentials_missing' : 'tool.cloudflare.token_rejected',
            kind: $missing ? DriftKind::Missing : DriftKind::Divergent,
            summary: $missing
                ? "Cloudflare is enabled on gateway {$node->name} but no API token is configured."
                : "Cloudflare rejected the API token configured on gateway {$node->name}.",
            detail: [
                'node' => $node->name,
                'tool' => 'cloudflare',
                ...CloudflareCredentialGuidance::meta($fault),
                ...($exception instanceof Throwable ? ['provider_message' => $exception->getMessage()] : []),
            ],
        );
    }

    private function unverifiable(Node $node, Throwable $exception): DriftEntry
    {
        return new DriftEntry(
            family: $this->family(),
            key: 'tool.cloudflare.credentials_probe_failed',
            kind: DriftKind::Unverifiable,
            summary: "Cloudflare API credentials on gateway {$node->name} could not be verified.",
            detail: [
                'node' => $node->name,
                'tool' => 'cloudflare',
                'error' => $exception->getMessage(),
            ],
        );
    }
}
