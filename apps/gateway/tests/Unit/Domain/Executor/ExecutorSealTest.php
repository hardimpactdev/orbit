<?php

declare(strict_types=1);

use App\Domain\Executor\ExecutorOrderSigner;

/** The box the executor's Rust test also opens (apps/agent/contract/fixtures/sealed-output.json). */
function sealFixture(): array
{
    return json_decode((string) file_get_contents(dirname(base_path(), 2).'/apps/agent/contract/fixtures/sealed-output.json'), true, flags: JSON_THROW_ON_ERROR);
}

it('names the X25519 key that its signing key opens, and opens the executor\'s sealed box', function (): void {
    $fixture = sealFixture();
    $signer = new ExecutorOrderSigner((string) base64_decode($fixture['signing_secret'], true));

    expect($signer->sealRecipient())->toBe($fixture['recipient'])
        ->and($signer->open($fixture['sealed']))->toBe($fixture['plaintext']);
});

it('refuses a box another key sealed, or one that is not base64', function (string $sealed): void {
    $signer = new ExecutorOrderSigner(sodium_crypto_sign_secretkey(sodium_crypto_sign_keypair()));

    $signer->open($sealed);
})->throws(RuntimeException::class, 'executor.seal_invalid')->with([
    'another key' => fn (): string => sealFixture()['sealed'],
    'not base64' => '!!!',
    'empty' => '',
]);
