<?php

declare(strict_types=1);

namespace App\Domain\Certificates;

use App\Domain\Shared\ResourceOperationException;
use App\Models\Operation;

/** Public CSR storage for the existing CA primitive; no execution evidence. */
final readonly class CertificateSigningRequests
{
    /** @return list<array<string,mixed>> */
    public static function leaves(Operation $operation): array
    {
        $leaves = $operation->kind === 'certificate.converge' ? [$operation->input] : ($operation->input['certificates'] ?? []);

        return is_array($leaves) && array_is_list($leaves) ? $leaves : [];
    }

    /** @param array<mixed> $requests */
    public function record(Operation $operation, array $requests): void
    {
        if (! array_is_list($requests) || count($requests) > 256) {
            $this->invalid();
        }
        $admitted = [];
        foreach (self::leaves($operation) as $leaf) {
            $admitted[($leaf['node_id'] ?? '').':'.($leaf['scope'] ?? '')] = true;
        }
        $seen = [];
        foreach ($requests as $request) {
            if (! is_array($request) || array_diff(array_keys($request), ['node_id', 'scope', 'request_base64']) !== []
                || ! is_int($request['node_id'] ?? null) || ! is_string($request['scope'] ?? null)
                || ! is_string($request['request_base64'] ?? null) || strlen($request['request_base64']) > 32768) {
                $this->invalid();
            }
            $selector = $request['node_id'].':'.$request['scope'];
            $pem = base64_decode($request['request_base64'], true);
            if (! isset($admitted[$selector]) || isset($seen[$selector]) || ! is_string($pem)
                || preg_match('/\A-----BEGIN CERTIFICATE REQUEST-----\r?\n[A-Za-z0-9+\/=\r\n]+-----END CERTIFICATE REQUEST-----\r?\n?\z/D', $pem) !== 1) {
                $this->invalid();
            }
            $seen[$selector] = true;
        }
        $operation->update(['state' => [...($operation->state ?? []), 'certificate_requests' => $requests]]);
    }

    private function invalid(): never
    {
        throw new ResourceOperationException('certificate.request_invalid', 'A public CSR for an admitted Node and scope is required.', 422);
    }
}
