<?php

declare(strict_types=1);

namespace App\Domain\Certificates;

use App\Domain\Operations\OperationGatewayCommand;
use App\Domain\Shared\ResourceOperationException;
use App\Models\Operation;

/** A CA signing primitive. Node keys never leave their Node. */
final readonly class SignLeafCommand implements OperationGatewayCommand
{
    public function __construct(private LeafCertificateSigner $signer) {}

    /** @return array<string, mixed> */
    public function schema(): array
    {
        return ['type' => 'object', 'additionalProperties' => false,
            'required' => ['domain', 'request_base64'],
            'properties' => ['domain' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 253],
                'request_base64' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 32768]]];
    }

    /** @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public function run(Operation $operation, #[\SensitiveParameter] array $input): array
    {
        $encoded = $input['request_base64'] ?? null;
        $request = is_string($encoded) ? base64_decode($encoded, true) : false;
        $domain = $input['domain'] ?? null;
        if (! is_string($request) || ! is_string($domain)
            || ($operation->input['domain'] ?? null) !== $domain
            || ! str_contains($request, '-----BEGIN CERTIFICATE REQUEST-----')) {
            throw new ResourceOperationException('certificate.request_invalid', 'The admitted certificate request is invalid.', 422);
        }

        return ['signed' => true,
            'certificate_base64' => base64_encode($this->signer->sign($domain, $request)),
            'root_certificate_base64' => base64_encode($this->signer->rootCertificate())];
    }
}
