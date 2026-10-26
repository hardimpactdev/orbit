<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Infrastructure\Releases\ReleaseProvenance;
use App\Infrastructure\Releases\SigstoreBundleVerifier;
use Closure;
use LogicException;
use OpenSSLAsymmetricKey;
use OpenSSLCertificate;

/**
 * A Sigstore stand-in for tests: its own Fulcio CA and Rekor key in a trust root, and bundles shaped as
 * `actions/attest-build-provenance` writes them against Rekor v1. Each option changes one fact and keeps every other
 * part consistent, so a test that refuses it proves the one check that catches it.
 *
 * Options: `identity`, `issuer`, `subject_alt_name` and `issuer_extension` (raw OpenSSL configuration values, such as
 * `DER:<hex>`), `statement` (fields merged into the statement), `digests` (name => SHA-256 to state instead),
 * `integrated_time`, `kind_version`, `proof_index`, `proof_hashes` (a closure that changes the audit path),
 * `checkpoint_origin`, `single_leaf` (a log of only this entry), and `tamper`, one of: `checkpoint-hint`, `dsse-signature`, `body-payload-hash`,
 * `body-signature`, `body-verifier`, `set`, `inclusion-hash`, `checkpoint-signature`, `checkpoint-root`,
 * `no-promise`, `unknown-log`, `foreign-ca`.
 */
final class SigstoreTestSigner
{
    public const string Repository = 'https://github.com/hardimpactdev/orbit';

    private static ?self $instance = null;

    private string $configuration;

    private OpenSSLAsymmetricKey $caKey;

    private OpenSSLCertificate $ca;

    private OpenSSLAsymmetricKey $rekorKey;

    private function __construct()
    {
        $this->configuration = (string) tempnam(sys_get_temp_dir(), 'orbit-sigstore-cnf-');
        file_put_contents($this->configuration, "[ req ]\ndistinguished_name = dn\n[ dn ]\n[ ca ]\nbasicConstraints = critical,CA:TRUE\nkeyUsage = critical,keyCertSign\n");
        $this->caKey = $this->key();
        $this->ca = $this->sign(['commonName' => 'sigstore-test-intermediate'], $this->caKey, null, $this->caKey, 3650, 'ca');
        $this->rekorKey = $this->key();
    }

    public static function instance(): self
    {
        return self::$instance ??= new self;
    }

    public static function identity(): string
    {
        return self::Repository.'/'.ReleaseProvenance::Workflow.'@'.ReleaseProvenance::Ref;
    }

