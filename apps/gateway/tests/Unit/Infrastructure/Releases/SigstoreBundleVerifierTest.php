<?php

declare(strict_types=1);

use App\Infrastructure\Releases\ReleaseProvenance;
use App\Infrastructure\Releases\ReleaseProvenanceRefused;
use App\Infrastructure\Releases\ReleaseProvenanceUnavailable;
use App\Infrastructure\Releases\SigstoreBundleVerifier;
use Tests\Support\SigstoreTestSigner;

const CLI_CLI_SIGNER = 'https://github.com/cli/cli/.github/workflows/deployment.yml@refs/heads/trunk';

const CLI_CLI_SUBJECT = ['gh_2.103.2_linux_arm64.tar.gz' => '4c3e99a50bd8b181a3fe9d69b25dd978bac768148d88f01507c6b4d1ef61a443'];

function public_good_trust_root(): string
{
    return (string) file_get_contents(dirname(__DIR__, 4).'/resources/sigstore/trusted_root.json');
}

function cli_cli_bundle(): string
{
    return (string) file_get_contents(dirname(__DIR__, 4).'/tests/Fixtures/Sigstore/cli-cli-v2.103.2-linux-arm64.sigstore.json');
}

/**
 * The real bundle with one change made to its decoded JSON.
 *
 * @param  Closure(array<string, mixed>): array<string, mixed>  $change
 */
