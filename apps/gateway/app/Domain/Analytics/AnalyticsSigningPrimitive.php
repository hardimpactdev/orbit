<?php

declare(strict_types=1);

namespace App\Domain\Analytics;

use App\Domain\Certificates\LeafCertificateSigner;
use App\Domain\Operations\DirectOperationPrimitive;
use App\Domain\Shared\ResourceOperationException;
use App\Models\Operation;
use App\Support\ValidatedData;

/** Existing CA service: sign public requests only. Node private keys never leave their Nodes. */
final readonly class AnalyticsSigningPrimitive implements DirectOperationPrimitive
{
    public function __construct(private LeafCertificateSigner $signer, private AnalyticsTrackingIntent $tracking) {}

    public function execute(Operation $operation): array
    {
        $this->tracking->assertCurrent($operation);
        $signed = [];
        $requests = ValidatedData::object($operation->state['certificate_requests'] ?? []);
        foreach ($this->tracking->plan($operation)['certificates'] as $leaf) {
            $encoded = $requests[$leaf['node_id'].':'.$leaf['scope']] ?? null;
            if (! is_string($encoded)) {
                continue;
            }
            $csr = base64_decode($encoded, true);
            if (! is_string($csr)) {
                throw new ResourceOperationException('certificate.request_invalid', 'The public request is invalid.', 422);
            }
            $signed[] = ['node_id' => $leaf['node_id'], 'scope' => $leaf['scope'],
                'certificate_base64' => base64_encode($this->certificate($leaf['domain'], $csr)),
                'root_certificate_base64' => base64_encode($this->signer->rootCertificate())];
        }

        return ['certificate_bundle_base64' => base64_encode(json_encode($signed, JSON_THROW_ON_ERROR))];
    }

    private function certificate(string $domain, string $csr): string
    {
        try {
            return $this->signer->sign($domain, $csr);
        } catch (\Throwable) {
            throw new ResourceOperationException('certificate.signing_failed', 'The CA could not sign the admitted public request.', 409);
        }
    }
}
