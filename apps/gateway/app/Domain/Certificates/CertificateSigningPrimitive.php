<?php

declare(strict_types=1);

namespace App\Domain\Certificates;

use App\Domain\Operations\DirectOperationPrimitive;
use App\Domain\Shared\ResourceOperationException;
use App\Models\Operation;

/** Existing CA signing custody: only public requests and certificates cross the boundary. */
final readonly class CertificateSigningPrimitive implements DirectOperationPrimitive
{
    public function __construct(private LeafCertificateSigner $signer) {}

    public function execute(Operation $operation): array
    {
        $requests = $operation->state['certificate_requests'] ?? null;
        if (! is_array($requests) || ! array_is_list($requests)) {
            throw new ResourceOperationException('certificate.request_invalid', 'The public signing request checkpoint is missing.', 422);
        }
        $root = $this->signer->rootCertificate();
        $leaves = CertificateSigningRequests::leaves($operation);
        $certificates = [];
        foreach ($requests as $request) {
            $encoded = $request['request_base64'] ?? null;
            $pem = is_string($encoded) ? base64_decode($encoded, true) : false;
            $subject = is_string($pem) ? @openssl_csr_get_subject($pem) : false;
            $domain = is_array($subject) ? ($subject['CN'] ?? null) : null;
            $leaf = array_find($leaves, static fn (array $leaf): bool => ($leaf['node_id'] ?? null) === ($request['node_id'] ?? null)
                && ($leaf['scope'] ?? null) === ($request['scope'] ?? null) && ($leaf['domain'] ?? null) === $domain);
            if ($leaf === null || ! is_string($domain) || ! is_string($pem)
                || ($leaf['root_hash'] ?? null) !== hash('sha256', $root)) {
                throw new ResourceOperationException('certificate.request_invalid', 'The public signing request does not match an admitted leaf.', 422);
            }
            try {
                $certificates[] = ['node_id' => $leaf['node_id'], 'scope' => $leaf['scope'],
                    'certificate_base64' => base64_encode($this->signer->sign($leaf['domain'], $pem)),
                    'root_certificate_base64' => base64_encode($root)];
            } catch (\Throwable) {
                return ['error_code' => 'app-dev.certificate_sign_failed'];
            }
        }

        return ['certificates' => $certificates];
    }
}