    /**
     * The trust root as JSON. `ca_valid_for` and `rekor_valid_for` replace their `validFor` ranges.
     *
     * @param  array<string, mixed>  $options
     */
    public function trustRoot(array $options = []): string
    {
        openssl_x509_export($this->ca, $pem);

        return json_encode([
            'mediaType' => 'application/vnd.dev.sigstore.trustedroot+json;version=0.1',
            'tlogs' => [[
                'baseUrl' => 'https://rekor.test',
                'hashAlgorithm' => 'SHA2_256',
                'publicKey' => ['rawBytes' => base64_encode($this->rekorDer()), 'keyDetails' => 'PKIX_ECDSA_P256_SHA_256', 'validFor' => $options['rekor_valid_for'] ?? ['start' => '2020-01-01T00:00:00Z']],
                'logId' => ['keyId' => base64_encode($this->logId())],
            ]],
            'certificateAuthorities' => [[
                'uri' => 'https://fulcio.test',
                'certChain' => ['certificates' => [['rawBytes' => base64_encode($this->der((string) $pem))]]],
                'validFor' => $options['ca_valid_for'] ?? ['start' => '2020-01-01T00:00:00Z'],
            ]],
            'ctlogs' => [],
            'timestampAuthorities' => [],
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
    }

    /** Writes the trust root to a file once per process and returns its path. */
    public function trustRootPath(): string
    {
        $path = sys_get_temp_dir().'/orbit-sigstore-trusted-root-'.getmypid().'.json';
        if (! is_file($path)) {
            file_put_contents($path, $this->trustRoot());
        }

        return $path;
    }

    /**
     * A bundle whose statement names each file with the SHA-256 of its bytes.
     *
     * @param  array<string, string>  $files
     * @param  array<string, mixed>  $options
     */
    public function bundle(array $files, array $options = []): string
    {
        $tamper = $options['tamper'] ?? null;
        $leafKey = $this->key();
        $signer = $tamper === 'foreign-ca' ? $this->key() : $this->caKey;
        $issuerCertificate = $tamper === 'foreign-ca' ? $this->sign(['commonName' => 'foreign-ca'], $signer, null, $signer, 3650, 'ca') : $this->ca;
        $leaf = $this->leaf(
            $leafKey,
            $signer,
            $issuerCertificate,
            (string) ($options['subject_alt_name'] ?? 'URI:'.($options['identity'] ?? self::identity())),
            (string) ($options['issuer_extension'] ?? 'ASN1:UTF8String:'.($options['issuer'] ?? ReleaseProvenance::Issuer)),
        );
        openssl_x509_export($leaf, $leafPem);
        $leafPem = (string) $leafPem;
        $time = is_int($options['integrated_time'] ?? null) ? $options['integrated_time'] : time();

        $subjects = [];
        foreach ($files as $name => $bytes) {
            $digests = is_array($options['digests'] ?? null) ? $options['digests'] : [];
            $subjects[] = ['name' => $name, 'digest' => ['sha256' => $digests[$name] ?? hash('sha256', $bytes)]];
        }
        $statement = [
            '_type' => SigstoreBundleVerifier::StatementType,
            'subject' => $subjects,
            'predicateType' => SigstoreBundleVerifier::PredicateType,
            'predicate' => ['buildDefinition' => ['buildType' => 'https://actions.github.io/buildtypes/workflow/v1'], 'runDetails' => []],
            ...(is_array($options['statement'] ?? null) ? $options['statement'] : []),
        ];
        $payload = json_encode($statement, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        $pae = 'DSSEv1 '.strlen(SigstoreBundleVerifier::PayloadType).' '.SigstoreBundleVerifier::PayloadType.' '.strlen($payload).' '.$payload;
        openssl_sign($tamper === 'dsse-signature' ? $pae.' ' : $pae, $signature, $leafKey, OPENSSL_ALGO_SHA256);
        $signature = (string) $signature;

        $loggedSignature = $tamper === 'body-signature' ? $this->signWith($this->key(), $pae) : $signature;
        $verifier = $leafPem;
        if ($tamper === 'body-verifier') {
            openssl_x509_export($this->leaf($this->key(), $this->caKey, $this->ca, 'URI:'.self::identity(), 'ASN1:UTF8String:'.ReleaseProvenance::Issuer), $other);
            $verifier = (string) $other;
        }
        $body = json_encode([
            'apiVersion' => '0.0.1',
            'kind' => 'dsse',
            'spec' => [
                'envelopeHash' => ['algorithm' => 'sha256', 'value' => hash('sha256', $payload)],
                'payloadHash' => ['algorithm' => 'sha256', 'value' => hash('sha256', $tamper === 'body-payload-hash' ? $payload.' ' : $payload)],
                'signatures' => [['signature' => base64_encode($loggedSignature), 'verifier' => base64_encode($verifier)]],
            ],
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        $encodedBody = base64_encode($body);

        $rekorKey = $tamper === 'unknown-log' ? $this->key() : $this->rekorKey;
        $logId = $tamper === 'unknown-log' ? hash('sha256', $this->der((string) openssl_pkey_get_details($rekorKey)['key']), true) : $this->logId();
        $leaf = hash('sha256', "\x00".$body, true);
        $leaves = ($options['single_leaf'] ?? false) === true ? [$leaf] : [hash('sha256', 'a', true), hash('sha256', 'b', true), hash('sha256', 'c', true), $leaf, hash('sha256', 'e', true)];
        $index = count($leaves) === 1 ? 0 : 3;
        $root = $this->treeHash($leaves);
        $path = $this->auditPath($index, $leaves);
        if ($tamper === 'inclusion-hash') {
            $path[0] = hash('sha256', 'other', true);
        }
        $logIndex = 1_000_000 + $index;
        $set = json_encode(['body' => $encodedBody, 'integratedTime' => $time, 'logID' => bin2hex($logId), 'logIndex' => $tamper === 'set' ? $logIndex + 1 : $logIndex], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        if (($options['proof_hashes'] ?? null) instanceof Closure) {
            $path = ($options['proof_hashes'])($path);
        }
        $note = ($options['checkpoint_origin'] ?? 'rekor.test - 1')."\n".count($leaves)."\n".base64_encode($tamper === 'checkpoint-root' ? hash('sha256', 'other root', true) : $root)."\n";
        $noteKey = $tamper === 'checkpoint-signature' ? $this->key() : $rekorKey;
        $hint = $tamper === 'checkpoint-hint' ? strrev(substr($logId, 0, 4)) : substr($logId, 0, 4);
        $checkpoint = $note."\n\u{2014} rekor.test ".base64_encode($hint.$this->signWith($noteKey, $note))."\n";

        $entry = [
            'logIndex' => (string) $logIndex,
            'logId' => ['keyId' => base64_encode($logId)],
            'kindVersion' => $options['kind_version'] ?? ['kind' => 'dsse', 'version' => '0.0.1'],
            'integratedTime' => (string) $time,
            'inclusionPromise' => ['signedEntryTimestamp' => base64_encode($this->signWith($rekorKey, $set))],
            'inclusionProof' => [
                'logIndex' => (string) ($options['proof_index'] ?? $index),
                'rootHash' => base64_encode($root),
                'treeSize' => (string) count($leaves),
                'hashes' => array_map(base64_encode(...), $path),
                'checkpoint' => ['envelope' => $checkpoint],
            ],
            'canonicalizedBody' => $encodedBody,
        ];
        if ($tamper === 'no-promise') {
            unset($entry['inclusionPromise']);
        }

        return json_encode([
            'mediaType' => SigstoreBundleVerifier::BundleType,
            'verificationMaterial' => [
                'certificate' => ['rawBytes' => base64_encode($this->der($leafPem))],
                'tlogEntries' => [$entry],
                'timestampVerificationData' => (object) [],
            ],
            'dsseEnvelope' => [
                'payload' => base64_encode($payload),
                'payloadType' => SigstoreBundleVerifier::PayloadType,
                'signatures' => [['sig' => base64_encode($signature)]],
            ],
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
    }

    private function leaf(OpenSSLAsymmetricKey $key, OpenSSLAsymmetricKey $caKey, OpenSSLCertificate $ca, string $subjectAltName, string $issuerExtension): OpenSSLCertificate
    {
        $configuration = (string) tempnam(sys_get_temp_dir(), 'orbit-sigstore-leaf-');
        try {
            file_put_contents($configuration, "[ req ]\ndistinguished_name = dn\n[ dn ]\n[ leaf ]\nbasicConstraints = critical,CA:FALSE\n"
                ."keyUsage = critical,digitalSignature\nextendedKeyUsage = codeSigning\nsubjectAltName = critical,{$subjectAltName}\n"
                .SigstoreBundleVerifier::IssuerExtension." = {$issuerExtension}\n");

            return $this->sign(['commonName' => 'sigstore-test-leaf'], $key, $ca, $caKey, 1, 'leaf', $configuration);
        } finally {
            @unlink($configuration);
        }
    }

    /** @param array<string, string> $subject */
    private function sign(array $subject, OpenSSLAsymmetricKey $key, ?OpenSSLCertificate $ca, OpenSSLAsymmetricKey $caKey, int $days, string $extensions, ?string $configuration = null): OpenSSLCertificate
    {
        $options = ['config' => $configuration ?? $this->configuration, 'digest_alg' => 'sha256', 'x509_extensions' => $extensions];
        $request = openssl_csr_new($subject, $key, $options);
        $certificate = $request === false || $request === true ? false : openssl_csr_sign($request, $ca, $caKey, $days, $options, random_int(1, PHP_INT_MAX));
        if ($certificate === false) {
            throw new LogicException('The test certificate cannot be signed: '.openssl_error_string());
        }

        return $certificate;
    }

    private function key(): OpenSSLAsymmetricKey
    {
        $key = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1', 'config' => $this->configuration]);
        if ($key === false) {
            throw new LogicException('The test key cannot be generated.');
        }

        return $key;
    }

    private function signWith(OpenSSLAsymmetricKey $key, string $data): string
    {
        openssl_sign($data, $signature, $key, OPENSSL_ALGO_SHA256);

        return (string) $signature;
    }

    private function rekorDer(): string
    {
        return $this->der((string) openssl_pkey_get_details($this->rekorKey)['key']);
    }

    private function logId(): string
    {
        return hash('sha256', $this->rekorDer(), true);
    }

    private function der(string $pem): string
    {
        return (string) base64_decode((string) preg_replace('/-----[A-Z ]+-----|\s+/', '', $pem), true);
    }

    /** @param list<string> $leaves RFC 6962 leaf hashes */
    private function treeHash(array $leaves): string
    {
        if (count($leaves) === 1) {
            return $leaves[0];
        }
        $split = $this->split(count($leaves));

        return hash('sha256', "\x01".$this->treeHash(array_slice($leaves, 0, $split)).$this->treeHash(array_slice($leaves, $split)), true);
    }

    /**
     * @param  list<string>  $leaves
     * @return list<string>
     */
    private function auditPath(int $index, array $leaves): array
    {
        if (count($leaves) === 1) {
            return [];
        }
        $split = $this->split(count($leaves));

        return $index < $split
            ? [...$this->auditPath($index, array_slice($leaves, 0, $split)), $this->treeHash(array_slice($leaves, $split))]
            : [...$this->auditPath($index - $split, array_slice($leaves, $split)), $this->treeHash(array_slice($leaves, 0, $split))];
    }

    /** The largest power of two below the size. */
    private function split(int $size): int
    {
        $split = 1;
        while ($split * 2 < $size) {
            $split *= 2;
        }

        return $split;
    }
}
