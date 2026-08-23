<?php

declare(strict_types=1);

namespace Orbit\Core\Firewall;

use InvalidArgumentException;

/**
 * @mago-expect lint:cyclomatic-complexity
 */
final class RetainedFirewallProofIdentity
{
    public const string HOST = 'beast';

    /**
     * @var list<string>
     */
    public const array CHECKOUT_PATHS = [
        'apps/cli/orbit',
        'apps/cli/app/Services/Firewall/LocalFirewallRuleAction.php',
        'apps/gateway/app/Services/Firewall/FirewallTargetPlatform.php',
        'packages/core/src/Firewall/ManagedUfwComment.php',
        'packages/core/src/Firewall/RetainedFirewallProofIdentity.php',
        'bin/orbit-firewall-retained-proof',
    ];

    /**
     * @param  array{operator: string, gateway: string, dev: string}  $instances
     */
    private function __construct(
        public string $candidate,
        public string $cliDigest,
        public string $target,
        public string $host,
        public array $instances,
    ) {}

    /**
     * @param  array<string, string>  $instances
     */
    public static function from(
        string $candidate,
        string $cliDigest,
        string $target,
        array $instances,
        string $host = self::HOST,
    ): self {
        self::requireSha($candidate);
        self::requireDigest($cliDigest);

        if ($host !== self::HOST) {
            throw new InvalidArgumentException('target identity mismatch');
        }

        self::assertOwnedInstances($target, $instances);

        return new self($candidate, $cliDigest, $target, $host, [
            'operator' => $instances['operator'],
            'gateway' => $instances['gateway'],
            'dev' => $instances['dev'],
        ]);
    }

    /**
     * @param  array{candidate: string, local_checkout_digest: string, remote_checkout_digest: string, target: string, instances: array<string, string>, host?: string, remote_head?: ?string}  $observed
     */
    public static function bindRemote(array $observed): self
    {
        $remoteHead = $observed['remote_head'] ?? null;

        if ($remoteHead !== null && $remoteHead !== $observed['candidate']) {
            throw new InvalidArgumentException('candidate SHA mismatch');
        }

        if ($observed['local_checkout_digest'] !== $observed['remote_checkout_digest']) {
            throw new InvalidArgumentException('CLI binary digest mismatch');
        }

        return self::from(
            $observed['candidate'],
            $observed['remote_checkout_digest'],
            $observed['target'],
            $observed['instances'],
            $observed['host'] ?? self::HOST,
        );
    }

    public static function digestCheckout(string $root): string
    {
        $parts = [];

        foreach (self::CHECKOUT_PATHS as $relative) {
            $digest = hash_file('sha256', rtrim($root, characters: '/').'/'.$relative);

            if (! is_string($digest)) {
                throw new InvalidArgumentException('CLI binary digest mismatch');
            }

            $parts[] = $relative.':'.$digest;
        }

        return hash('sha256', implode("\n", $parts));
    }

    /**
     * @param  array<string, string>  $instances
     */
    public static function assertOwnedInstances(string $topologyId, array $instances): void
    {
        if (preg_match('/^dev-[a-f0-9]{6}$/', $topologyId) !== 1) {
            throw new InvalidArgumentException('target identity mismatch');
        }

        foreach (['operator', 'gateway', 'dev'] as $role) {
            if (($instances[$role] ?? '') !== 'orbit-e2e-'.$topologyId.'-'.$role) {
                throw new InvalidArgumentException('target identity mismatch');
            }
        }
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
     * @return array{candidate: string, cli_digest: string, target: string, host: string, instances: array{operator: string, gateway: string, dev: string}}
     */
    public function toArray(): array
    {
        return [
            'candidate' => $this->candidate,
            'cli_digest' => $this->cliDigest,
            'target' => $this->target,
            'host' => $this->host,
            'instances' => $this->instances,
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
