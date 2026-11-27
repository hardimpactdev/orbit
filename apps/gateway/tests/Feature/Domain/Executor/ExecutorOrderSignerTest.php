<?php

declare(strict_types=1);

use App\Domain\Executor\ExecutorOrderSigner;
use App\Models\Operation;
use Illuminate\Support\Carbon;

/** A throwaway key for one test; nothing outside the test sees it. */
function executor_test_keypair(): array
{
    $pair = sodium_crypto_sign_keypair();

    return [sodium_crypto_sign_secretkey($pair), sodium_crypto_sign_publickey($pair)];
}

/** An Operation as the signer reads it: its id and its node_id. */
function executor_operation(?int $id = 42, ?int $nodeId = 7): Operation
{
    return (new Operation)->forceFill(['id' => $id, 'node_id' => $nodeId]);
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

it('signs an order for an Operation that verifies with its public key and holds exactly the ADR fields', function (): void {
    [$secret, $public] = executor_test_keypair();
    Carbon::setTestNow(Carbon::createFromTimestampMs(1_800_000_000_000));

    $order = (new ExecutorOrderSigner($secret))->sign(executor_operation(), 'proof.noop', '7');
    [$payload, $bytes, $signature] = executor_split($order->wire);

    expect(sodium_crypto_sign_verify_detached($signature, $bytes, $public))->toBeTrue()
        ->and(array_keys($payload))->toBe(['action', 'node', 'target', 'operation', 'nonce', 'expires_ms'])
        ->and($payload)->toMatchArray(['action' => 'proof.noop', 'node' => '7', 'target' => '7', 'operation' => '42'])
        ->and($order->operation)->toBe('42')
        ->and($payload['nonce'])->toBe($order->nonce)->toMatch('/\A[0-9a-f]{32}\z/')
        ->and($payload['expires_ms'])->toBe(1_800_000_005_000)->toBe($order->expiresMs);
});

it('builds the same payload bytes as the shared fixture that the executor verifies', function (string $file, int $expiry): void {
    $fixture = json_decode((string) file_get_contents(base_path('../agent/contract/fixtures/'.$file)), true, flags: JSON_THROW_ON_ERROR);
    $fields = $fixture['fields'];
    [, $bytes, $signature] = executor_split($fixture['wire']);

    expect(ExecutorOrderSigner::payload($fields['action'], $fields['node'], $fields['target'], $fields['operation'], $fields['nonce'], $fields['expires_ms'], input: $fields['input'] ?? []))->toBe($bytes)
        ->and(sodium_crypto_sign_verify_detached($signature, $bytes, (string) base64_decode($fixture['public_key'], true)))->toBeTrue()
        ->and($fields['expires_ms'] - $fixture['now_ms'])->toBe($expiry);
})->with([
    'proof' => ['executor-order.json', ExecutorOrderSigner::ExpiresAfterMs],
    'clone removal' => ['executor-instance-destroy.json', ExecutorOrderSigner::StepExpiresAfterMs],
    'site withdrawal' => ['executor-route-withdraw-site.json', ExecutorOrderSigner::StepExpiresAfterMs],
    'guarded DNS records' => ['executor-dns-guarded-records.json', ExecutorOrderSigner::StepExpiresAfterMs],
    'copy sync import' => ['executor-instance-sync-base.json', ExecutorOrderSigner::StepExpiresAfterMs],
    'copy sync scratch' => ['executor-instance-clear-base.json', ExecutorOrderSigner::StepExpiresAfterMs],
]);

it('gives every order a new nonce', function (): void {
    [$secret] = executor_test_keypair();
    $signer = new ExecutorOrderSigner($secret);
    $nonces = array_map(fn (): string => $signer->sign(executor_operation(), 'proof.noop', '7')->nonce, range(1, 200));

    expect(array_unique($nonces))->toHaveCount(200);
});

it('refuses an unknown action and a target outside the executor form', function (string $action, string $target): void {
    [$secret] = executor_test_keypair();

    expect(fn () => (new ExecutorOrderSigner($secret))->sign(executor_operation(), $action, $target))->toThrow(InvalidArgumentException::class);
})->with([
    'unknown action' => ['hibernate', '7'],
    'bad character' => ['proof.noop', '7 8'],
    'empty target' => ['proof.noop', ''],
    'over 128 bytes' => ['proof.noop', str_repeat('a', 129)],
]);

it('signs only for an Operation with an id and a node_id', function (?int $id, ?int $nodeId): void {
    [$secret] = executor_test_keypair();

    expect(fn () => (new ExecutorOrderSigner($secret))->sign(executor_operation($id, $nodeId), 'proof.noop', '7'))
        ->toThrow(InvalidArgumentException::class, 'An executor order needs an Operation with an id and a node_id.');
})->with([
    'no node_id' => [42, null],
    'not saved' => [null, 7],
]);

it('keeps the largest possible order within 4 KB', function (): void {
    [$secret] = executor_test_keypair();
    $order = (new ExecutorOrderSigner($secret))->sign(executor_operation(PHP_INT_MAX, PHP_INT_MAX), 'proof.noop', str_repeat('a', 128));

    expect(strlen($order->wire))->toBeLessThanOrEqual(ExecutorOrderSigner::MaxWireBytes);
});

it('reads its key from the Gateway config and refuses a missing or malformed one', function (): void {
    [$secret, $public] = executor_test_keypair();
    config(['orbit.executor.signing_key' => base64_encode($secret)]);
    [, $bytes, $signature] = executor_split(ExecutorOrderSigner::fromConfig()->sign(executor_operation(), 'proof.noop', '7')->wire);
    expect(sodium_crypto_sign_verify_detached($signature, $bytes, $public))->toBeTrue();

    config(['orbit.executor.signing_key' => null]);
    expect(fn () => ExecutorOrderSigner::fromConfig())->toThrow(RuntimeException::class, 'ORBIT_EXECUTOR_SIGNING_KEY is not a base64 Ed25519 secret key.');
    config(['orbit.executor.signing_key' => base64_encode('short')]);
    expect(fn () => ExecutorOrderSigner::fromConfig())->toThrow(RuntimeException::class);
});

it('signs sleep and discard on Instance targets', function (string $action): void {
    [$secret, $public] = executor_test_keypair();
    $order = (new ExecutorOrderSigner($secret))->sign(executor_operation(), $action, '23');
    [$payload, $bytes, $signature] = executor_split($order->wire);
    expect($payload)->toMatchArray(['action' => $action, 'target' => '23'])
        ->and(sodium_crypto_sign_verify_detached($signature, $bytes, $public))->toBeTrue();
})->with(['sleep', 'wake', 'discard']);
