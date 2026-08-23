<?php

declare(strict_types=1);

namespace Orbit\Core\Firewall;

use InvalidArgumentException;

final class RetainedFirewallProofIdentity
{
    public const string Host = 'beast';

    public const string TargetPrefix = 'orbit-e2e-fw-proof-';

    private function __construct(
        public string $candidate,
        public string $cliDigest,
        public string $target,
        public string $host,
    ) {}

    public static function from(string $candidate, string $cliDigest, string $host = self::Host): self
    {
        self::requireSha($candidate);
        self::requireDigest($cliDigest);

        if ($host !== self::Host) {
            throw new InvalidArgumentException('target identity mismatch');
        }

        return new self($candidate, $cliDigest, self::targetName($candidate), $host);
    }

    public static function targetName(string $candidateSha): string
    {
        self::requireSha($candidateSha);

        return self::TargetPrefix.substr($candidateSha, 0, 12);
    }

    public static function digestFile(string $path): string
    {
        $digest = is_file($path) ? hash_file('sha256', $path) : false;

        if (! is_string($digest)) {
            throw new InvalidArgumentException('CLI binary digest mismatch');
        }

        return $digest;
    }

    /**
     * @param  array{candidate: string, cli_digest: string, target: string, host: string}  $expected
     * @param  array{candidate: string, cli_digest: string, target: string, host: string}  $observed
     */
    public static function assertSame(array $expected, array $observed): void
    {
        if ($expected['candidate'] !== $observed['candidate']) {
            throw new InvalidArgumentException('candidate SHA mismatch');
        }

        if ($expected['cli_digest'] !== $observed['cli_digest']) {
            throw new InvalidArgumentException('CLI binary digest mismatch');
        }

        if ($expected['target'] !== $observed['target'] || $expected['host'] !== $observed['host']) {
            throw new InvalidArgumentException('target identity mismatch');
        }
    }

    public function assertOwnedTarget(string $target): void
    {
        if ($target !== $this->target) {
            throw new InvalidArgumentException('cleanup identity mismatch');
        }
    }

    /**
     * @return array{candidate: string, cli_digest: string, target: string, host: string}
     */
    public function toArray(): array
    {
        return [
            'candidate' => $this->candidate,
            'cli_digest' => $this->cliDigest,
            'target' => $this->target,
            'host' => $this->host,
        ];
    }

    private static function requireSha(string $value): void
    {
        if (preg_match('/^[a-f0-9]{40}$/', $value) !== 1) {
            throw new InvalidArgumentException('candidate SHA mismatch');
        }
    }

    private static function requireDigest(string $value): void
    {
        if (preg_match('/^[a-f0-9]{64}$/', $value) !== 1) {
            throw new InvalidArgumentException('CLI binary digest mismatch');
        }
    }
}
