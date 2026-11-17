<?php

declare(strict_types=1);

use App\Domain\Executor\ExecutorOrderSigner;
use Illuminate\Support\Carbon;

/** A throwaway key for one test; nothing outside the test sees it. */
function executor_test_keypair(): array
{
    $pair = sodium_crypto_sign_keypair();

    return [sodium_crypto_sign_secretkey($pair), sodium_crypto_sign_publickey($pair)];
}

/** @return array{0: array<string, mixed>, 1: string, 2: string} */
function executor_split(string $wire): array
{
    [$payload, $signature] = explode('.', $wire, 2);
    $bytes = base64_decode($payload, true);
    expect($bytes)->toBeString();

    return [json_decode((string) $bytes, true, flags: JSON_THROW_ON_ERROR), (string) $bytes, (string) base64_decode($signature, true)];
}

afterEach(fn () => Carbon::setTestNow());

it('signs an order that verifies with its public key and holds exactly the ADR fields', function (): void {
    [$secret, $public] = executor_test_keypair();
    Carbon::setTestNow(Carbon::createFromTimestampMs(1_800_000_000_000));

    $order = (new ExecutorOrderSigner($secret))->sign('proof.noop', '7', '7', 'op-1');
    [$payload, $bytes, $signature] = executor_split($order->wire);

    expect(sodium_crypto_sign_verify_detached($signature, $bytes, $public))->toBeTrue()
        ->and(array_keys($payload))->toBe(['action', 'node', 'target', 'operation', 'nonce', 'expires_ms'])
        ->and($payload)->toMatchArray(['action' => 'proof.noop', 'node' => '7', 'target' => '7', 'operation' => 'op-1'])
        ->and($payload['nonce'])->toBe($order->nonce)->toMatch('/\A[0-9a-f]{32}\z/')
        ->and($payload['expires_ms'])->toBe(1_800_000_005_000)->toBe($order->expiresMs);
});

it('builds the same payload bytes as the shared fixture that the executor verifies', function (): void {
    $fixture = json_decode((string) file_get_contents(base_path('../agent/contract/fixtures/executor-order.json')), true, flags: JSON_THROW_ON_ERROR);
    $fields = $fixture['fields'];
    [, $bytes, $signature] = executor_split($fixture['wire']);

    expect(ExecutorOrderSigner::payload($fields['action'], $fields['node'], $fields['target'], $fields['operation'], $fields['nonce'], $fields['expires_ms']))->toBe($bytes)
        ->and(sodium_crypto_sign_verify_detached($signature, $bytes, (string) base64_decode($fixture['public_key'], true)))->toBeTrue()
        ->and($fields['expires_ms'] - $fixture['now_ms'])->toBe(ExecutorOrderSigner::ExpiresAfterMs);
});

it('gives every order a new nonce', function (): void {
    [$secret] = executor_test_keypair();
    $signer = new ExecutorOrderSigner($secret);
    $nonces = array_map(fn (): string => $signer->sign('proof.noop', '7', '7', 'op-1')->nonce, range(1, 200));

    expect(array_unique($nonces))->toHaveCount(200);
});

it('refuses an unknown action, an id outside the executor form, and an order over 4 KB', function (string $action, string $node, string $operation): void {
    [$secret] = executor_test_keypair();

    expect(fn () => (new ExecutorOrderSigner($secret))->sign($action, $node, $node, $operation))->toThrow(InvalidArgumentException::class);
})->with([
    'unknown action' => ['sleep', '7', 'op-1'],
    'bad character' => ['proof.noop', '7', 'op 1'],
    'empty id' => ['proof.noop', '', 'op-1'],
    'over 128 bytes' => ['proof.noop', '7', str_repeat('a', 129)],
]);

it('keeps the largest possible order within 4 KB', function (): void {
    [$secret] = executor_test_keypair();
    $id = str_repeat('a', 128);
    $order = (new ExecutorOrderSigner($secret))->sign('proof.noop', $id, $id, $id);

    expect(strlen($order->wire))->toBeLessThanOrEqual(ExecutorOrderSigner::MaxWireBytes);
});

it('reads its key from the Gateway config and refuses a missing or malformed one', function (): void {
    [$secret, $public] = executor_test_keypair();
    config(['orbit.executor.signing_key' => base64_encode($secret)]);
    [, $bytes, $signature] = executor_split(ExecutorOrderSigner::fromConfig()->sign('proof.noop', '7', '7', 'op-1')->wire);
    expect(sodium_crypto_sign_verify_detached($signature, $bytes, $public))->toBeTrue();

    config(['orbit.executor.signing_key' => null]);
    expect(fn () => ExecutorOrderSigner::fromConfig())->toThrow(RuntimeException::class, 'ORBIT_EXECUTOR_SIGNING_KEY is not a base64 Ed25519 secret key.');
    config(['orbit.executor.signing_key' => base64_encode('short')]);
    expect(fn () => ExecutorOrderSigner::fromConfig())->toThrow(RuntimeException::class);
});
