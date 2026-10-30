<?php

declare(strict_types=1);

namespace App\Domain\Certificates;

use App\Domain\Operations\DirectOperationPrimitive;
use App\Domain\Shared\ResourceOperationException;
use App\Models\Operation;

/** A CA signing primitive. Node keys never leave their Node. */
final readonly class SignLeafPrimitive implements DirectOperationPrimitive
{
    public function __construct(private LeafCertificateSigner $signer) {}

    /** @return array<string, mixed> */
    public function execute(Operation $operation): array
    {
        $encoded = $operation->state['certificate_request'] ?? null;
        $request = is_string($encoded) ? base64_decode($encoded, true) : false;
        $domain = $operation->input['domain'] ?? null;
        if (! is_string($request) || ! is_string($domain)
            || ! str_contains($request, '-----BEGIN CERTIFICATE REQUEST-----')) {
            throw new ResourceOperationException('certificate.request_invalid', 'The admitted certificate request is invalid.', 422);
        }

        try {
            return ['signed' => true,
                'certificate_base64' => base64_encode($this->signer->sign($domain, $request)),
                'root_certificate_base64' => base64_encode($this->signer->rootCertificate())];
        } catch (\Throwable) {
            return ['error_code' => 'app-dev.certificate_sign_failed'];
        }
    }
}
