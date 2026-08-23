<?php

declare(strict_types=1);

use Orbit\Core\Firewall\RetainedFirewallProofIdentity;
use Orbit\Core\Firewall\RetainedFirewallProofReceipt;

it('binds a unique Beast Incus target from the exact candidate SHA', function (): void {
    $candidate = str_repeat('ab', 20);

    expect(RetainedFirewallProofIdentity::targetName($candidate))
        ->toBe('orbit-e2e-fw-proof-abababababab');
});

it('rejects candidate, CLI digest, and target identity mismatches', function (
    array $expected,
    array $observed,
    string $message,
): void {
    expect(fn () => RetainedFirewallProofIdentity::assertSame($expected, $observed))
        ->toThrow(InvalidArgumentException::class, $message);
})->with([
    'candidate mismatch' => [
        [
            'candidate' => str_repeat('a', 40),
            'cli_digest' => str_repeat('b', 64),
            'target' => 'orbit-e2e-fw-proof-aaaaaaaaaaaa',
            'host' => 'beast',
        ],
        [
            'candidate' => str_repeat('c', 40),
            'cli_digest' => str_repeat('b', 64),
            'target' => 'orbit-e2e-fw-proof-aaaaaaaaaaaa',
            'host' => 'beast',
        ],
        'candidate SHA mismatch',
    ],
    'cli digest mismatch' => [
        [
            'candidate' => str_repeat('a', 40),
            'cli_digest' => str_repeat('b', 64),
            'target' => 'orbit-e2e-fw-proof-aaaaaaaaaaaa',
            'host' => 'beast',
        ],
        [
            'candidate' => str_repeat('a', 40),
            'cli_digest' => str_repeat('d', 64),
            'target' => 'orbit-e2e-fw-proof-aaaaaaaaaaaa',
            'host' => 'beast',
        ],
        'CLI binary digest mismatch',
    ],
    'target mismatch' => [
        [
            'candidate' => str_repeat('a', 40),
            'cli_digest' => str_repeat('b', 64),
            'target' => 'orbit-e2e-fw-proof-aaaaaaaaaaaa',
            'host' => 'beast',
        ],
        [
            'candidate' => str_repeat('a', 40),
            'cli_digest' => str_repeat('b', 64),
            'target' => 'orbit-e2e-fw-proof-ffffffffffff',
            'host' => 'beast',
        ],
        'target identity mismatch',
    ],
]);

it('refuses cleanup of a fixture it does not own', function (): void {
    $owned = RetainedFirewallProofIdentity::from(
        candidate: str_repeat('a', 40),
        cliDigest: str_repeat('b', 64),
        host: 'beast',
    );

    expect(fn () => $owned->assertOwnedTarget('orbit-e2e-fw-proof-ffffffffffff'))
        ->toThrow(InvalidArgumentException::class, 'cleanup identity mismatch');
});

it('formats a candidate-bound retained-incus receipt', function (): void {
    $receipt = RetainedFirewallProofReceipt::line(
        candidate: str_repeat('a', 40),
        target: 'orbit-e2e-fw-proof-aaaaaaaaaaaa',
        expected: 'allow-list-doctor-remove pass with owned comment and unrelated same-port preserved',
        observed: 'allow-list-doctor-remove passed; managed allow preceded deny; protected same-port survived',
        result: 'passed',
        evidence: '.orbit/evidence/firewall-retained-proof/aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa.json',
    );

    expect($receipt)
        ->toContain('candidate='.str_repeat('a', 40))
        ->toContain('venue=retained-incus')
        ->toContain('environment=dev-fixture')
        ->toContain('target=orbit-e2e-fw-proof-aaaaaaaaaaaa')
        ->toContain('result=passed')
        ->toContain('evidence=`.orbit/evidence/firewall-retained-proof/aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa.json`');
});
