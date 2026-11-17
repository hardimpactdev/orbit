<?php

declare(strict_types=1);

use App\Domain\Executor\ExecutorOrderSigner;

beforeEach(function (): void {
    require_once base_path('../../tools/swamp-proof-guest/executor-order.php');
    $pair = sodium_crypto_sign_keypair();
    $this->public = sodium_crypto_sign_publickey($pair);
    $this->secretPath = tempnam(sys_get_temp_dir(), 'executor-proof-test-');
    file_put_contents($this->secretPath, base64_encode(sodium_crypto_sign_secretkey($pair)));
});

afterEach(fn () => @unlink($this->secretPath));

it('signs the unknown_action proof order with an action no executor allows, under a valid signature', function (): void {
    $made = proofOrder($this->secretPath, ['tamper' => 'unknown_action', 'node' => '7', 'target' => '7', 'operation' => '42']);
    [$payload, $signature] = explode('.', $made['order'], 2);
    $bytes = (string) base64_decode($payload, true);
    $fields = json_decode($bytes, true, flags: JSON_THROW_ON_ERROR);

    // Only the action is wrong: the executor must refuse with unknown_action, never run or refuse on the target.
    expect(sodium_crypto_sign_verify_detached((string) base64_decode($signature, true), $bytes, $this->public))->toBeTrue()
        ->and($fields['action'])->toBe(PROOF_UNKNOWN_ACTION)
        ->and(ExecutorOrderSigner::Actions)->not->toContain($fields['action']);
});
