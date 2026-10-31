<?php

declare(strict_types=1);

namespace App\Infrastructure\Certificates;

use App\Domain\Routes\RouteDomain;
use App\Domain\Shared\ResourceOperationException;
use App\Infrastructure\Processes\ProcessInvocation;
use App\Infrastructure\Processes\ProcessRunner;
use Illuminate\Support\Facades\Config;

/** The Orbit CA key is read only in this Gateway process and never reaches Swamp. */
final readonly class GatewayLeafCertificateSigner
{
    public function __construct(private ProcessRunner $processes) {}

    public function sign(string $domain, string $request, string $root): string
    {
        if (preg_match('/\A-----BEGIN (NEW CERTIFICATE REQUEST|CERTIFICATE REQUEST)-----\r?\n[A-Za-z0-9+\/=\r\n]+-----END \1-----\s*\z/D', $request) !== 1) {
            throw $this->invalidRequest();
        }
        $subject = @openssl_csr_get_subject($request, false);
        $public = @openssl_csr_get_public_key($request);
        $key = $public === false ? false : openssl_pkey_get_details($public);
        if (! RouteDomain::isValid($domain) || ! is_array($subject) || ($subject['commonName'] ?? null) !== $domain
            || ! is_array($key) || ($key['type'] ?? null) !== OPENSSL_KEYTYPE_RSA || ($key['bits'] ?? null) !== 2048) {
            throw $this->invalidRequest();
        }
        $inspection = $this->processes->run(new ProcessInvocation(['openssl', 'req', '-noout', '-text', '-verify'],
            timeout: 10, input: $request, maxOutputBytes: 32768));
        $sanCount = preg_match_all('/X509v3 Subject Alternative Name:\s*([^\r\n]+)/', $inspection->stdout, $matches);
        if (! $inspection->succeeded() || $inspection->truncated || $sanCount === false || $sanCount > 1
            || ($sanCount === 1 && trim($matches[1][0]) !== 'DNS:'.$domain)) {
            throw $this->invalidRequest();
        }
        $privatePath = rtrim(Config::string('orbit.home'), '/').'/ca/root.key';
        $privateKey = is_file($privatePath) ? file_get_contents($privatePath) : false;
        if (! is_string($privateKey) || ! OrbitRootCa::pairIsValid($root, $privateKey, time())) {
            throw new ResourceOperationException('certificate.signer_unavailable', 'The Orbit certificate authority is unavailable.', 503);
        }
        $configuration = tempnam(sys_get_temp_dir(), 'orbit-leaf-policy-');
        if ($configuration === false) {
            throw new ResourceOperationException('certificate.signer_unavailable', 'The Orbit certificate authority is unavailable.', 503);
        }
        try {
            file_put_contents($configuration, "[req]\ndistinguished_name=subject\n[subject]\n".OrbitLeafCertificate::extensions('leaf', 'DNS:'.$domain)."\n");
            $leaf = @openssl_csr_sign($request, $root, $privateKey, OrbitLeafCertificate::ValidityDays,
                ['config' => $configuration, 'x509_extensions' => 'leaf', 'digest_alg' => 'sha256'], random_int(1, PHP_INT_MAX));
            $certificate = '';
            if ($leaf === false || ! openssl_x509_export($leaf, $certificate)) {
                throw new ResourceOperationException('certificate.sign_failed', 'The admitted certificate could not be signed.', 422);
            }

            return $certificate;
        } finally {
            unlink($configuration);
        }
    }

    private function invalidRequest(): ResourceOperationException
    {
        return new ResourceOperationException('certificate.request_invalid', 'The CSR must be an RSA 2048 request for the admitted domain only.', 422);
    }
}
