<?php

declare(strict_types=1);

use Orbit\Core\Firewall\RetainedFirewallProofIdentity;
use Orbit\Core\Firewall\RetainedFirewallProofReceipt;

it('binds the owned Beast topology id and instance names', function (): void {
    $identity = RetainedFirewallProofIdentity::from(
        candidate: str_repeat('a', times: 40),
        cliDigest: str_repeat('b', times: 64),
        target: 'dev-501dc2',
        instances: [
            'operator' => 'orbit-e2e-dev-501dc2-operator',
            'gateway' => 'orbit-e2e-dev-501dc2-gateway',
            'dev' => 'orbit-e2e-dev-501dc2-dev',
        ],
    );

    expect($identity->target)
        ->toBe('dev-501dc2')
        ->and($identity->instances['dev'])
        ->toBe('orbit-e2e-dev-501dc2-dev');
});

it('rejects synthetic proof prefixes and foreign instance names', function (
    string $target,
    array $instances,
): void {
    expect(fn () => RetainedFirewallProofIdentity::from(
        candidate: str_repeat('a', times: 40),
        cliDigest: str_repeat('b', times: 64),
        target: $target,
        instances: $instances,
    ))
        ->toThrow(InvalidArgumentException::class, 'target identity mismatch');
})->with([
    'synthetic prefix' => [
        'orbit-e2e-fw-proof-aaaaaaaaaaaa',
        [
            'operator' => 'orbit-e2e-fw-proof-aaaaaaaaaaaa-operator',
            'gateway' => 'orbit-e2e-fw-proof-aaaaaaaaaaaa-gateway',
            'dev' => 'orbit-e2e-fw-proof-aaaaaaaaaaaa-dev',
        ],
    ],
    'foreign instance' => [
        'dev-501dc2',
        [
            'operator' => 'orbit-e2e-dev-501dc2-operator',
            'gateway' => 'orbit-e2e-dev-501dc2-gateway',
            'dev' => 'orbit-e2e-dev-ffff00-dev',
        ],
    ],
]);

it('rejects candidate, CLI digest, and topology identity mismatches', function (
    array $expected,
    array $observed,
    string $message,
): void {
    expect(fn () => RetainedFirewallProofIdentity::assertSame($expected, $observed))
        ->toThrow(InvalidArgumentException::class, $message);
})->with([
    'candidate mismatch' => [
        [
            'candidate' => str_repeat('a', times: 40),
            'cli_digest' => str_repeat('b', times: 64),
            'target' => 'dev-501dc2',
            'host' => 'beast',
        ],
        [
            'candidate' => str_repeat('c', times: 40),
            'cli_digest' => str_repeat('b', times: 64),
            'target' => 'dev-501dc2',
            'host' => 'beast',
        ],
        'candidate SHA mismatch',
    ],
    'cli digest mismatch' => [
        [
            'candidate' => str_repeat('a', times: 40),
            'cli_digest' => str_repeat('b', times: 64),
            'target' => 'dev-501dc2',
            'host' => 'beast',
        ],
        [
            'candidate' => str_repeat('a', times: 40),
            'cli_digest' => str_repeat('d', times: 64),
            'target' => 'dev-501dc2',
            'host' => 'beast',
        ],
        'CLI binary digest mismatch',
    ],
    'target mismatch' => [
        [
            'candidate' => str_repeat('a', times: 40),
            'cli_digest' => str_repeat('b', times: 64),
            'target' => 'dev-501dc2',
            'host' => 'beast',
        ],
        [
            'candidate' => str_repeat('a', times: 40),
            'cli_digest' => str_repeat('b', times: 64),
            'target' => 'dev-ffff00',
            'host' => 'beast',
        ],
        'target identity mismatch',
    ],
]);

it('refuses cleanup of a fixture it does not own', function (): void {
    $owned = RetainedFirewallProofIdentity::from(
        candidate: str_repeat('a', times: 40),
        cliDigest: str_repeat('b', times: 64),
        target: 'dev-501dc2',
        instances: [
            'operator' => 'orbit-e2e-dev-501dc2-operator',
            'gateway' => 'orbit-e2e-dev-501dc2-gateway',
            'dev' => 'orbit-e2e-dev-501dc2-dev',
        ],
    );

    expect(fn () => $owned->assertOwnedTarget('dev-ffff00'))
        ->toThrow(InvalidArgumentException::class, 'cleanup identity mismatch');
});

it('rejects a stale remote checkout that does not match the candidate', function (): void {
    expect(fn () => RetainedFirewallProofIdentity::bindRemote([
        'candidate' => str_repeat('a', times: 40),
        'local_checkout_digest' => str_repeat('b', times: 64),
        'remote_checkout_digest' => str_repeat('b', times: 64),
        'target' => 'dev-501dc2',
        'instances' => [
            'operator' => 'orbit-e2e-dev-501dc2-operator',
            'gateway' => 'orbit-e2e-dev-501dc2-gateway',
            'dev' => 'orbit-e2e-dev-501dc2-dev',
        ],
        'remote_head' => str_repeat('c', times: 40),
    ]))
        ->toThrow(InvalidArgumentException::class, 'candidate SHA mismatch');
});

it('rejects a remote checkout digest that does not match the candidate worktree', function (): void {
    expect(fn () => RetainedFirewallProofIdentity::bindRemote([
        'candidate' => str_repeat('a', times: 40),
        'local_checkout_digest' => str_repeat('b', times: 64),
        'remote_checkout_digest' => str_repeat('d', times: 64),
        'target' => 'dev-501dc2',
        'instances' => [
            'operator' => 'orbit-e2e-dev-501dc2-operator',
            'gateway' => 'orbit-e2e-dev-501dc2-gateway',
            'dev' => 'orbit-e2e-dev-501dc2-dev',
        ],
    ]))
        ->toThrow(InvalidArgumentException::class, 'CLI binary digest mismatch');
});

it('formats a candidate-bound retained-incus receipt for the owned topology', function (): void {
    $receipt = RetainedFirewallProofReceipt::line([
        'candidate' => str_repeat('a', times: 40),
        'target' => 'dev-501dc2',
        'expected' => 'allow-list-doctor-remove pass with owned comment and unrelated same-port preserved',
        'observed' => 'allow-list-doctor-remove passed; managed allow preceded deny; protected same-port survived',
        'result' => 'passed',
        'evidence' => '.orbit/evidence/firewall-retained-proof/aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa.json',
    ]);

    expect($receipt)
        ->toContain('candidate='.str_repeat('a', times: 40))
        ->toContain('venue=retained-incus')
        ->toContain('environment=dev-fixture')
        ->toContain('target=dev-501dc2')
        ->not
        ->toContain('orbit-e2e-fw-proof-')
        ->toContain('result=passed')
        ->toContain('evidence=`.orbit/evidence/firewall-retained-proof/aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa.json`');
});