function changed_cli_cli_bundle(Closure $change): string
{
    return json_encode($change(json_decode(cli_cli_bundle(), true, flags: JSON_THROW_ON_ERROR)), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
}

/** Flips one bit of a base64 value at the given byte. */
function flip_base64_byte(string $value, int $byte): string
{
    $bytes = (string) base64_decode($value, true);
    $bytes[$byte] = chr(ord($bytes[$byte]) ^ 0x01);

    return base64_encode($bytes);
}

/** @return array<string, string> */
function orbit_release_files(): array
{
    return ['SHA256SUMS' => "aaaa  orbit-agent-linux-x86_64\n", 'manifest.json' => '{"commit":"b"}'];
}

describe(SigstoreBundleVerifier::class, function (): void {
    it('verifies a real public-good build provenance bundle against the committed trust root', function (): void {
        new SigstoreBundleVerifier(public_good_trust_root())->verify(cli_cli_bundle(), CLI_CLI_SIGNER, ReleaseProvenance::Issuer, CLI_CLI_SUBJECT);

        expect(true)->toBeTrue();
    });

    it('refuses the real bundle when one fact changes', function (string $bundle, string $identity, string $issuer, array $subjects, string $message): void {
        expect(fn () => new SigstoreBundleVerifier(public_good_trust_root())->verify($bundle, $identity, $issuer, $subjects))
            ->toThrow(ReleaseProvenanceRefused::class, $message);
    })->with([
        'a payload byte' => [fn (): string => changed_cli_cli_bundle(static function (array $bundle): array {
            $bundle['dsseEnvelope']['payload'] = flip_base64_byte($bundle['dsseEnvelope']['payload'], 40);

            return $bundle;
        }), CLI_CLI_SIGNER, ReleaseProvenance::Issuer, CLI_CLI_SUBJECT, 'The log entry body does not record this envelope and certificate.'],
        'a signature byte' => [fn (): string => changed_cli_cli_bundle(static function (array $bundle): array {
            $bundle['dsseEnvelope']['signatures'][0]['sig'] = flip_base64_byte($bundle['dsseEnvelope']['signatures'][0]['sig'], 20);

            return $bundle;
        }), CLI_CLI_SIGNER, ReleaseProvenance::Issuer, CLI_CLI_SUBJECT, 'The log entry body does not record this envelope and certificate.'],
        'the signed entry timestamp' => [fn (): string => changed_cli_cli_bundle(static function (array $bundle): array {
            $bundle['verificationMaterial']['tlogEntries'][0]['inclusionPromise']['signedEntryTimestamp'] = flip_base64_byte($bundle['verificationMaterial']['tlogEntries'][0]['inclusionPromise']['signedEntryTimestamp'], 20);

            return $bundle;
        }), CLI_CLI_SIGNER, ReleaseProvenance::Issuer, CLI_CLI_SUBJECT, 'The signed entry timestamp does not verify with the Rekor key.'],
        'an inclusion proof hash' => [fn (): string => changed_cli_cli_bundle(static function (array $bundle): array {
            $bundle['verificationMaterial']['tlogEntries'][0]['inclusionProof']['hashes'][3] = flip_base64_byte($bundle['verificationMaterial']['tlogEntries'][0]['inclusionProof']['hashes'][3], 0);

            return $bundle;
        }), CLI_CLI_SIGNER, ReleaseProvenance::Issuer, CLI_CLI_SUBJECT, 'The inclusion proof does not lead to its root hash.'],
        'the checkpoint signature' => [fn (): string => changed_cli_cli_bundle(static function (array $bundle): array {
            $proof = &$bundle['verificationMaterial']['tlogEntries'][0]['inclusionProof'];
            $proof['checkpoint']['envelope'] = preg_replace('/(\x{2014} \S+ wNI9a)j/u', '${1}k', $proof['checkpoint']['envelope']);

            return $bundle;
        }), CLI_CLI_SIGNER, ReleaseProvenance::Issuer, CLI_CLI_SUBJECT, 'The checkpoint signature does not verify with the Rekor key.'],
        'the signer' => [fn (): string => cli_cli_bundle(), 'https://github.com/cli/cli/.github/workflows/deployment.yml@refs/heads/main', ReleaseProvenance::Issuer, CLI_CLI_SUBJECT, 'The certificate does not name the signer'],
        'the issuer' => [fn (): string => cli_cli_bundle(), CLI_CLI_SIGNER, 'https://accounts.google.com', CLI_CLI_SUBJECT, 'The certificate does not name the OIDC issuer'],
        'the subject digest' => [fn (): string => cli_cli_bundle(), CLI_CLI_SIGNER, ReleaseProvenance::Issuer, ['gh_2.103.2_linux_arm64.tar.gz' => str_repeat('0', 64)], 'does not name [gh_2.103.2_linux_arm64.tar.gz] with the SHA-256'],
    ]);

    it('refuses the real bundle when the trust root does not hold its Rekor key', function (): void {
        expect(fn () => new SigstoreBundleVerifier(SigstoreTestSigner::instance()->trustRoot())->verify(cli_cli_bundle(), CLI_CLI_SIGNER, ReleaseProvenance::Issuer, CLI_CLI_SUBJECT))
            ->toThrow(ReleaseProvenanceRefused::class, 'names a log that the trust root does not hold');
    });

    it('verifies a bundle with the identity of the Orbit release workflow', function (): void {
        $signer = SigstoreTestSigner::instance();
        $digests = array_map(static fn (string $bytes): string => hash('sha256', $bytes), orbit_release_files());

        new SigstoreBundleVerifier($signer->trustRoot())->verify($signer->bundle(orbit_release_files()), SigstoreTestSigner::identity(), ReleaseProvenance::Issuer, $digests);

        expect(true)->toBeTrue();
    });

    it('refuses a bundle that differs from a valid one in one fact', function (array $options, string $message, array $trustRoot = []): void {
        $signer = SigstoreTestSigner::instance();
        $digests = array_map(static fn (string $bytes): string => hash('sha256', $bytes), orbit_release_files());

        expect(fn () => new SigstoreBundleVerifier($signer->trustRoot($trustRoot))->verify($signer->bundle(orbit_release_files(), $options), SigstoreTestSigner::identity(), ReleaseProvenance::Issuer, $digests))
            ->toThrow(ReleaseProvenanceRefused::class, $message);
    })->with([
        'a DSSE signature over other bytes' => [['tamper' => 'dsse-signature'], 'The DSSE signature does not verify with the certificate.'],
        'a logged payload hash of other bytes' => [['tamper' => 'body-payload-hash'], 'The log entry body does not record this envelope and certificate.'],
        'a logged signature that is not the envelope signature' => [['tamper' => 'body-signature'], 'The log entry body does not record this envelope and certificate.'],
        'a logged certificate that is not the bundle certificate' => [['tamper' => 'body-verifier'], 'The log entry body does not record this envelope and certificate.'],
        'a signed entry timestamp over another log index' => [['tamper' => 'set'], 'The signed entry timestamp does not verify with the Rekor key.'],
        'no signed entry timestamp' => [['tamper' => 'no-promise'], 'has no signed entry timestamp'],
        'an inclusion proof hash of another tree' => [['tamper' => 'inclusion-hash'], 'The inclusion proof does not lead to its root hash.'],
        'a checkpoint signed by another key' => [['tamper' => 'checkpoint-signature'], 'The checkpoint signature does not verify with the Rekor key.'],
        'a signed checkpoint of another root' => [['tamper' => 'checkpoint-root'], 'The checkpoint does not hold the tree size and root hash of the inclusion proof.'],
        'a log the trust root does not hold' => [['tamper' => 'unknown-log'], 'names a log that the trust root does not hold'],
        'a Rekor v2 entry' => [['kind_version' => ['kind' => 'dsse', 'version' => '0.0.2']], 'is not a Rekor v1 dsse 0.0.1 entry'],
        'a certificate from another CA' => [['tamper' => 'foreign-ca'], 'No Fulcio CA of the trust root signed the certificate'],
        'a time after the certificate validity' => [['integrated_time' => time() + 3 * 86400], 'The integrated time lies outside the certificate validity.'],
        'a time after the CA validity' => [[], 'No Fulcio CA of the trust root signed the certificate', ['ca_valid_for' => ['start' => '2020-01-01T00:00:00Z', 'end' => '2021-01-01T00:00:00Z']]],
        'a time before the Rekor key validity' => [[], 'not an ECDSA P-256 key valid at its integrated time', ['rekor_valid_for' => ['start' => '2999-01-01T00:00:00Z']]],
        'another signer workflow' => [['identity' => 'https://github.com/hardimpactdev/orbit/.github/workflows/ci.yml@refs/heads/main'], 'The certificate does not name the signer'],
        'another branch' => [['identity' => 'https://github.com/hardimpactdev/orbit/.github/workflows/orbit-cli-release.yml@refs/heads/feature'], 'The certificate does not name the signer'],
        'another issuer' => [['issuer' => 'https://token.actions.example.com'], 'The certificate does not name the OIDC issuer'],
        'another statement type' => [['statement' => ['_type' => 'https://in-toto.io/Statement/v0.1']], 'not an in-toto statement v1 with SLSA provenance v1'],
        'another predicate type' => [['statement' => ['predicateType' => 'https://in-toto.io/attestation/release/v0.2']], 'not an in-toto statement v1 with SLSA provenance v1'],
        'a subject digest of other bytes' => [['digests' => ['manifest.json' => str_repeat('0', 64)]], 'does not name [manifest.json] with the SHA-256'],
        'a second URI SAN' => [['subject_alt_name' => 'URI:'.SigstoreTestSigner::identity().',URI:https://example.com/other'], 'The certificate does not name the signer'],
        'an email SAN with the signer value' => [['subject_alt_name' => 'email:'.SigstoreTestSigner::identity()], 'The certificate does not name the signer'],
        'an issuer as another string type' => [['issuer_extension' => 'ASN1:IA5STRING:'.ReleaseProvenance::Issuer], 'The certificate does not name the OIDC issuer'],
        'an issuer with a long-form length' => [['issuer_extension' => 'DER:0c812b68747470733a2f2f746f6b656e2e616374696f6e732e67697468756275736572636f6e74656e742e636f6d'], 'The certificate does not name the OIDC issuer'],
        'an issuer with a trailing byte' => [['issuer_extension' => 'DER:0c2b68747470733a2f2f746f6b656e2e616374696f6e732e67697468756275736572636f6e74656e742e636f6d00'], 'The certificate does not name the OIDC issuer'],
        'a proof index outside the tree' => [['proof_index' => 5], 'The inclusion proof does not lead to its root hash.'],
        'a proof index past a one-entry log' => [['single_leaf' => true, 'proof_index' => 1], 'The inclusion proof does not lead to its root hash.'],
        'a proof with a surplus hash' => [['proof_hashes' => static fn (array $path): array => [...$path, hash('sha256', 'surplus', true)]], 'The inclusion proof does not lead to its root hash.'],
        'a proof with a missing hash' => [['proof_hashes' => static fn (array $path): array => array_slice($path, 0, -1)], 'The inclusion proof does not lead to its root hash.'],
        'a proof hash that is not 32 bytes' => [['proof_hashes' => static fn (array $path): array => [...array_slice($path, 0, -1), substr((string) end($path), 0, 31)]], 'is not 32 bytes'],
        'a checkpoint under another key hint' => [['tamper' => 'checkpoint-hint'], 'The checkpoint signature does not verify with the Rekor key.'],
        'a checkpoint of another log' => [['checkpoint_origin' => 'rekor.example - 1'], "is not a checkpoint of the entry's log"],
        'a subject named twice' => [['statement' => ['subject' => [['name' => 'SHA256SUMS', 'digest' => ['sha256' => '0']], ['name' => 'SHA256SUMS', 'digest' => ['sha256' => '1']]]]], 'one name twice'],
    ]);

    it('verifies an entry that is alone in its log', function (): void {
        $signer = SigstoreTestSigner::instance();

        new SigstoreBundleVerifier($signer->trustRoot())->verify($signer->bundle(['SHA256SUMS' => 'sums'], ['single_leaf' => true]), SigstoreTestSigner::identity(), ReleaseProvenance::Issuer, ['SHA256SUMS' => hash('sha256', 'sums')]);

        expect(true)->toBeTrue();
    });

    it('refuses a bundle that does not name a required file', function (): void {
        $signer = SigstoreTestSigner::instance();

        expect(fn () => new SigstoreBundleVerifier($signer->trustRoot())->verify($signer->bundle(['SHA256SUMS' => 'sums']), SigstoreTestSigner::identity(), ReleaseProvenance::Issuer, ['SHA256SUMS' => hash('sha256', 'sums'), 'manifest.json' => hash('sha256', '{}')]))
            ->toThrow(ReleaseProvenanceRefused::class, 'does not name [manifest.json]');
    });

    it('refuses a bundle with only RFC 3161 timestamps and no transparency log entry', function (): void {
        $signer = SigstoreTestSigner::instance();
        $bundle = json_decode($signer->bundle(['SHA256SUMS' => 'sums']), true, flags: JSON_THROW_ON_ERROR);
        $bundle['verificationMaterial']['tlogEntries'] = [];
        $bundle['verificationMaterial']['timestampVerificationData'] = ['rfc3161Timestamps' => [['signedTimestamp' => base64_encode('timestamp')]]];

        expect(fn () => new SigstoreBundleVerifier($signer->trustRoot())->verify(json_encode($bundle, JSON_THROW_ON_ERROR), SigstoreTestSigner::identity(), ReleaseProvenance::Issuer, ['SHA256SUMS' => hash('sha256', 'sums')]))
            ->toThrow(ReleaseProvenanceRefused::class, 'exactly one transparency log entry');
    });

    it('reports a malformed trust root as a Gateway fault, not as a refused release', function (string $trustRoot): void {
        $signer = SigstoreTestSigner::instance();

        expect(fn () => new SigstoreBundleVerifier($trustRoot)->verify($signer->bundle(['SHA256SUMS' => 'sums']), SigstoreTestSigner::identity(), ReleaseProvenance::Issuer, ['SHA256SUMS' => hash('sha256', 'sums')]))
            ->toThrow(fn (ReleaseProvenanceUnavailable $unavailable) => expect($unavailable->local)->toBeTrue());
    })->with([
        'not JSON' => ['{"mediaType":'],
        'no logs' => ['{"certificateAuthorities":[]}'],
        'a malformed time' => [fn (): string => str_replace('2020-01-01T00:00:00Z', 'yesterday-ish', SigstoreTestSigner::instance()->trustRoot())],
    ]);

    it('refuses a document that is not a Sigstore bundle v0.3', function (string $bundle): void {
        expect(fn () => new SigstoreBundleVerifier(public_good_trust_root())->verify($bundle, CLI_CLI_SIGNER, ReleaseProvenance::Issuer, CLI_CLI_SUBJECT))
            ->toThrow(ReleaseProvenanceRefused::class);
    })->with([
        'not JSON' => ['<html>Not Found</html>'],
        'an older bundle version' => [fn (): string => changed_cli_cli_bundle(static fn (array $bundle): array => [...$bundle, 'mediaType' => 'application/vnd.dev.sigstore.bundle+json;version=0.2'])],
    ]);
});
