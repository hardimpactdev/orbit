<?php

declare(strict_types=1);

use App\Domain\Operations\OperationIdempotency;

it('runs a callback under another start identity and puts the previous one back', function (): void {
    $identity = new OperationIdempotency;
    $identity->kind = 'instance.deploy';
    $identity->key = 'request-key';
    $identity->fingerprint = 'request-fingerprint';

    $seen = $identity->under('tasks.cancel', 'proof:1:tasks.cancel', 'inner-fingerprint', fn (): array => [$identity->kind, $identity->key, $identity->fingerprint]);

    expect($seen)->toBe(['tasks.cancel', 'proof:1:tasks.cancel', 'inner-fingerprint'])
        ->and([$identity->kind, $identity->key, $identity->fingerprint])->toBe(['instance.deploy', 'request-key', 'request-fingerprint']);
});

it('puts the previous start identity back when the callback throws', function (): void {
    $identity = new OperationIdempotency;

    expect(fn () => $identity->under('tasks.cancel', 'key', 'fingerprint', fn () => throw new RuntimeException('refused')))->toThrow(RuntimeException::class)
        ->and([$identity->kind, $identity->key, $identity->fingerprint])->toBe([null, null, null]);
});
