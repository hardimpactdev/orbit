<?php

declare(strict_types=1);

namespace App\Infrastructure\AgentCommands;

use App\Domain\Operations\EffectReceiptMetadata;
use App\Domain\Shared\ResourceOperationException;
use App\Models\Node;
use App\Support\ValidatedData;

/** Reads the same private store used by the command registry and named remote programs. */
final readonly class AgentEffectReceipts
{
    public function __construct(private AgentCommandRunner $runner) {}

    /** @param array<string, mixed> $run
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    public static function input(#[\SensitiveParameter] array $run, #[\SensitiveParameter] array $input): array
    {
        return EffectReceiptMetadata::fromRun($run)->attach([...$input, 'effect_key' => $run['effect_key']]);
    }

    /** @return array{effect_key: string, state: string, result: array<string, mixed>|null, result_digest: string|null, sanitized: bool, exit_code: int|null, finished_at: string|null} */
    public function read(Node $node, string $command, string $effectKey, ?string $owner = null): array
    {
        return self::decode($this->runner->run($node, 'agent.effect-receipt', ['command' => $command, 'effect_key' => $effectKey, 'owner' => $owner]), $effectKey);
    }

    /** @param array<string, mixed> $receipt
     * @return array{effect_key: string, state: string, result: array<string, mixed>|null, result_digest: string|null, sanitized: bool, exit_code: int|null, finished_at: string|null}
     */
    public static function decode(array $receipt, string $effectKey): array
    {
        $state = $receipt['state'] ?? null;
        $response = $receipt['result'] ?? null;
        if (($receipt['effect_key'] ?? null) !== $effectKey || ! in_array($state, ['succeeded', 'failed', 'running', 'unknown'], true)
            || (in_array($state, ['unknown', 'running'], true) ? $response !== null : ! is_array($response))) {
            throw new ResourceOperationException('operation.receipt_invalid', 'The target returned an invalid effect receipt.', 502);
        }
        if (in_array($state, ['succeeded', 'failed'], true) && ($receipt['sanitized'] ?? false) !== true) {
            throw new ResourceOperationException('operation.receipt_unproven', 'The target receipt has no proven sanitization.', 409);
        }
        $result = null;
        if (is_array($response)) {
            $result = ($response['ok'] ?? null) === true ? ValidatedData::object($response['data'] ?? [])
                : ['error_code' => ValidatedData::string(ValidatedData::object($response['error'] ?? [])['code'] ?? 'operation.execution_failed')];
        }

        return ['effect_key' => $effectKey, 'state' => $state, 'result' => $result, 'sanitized' => ($receipt['sanitized'] ?? false) === true,
            'result_digest' => ValidatedData::nullableString($receipt['result_digest'] ?? null),
            'exit_code' => isset($receipt['exit_code']) ? ValidatedData::integer($receipt['exit_code']) : null,
            'finished_at' => ValidatedData::nullableString($receipt['finished_at'] ?? null)];
    }
}
