<?php

declare(strict_types=1);

namespace App\Infrastructure\Releases;

use DateTimeImmutable;
use Exception;
use OpenSSLAsymmetricKey;

/**
 * Verifies a Sigstore bundle v0.3 offline against a Sigstore trust root, with PHP's OpenSSL extension alone
 * ([Release provenance](/reference/gateway-recovery#release-provenance)). It accepts exactly one Rekor v1 `dsse`
 * 0.0.1 entry with a signed entry timestamp and an inclusion proof, a Fulcio certificate for one exact identity, and
 * an in-toto statement with SLSA provenance v1 that names each expected subject with its SHA-256. Anything else
 * refuses, including Rekor v2 entries and bundles that carry only RFC 3161 timestamps.
 */
final readonly class SigstoreBundleVerifier
{
    public const string BundleType = 'application/vnd.dev.sigstore.bundle.v0.3+json';

    public const string PayloadType = 'application/vnd.in-toto+json';

    public const string StatementType = 'https://in-toto.io/Statement/v1';

    public const string PredicateType = 'https://slsa.dev/provenance/v1';

    /** The Fulcio extension that holds the OIDC issuer as a DER UTF8String. */
    public const string IssuerExtension = '1.3.6.1.4.1.57264.1.8';

    private const string RekorKeyType = 'PKIX_ECDSA_P256_SHA_256';

    /** @param string $trustRoot the JSON of a Sigstore `trusted_root.json` */
    public function __construct(private string $trustRoot) {}

    /**
     * @param  string  $identity  The exact certificate SAN URI the signer must have.
     * @param  string  $issuer  The exact OIDC issuer the certificate must name.
     * @param  array<string, string>  $subjects  The lowercase hex SHA-256 each named subject must have.
     *
     * @throws ReleaseProvenanceRefused
     */
    public function verify(string $bundle, string $identity, string $issuer, array $subjects): void
    {
        $root = $this->object(json_decode($this->trustRoot, true), 'The Sigstore trust root is not JSON.');
        $bundle = $this->object(json_decode($bundle, true), 'The provenance bundle is not JSON.');
        if (($bundle['mediaType'] ?? null) !== self::BundleType) {
            throw $this->refuse('The provenance bundle is not a Sigstore bundle v0.3.');
        }
        $material = $this->object($bundle['verificationMaterial'] ?? null, 'The provenance bundle has no verification material.');
        $certificate = $this->bytes($this->object($material['certificate'] ?? null, 'The provenance bundle has no certificate.')['rawBytes'] ?? null, 'certificate');
        $entries = $material['tlogEntries'] ?? null;
        if (! is_array($entries) || ! array_is_list($entries) || count($entries) !== 1) {
            throw $this->refuse('The provenance bundle must have exactly one transparency log entry.');
        }
        $envelope = $this->object($bundle['dsseEnvelope'] ?? null, 'The provenance bundle has no DSSE envelope.');
        $signatures = $envelope['signatures'] ?? null;
        if (! is_array($signatures) || ! array_is_list($signatures) || count($signatures) !== 1) {
            throw $this->refuse('The DSSE envelope must have exactly one signature.');
        }
        $payload = $this->bytes($envelope['payload'] ?? null, 'DSSE payload');
        $payloadType = $envelope['payloadType'] ?? null;
        $signature = $this->bytes($this->object($signatures[0], 'The DSSE signature is malformed.')['sig'] ?? null, 'DSSE signature');

        $time = $this->entry($this->object($entries[0], 'The transparency log entry is malformed.'), $root, $payload, $signature, $certificate);
        $leaf = $this->certificate($certificate, $root, $time, $identity, $issuer);

        if ($payloadType !== self::PayloadType) {
            throw $this->refuse('The DSSE payload is not an in-toto statement.');
        }
        if (openssl_verify('DSSEv1 '.strlen(self::PayloadType).' '.self::PayloadType.' '.strlen($payload).' '.$payload, $signature, $leaf, OPENSSL_ALGO_SHA256) !== 1) {
            throw $this->refuse('The DSSE signature does not verify with the certificate.');
        }
        $this->statement($payload, $subjects);
    }

    /**
     * Checks the Rekor v1 entry and returns its integrated time: the log key, the signed entry timestamp, the
     * inclusion proof and its checkpoint, and that the logged body records this envelope and certificate.
     *
     * @param  array<array-key, mixed>  $entry
     * @param  array<array-key, mixed>  $root
     */
    private function entry(array $entry, array $root, string $payload, string $signature, string $certificate): int
    {
        if (($entry['kindVersion'] ?? null) !== ['kind' => 'dsse', 'version' => '0.0.1']) {
            throw $this->refuse('The transparency log entry is not a Rekor v1 dsse 0.0.1 entry.');
        }
        $logId = $this->bytes($this->object($entry['logId'] ?? null, 'The transparency log entry has no log ID.')['keyId'] ?? null, 'log ID');
        $time = $this->integer($entry['integratedTime'] ?? null, 'integrated time');
        $index = $this->integer($entry['logIndex'] ?? null, 'log index');
        $encodedBody = $entry['canonicalizedBody'] ?? null;
        $body = $this->bytes($encodedBody, 'log entry body');
        $key = $this->rekorKey($root, $logId, $time);

        $promise = $this->object($entry['inclusionPromise'] ?? null, 'The transparency log entry has no signed entry timestamp.');
        $timestamp = $this->bytes($promise['signedEntryTimestamp'] ?? null, 'signed entry timestamp');
        $signed = json_encode(['body' => $encodedBody, 'integratedTime' => $time, 'logID' => bin2hex($logId), 'logIndex' => $index], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        if (openssl_verify($signed, $timestamp, $key, OPENSSL_ALGO_SHA256) !== 1) {
            throw $this->refuse('The signed entry timestamp does not verify with the Rekor key.');
        }

        $proof = $this->object($entry['inclusionProof'] ?? null, 'The transparency log entry has no inclusion proof.');
        $rootHash = $this->bytes($proof['rootHash'] ?? null, 'inclusion proof root hash');
        $treeSize = $this->integer($proof['treeSize'] ?? null, 'inclusion proof tree size');
        $hashes = $proof['hashes'] ?? null;
        if (! is_array($hashes) || ! array_is_list($hashes)) {
            throw $this->refuse('The inclusion proof has no hashes.');
        }
        $path = array_map(fn (mixed $hash): string => $this->bytes($hash, 'inclusion proof hash'), $hashes);
        if (! hash_equals($rootHash, $this->inclusionRoot(hash('sha256', "\x00".$body, true), $this->integer($proof['logIndex'] ?? null, 'inclusion proof log index'), $treeSize, $path))) {
            throw $this->refuse('The inclusion proof does not lead to its root hash.');
        }
        $checkpoint = $this->object($proof['checkpoint'] ?? null, 'The inclusion proof has no checkpoint.')['envelope'] ?? null;
        $this->checkpoint(is_string($checkpoint) ? $checkpoint : '', $key, $logId, $treeSize, $rootHash);

        $this->body($body, $payload, $signature, $certificate);

        return $time;
    }

    /** @param array<array-key, mixed> $root */
    private function rekorKey(array $root, string $logId, int $time): OpenSSLAsymmetricKey
    {
        foreach (is_array($root['tlogs'] ?? null) ? $root['tlogs'] : [] as $log) {
            $id = is_array($log) && is_array($log['logId'] ?? null) ? ($log['logId']['keyId'] ?? null) : null;
            $publicKey = is_array($log) && is_array($log['publicKey'] ?? null) ? $log['publicKey'] : [];
            if (! is_string($id) || base64_decode($id, true) !== $logId) {
                continue;
            }
            $der = is_string($publicKey['rawBytes'] ?? null) ? base64_decode($publicKey['rawBytes'], true) : false;
            $key = is_string($der) ? openssl_pkey_get_public($this->pem($der, 'PUBLIC KEY')) : false;
            if (($publicKey['keyDetails'] ?? null) !== self::RekorKeyType || $key === false || ! $this->within($publicKey['validFor'] ?? null, $time)) {
                throw $this->refuse('The Rekor key of the entry is not an ECDSA P-256 key valid at its integrated time.');
            }

            return $key;
        }

        throw $this->refuse('The transparency log entry names a log that the trust root does not hold.');
    }

    /**
     * The root hash an RFC 6962 inclusion proof leads to (RFC 9162, section 2.1.3.2), or an empty string when the
     * proof does not fit the tree.
     *
     * @param  list<string>  $path
     */
    private function inclusionRoot(string $leaf, int $index, int $size, array $path): string
    {
        if ($index >= $size) {
            return '';
        }
        $fn = $index;
        $sn = $size - 1;
        $hash = $leaf;
        foreach ($path as $sibling) {
            if ($sn === 0) {
                return '';
            }
            if (($fn & 1) === 1 || $fn === $sn) {
                $hash = hash('sha256', "\x01".$sibling.$hash, true);
                while (($fn & 1) === 0 && $fn !== 0) {
                    $fn >>= 1;
                    $sn >>= 1;
                }
            } else {
                $hash = hash('sha256', "\x01".$hash.$sibling, true);
            }
            $fn >>= 1;
            $sn >>= 1;
        }

        return $sn === 0 ? $hash : '';
    }

    /** A signed note whose tree size and root hash equal the proof's, signed by the entry's Rekor key. */
    private function checkpoint(string $note, OpenSSLAsymmetricKey $key, string $logId, int $treeSize, string $rootHash): void
    {
        $parts = explode("\n\n", $note, 2);
        $lines = explode("\n", $parts[0]);
        if (count($parts) !== 2 || count($lines) < 3 || $lines[1] !== (string) $treeSize || base64_decode($lines[2], true) !== $rootHash) {
            throw $this->refuse('The checkpoint does not hold the tree size and root hash of the inclusion proof.');
        }
        foreach (explode("\n", rtrim($parts[1], "\n")) as $line) {
            if (preg_match('/\A\x{2014} \S+ ([A-Za-z0-9+\/]+={0,2})\z/u', $line, $match) !== 1) {
                continue;
            }
            $signature = (string) base64_decode($match[1], true);
            if (strlen($signature) > 4 && substr($signature, 0, 4) === substr($logId, 0, 4)
                && openssl_verify($parts[0]."\n", substr($signature, 4), $key, OPENSSL_ALGO_SHA256) === 1) {
                return;
            }
        }

        throw $this->refuse('The checkpoint signature does not verify with the Rekor key.');
    }

    /** The logged dsse body must record this payload's hash, this signature, and this certificate. */
    private function body(string $body, string $payload, string $signature, string $certificate): void
    {
        $body = $this->object(json_decode($body, true), 'The log entry body is not JSON.');
        $spec = is_array($body['spec'] ?? null) ? $body['spec'] : [];
        $logged = is_array($spec['signatures'] ?? null) && array_is_list($spec['signatures']) && count($spec['signatures']) === 1 && is_array($spec['signatures'][0])
            ? $spec['signatures'][0] : [];
        $verifier = is_string($logged['verifier'] ?? null) ? base64_decode($logged['verifier'], true) : false;
        $verifierDer = is_string($verifier) && preg_match('/\A-----BEGIN CERTIFICATE-----\n([A-Za-z0-9+\/=\n]+)-----END CERTIFICATE-----\n?\z/', $verifier, $match) === 1
            ? base64_decode(str_replace("\n", '', $match[1]), true) : false;

        if (($body['apiVersion'] ?? null) !== '0.0.1' || ($body['kind'] ?? null) !== 'dsse'
            || ($spec['payloadHash'] ?? null) !== ['algorithm' => 'sha256', 'value' => hash('sha256', $payload)]
            || ! is_string($logged['signature'] ?? null) || base64_decode($logged['signature'], true) !== $signature
            || $verifierDer !== $certificate) {
            throw $this->refuse('The log entry body does not record this envelope and certificate.');
        }
    }

    /**
     * Checks the Fulcio certificate at the integrated time and returns its public key: a CA of the trust root signed
     * it, and the time lies inside the CA's validity, its chain, and the certificate. The SAN and the OIDC issuer
     * must equal the expected identity exactly.
     *
     * @param  array<array-key, mixed>  $root
     */
    private function certificate(string $der, array $root, int $time, string $identity, string $issuer): OpenSSLAsymmetricKey
    {
        $pem = $this->pem($der, 'CERTIFICATE');
        $parsed = openssl_x509_parse($pem, false);
        $key = openssl_pkey_get_public($pem);
        if (! is_array($parsed) || $key === false) {
            throw $this->refuse('The provenance certificate cannot be read.');
        }
        if (! $this->signedByAuthority($pem, $root, $time)) {
            throw $this->refuse('No Fulcio CA of the trust root signed the certificate at the integrated time.');
        }
        if (! $this->validAt($parsed, $time)) {
            throw $this->refuse('The integrated time lies outside the certificate validity.');
        }
        $extensions = is_array($parsed['extensions'] ?? null) ? $parsed['extensions'] : [];
        if (($extensions['subjectAltName'] ?? null) !== 'URI:'.$identity) {
            throw $this->refuse("The certificate does not name the signer [{$identity}].");
        }
        if ($this->utf8String($extensions[self::IssuerExtension] ?? null) !== $issuer) {
            throw $this->refuse("The certificate does not name the OIDC issuer [{$issuer}].");
        }

        return $key;
    }

    /** @param array<array-key, mixed> $root */
    private function signedByAuthority(string $pem, array $root, int $time): bool
    {
        foreach (is_array($root['certificateAuthorities'] ?? null) ? $root['certificateAuthorities'] : [] as $authority) {
            $chain = is_array($authority) && is_array($authority['certChain'] ?? null) ? ($authority['certChain']['certificates'] ?? null) : null;
            if (! is_array($chain) || $chain === [] || ! $this->within($authority['validFor'] ?? null, $time)) {
                continue;
            }
            $certificates = [];
            foreach ($chain as $certificate) {
                $raw = is_array($certificate) && is_string($certificate['rawBytes'] ?? null) ? base64_decode($certificate['rawBytes'], true) : false;
                $parsed = is_string($raw) ? openssl_x509_parse($this->pem($raw, 'CERTIFICATE'), false) : false;
                if (! is_string($raw) || ! is_array($parsed) || ! $this->validAt($parsed, $time)) {
                    continue 2;
                }
                $certificates[] = $this->pem($raw, 'CERTIFICATE');
            }
            $issuer = openssl_pkey_get_public($certificates[0]);
            if ($issuer !== false && openssl_x509_verify($pem, $issuer) === 1) {
                return true;
            }
        }

        return false;
    }

    /** @param array<string, string> $subjects */
    private function statement(string $payload, array $subjects): void
    {
        $statement = $this->object(json_decode($payload, true), 'The DSSE payload is not JSON.');
        if (($statement['_type'] ?? null) !== self::StatementType || ($statement['predicateType'] ?? null) !== self::PredicateType) {
            throw $this->refuse('The DSSE payload is not an in-toto statement v1 with SLSA provenance v1.');
        }
        $named = [];
        foreach (is_array($statement['subject'] ?? null) ? $statement['subject'] : [] as $subject) {
            $name = is_array($subject) ? ($subject['name'] ?? null) : null;
            $digest = is_array($subject) && is_array($subject['digest'] ?? null) ? ($subject['digest']['sha256'] ?? null) : null;
            if (! is_string($name) || isset($named[$name])) {
                throw $this->refuse('The statement names a subject without a name, or one name twice.');
            }
            $named[$name] = $digest;
        }
        foreach ($subjects as $name => $digest) {
            if (! isset($named[$name]) || $named[$name] !== $digest) {
                throw $this->refuse("The statement does not name [{$name}] with the SHA-256 of the downloaded file.");
            }
        }
    }

    /** @param array<array-key, mixed> $certificate */
    private function validAt(array $certificate, int $time): bool
    {
        $from = $certificate['validFrom_time_t'] ?? null;
        $to = $certificate['validTo_time_t'] ?? null;

        return is_int($from) && is_int($to) && $from <= $time && $time <= $to;
    }

    /** Whether a trust root `validFor` range holds the time. A range without an end is still open. */
    private function within(mixed $range, int $time): bool
    {
        $start = is_array($range) ? ($range['start'] ?? null) : null;
        $end = is_array($range) ? ($range['end'] ?? null) : null;

        return is_string($start) && $this->timestamp($start) <= $time && ($end === null || (is_string($end) && $time <= $this->timestamp($end)));
    }

    private function timestamp(string $time): int
    {
        try {
            return new DateTimeImmutable($time)->getTimestamp();
        } catch (Exception) {
            throw $this->refuse('The Sigstore trust root has a malformed time.');
        }
    }

    /** The value of a DER UTF8String, or null for anything else. */
    private function utf8String(mixed $der): ?string
    {
        if (! is_string($der) || strlen($der) < 2 || $der[0] !== "\x0c") {
            return null;
        }
        $length = ord($der[1]);
        $offset = 2;
        if ($length > 0x80 && $length <= 0x82) {
            $bytes = $length - 0x80;
            $length = (int) hexdec(bin2hex(substr($der, 2, $bytes)));
            $offset += $bytes;
        } elseif ($length >= 0x80) {
            return null;
        }

        return strlen($der) === $offset + $length ? substr($der, $offset) : null;
    }

    private function integer(mixed $value, string $name): int
    {
        if (is_string($value) && preg_match('/\A(?:0|[1-9][0-9]{0,17})\z/D', $value) === 1) {
            return (int) $value;
        }
        if (is_int($value) && $value >= 0) {
            return $value;
        }

        throw $this->refuse("The provenance bundle has no valid {$name}.");
    }

    private function bytes(mixed $value, string $name): string
    {
        $decoded = is_string($value) ? base64_decode($value, true) : false;
        if (! is_string($decoded) || $decoded === '') {
            throw $this->refuse("The provenance bundle has no valid {$name}.");
        }

        return $decoded;
    }

    /** @return array<array-key, mixed> */
    private function object(mixed $value, string $message): array
    {
        if (! is_array($value) || ($value !== [] && array_is_list($value))) {
            throw $this->refuse($message);
        }

        return $value;
    }

    private function pem(string $der, string $label): string
    {
        return "-----BEGIN {$label}-----\n".chunk_split(base64_encode($der), 64, "\n")."-----END {$label}-----\n";
    }

    private function refuse(string $message): ReleaseProvenanceRefused
    {
        return new ReleaseProvenanceRefused($message);
    }
}
