<?php

declare(strict_types=1);

namespace App\Infrastructure\RemotePrograms;

use App\Domain\GitHub\GitReadEnvironment;
use App\Domain\Operations\EffectReceiptMetadata;
use App\Domain\RemotePrograms\ProgramResult;
use App\Domain\RemotePrograms\RemoteProgram;
use App\Domain\RemotePrograms\RemoteProgramRunner;
use App\Domain\Shared\ResourceOperationException;
use App\Infrastructure\AgentCommands\AgentCommandRefused;
use App\Infrastructure\AgentCommands\AgentCommandRunner;
use App\Infrastructure\AgentCommands\AgentEffectReceipts;
use App\Models\Node;
use App\Support\ValidatedData;
use Closure;

/** Adds receipts to the existing fixed catalog runner; never accepts executable source from callers. */
final readonly class KeyedRemoteProgramRunner
{
    public function __construct(private RemoteProgramRunner $runner, private AgentCommandRunner $agent, private AgentEffectReceipts $receipts) {}

    /** @param array<string, mixed>|list<string> $input */
    public function run(Node $node, RemoteProgram $program, #[\SensitiveParameter] array $input, string $effectKey,
        #[\SensitiveParameter] ?GitReadEnvironment $gitRead = null, ?float $timeoutSeconds = null, ?Closure $cancelled = null, ?EffectReceiptMetadata $effect = null): ProgramResult
    {
        if ($effect === null) {
            throw new ResourceOperationException('operation.receipt_unproven', 'Protected effect metadata is required before execution.', 422);
        }
        $identity = ['command' => 'remote_program.'.$program->name, 'effect_key' => $effectKey,
            'fingerprint' => hash('sha256', json_encode(self::ordered([$program->digest(), $program->user, $input, $gitRead?->variables, $effect->identity()]), JSON_THROW_ON_ERROR))];
        try {
            $reservation = $this->agent->run($node, 'agent.effect-reserve', $effect->attach([...$identity, 'timeout_seconds' => $program->timeoutSeconds]));
        } catch (AgentCommandRefused $exception) {
            throw new ResourceOperationException('operation.execution_uncertain', 'The program reservation is unproven.', 409);
        }
        if (($reservation['reserved'] ?? false) === true) {
            try {
                $result = $this->runner->run($node, $program, $input, $gitRead, $timeoutSeconds, $cancelled);
            } catch (\Throwable) {
                throw new ResourceOperationException('operation.execution_uncertain', 'The program did not return a proven result.', 409);
            }
            try {
                $receipt = AgentEffectReceipts::decode($this->agent->run($node, 'agent.effect-complete', $effect->attach([...$identity,
                    'result' => ['outcome' => $result->outcome, 'data' => $result->data, 'log' => $result->log]])), $effectKey);
            } catch (\Throwable) {
                throw new ResourceOperationException('operation.execution_uncertain', 'The program ran without a proven saved receipt.', 409);
            }
        } else {
            $receipt = AgentEffectReceipts::decode(ValidatedData::object($reservation['receipt'] ?? []), $effectKey);
        }
        if (in_array($receipt['state'], ['unknown', 'running'], true) || $receipt['result'] === null) {
            throw new ResourceOperationException('operation.execution_uncertain', 'Read the keyed receipt; the program cannot be repeated.', 409);
        }
        if ($receipt['effect_nonce'] !== $effect->identity()['nonce'] || $receipt['effect_created_at'] !== $effect->identity()['created_at']) {
            throw new ResourceOperationException('operation.execution_uncertain', 'The target receipt belongs to another Operation identity.', 409);
        }
        $effect->received($receipt);
        $result = $receipt['result'];

        return new ProgramResult(ValidatedData::string($result['outcome']), ValidatedData::object($result['data']), ValidatedData::string($result['log'] ?? ''));
    }

    /** @return array{effect_key: string, state: string, result: array<string, mixed>|null, result_digest: string|null, sanitized: bool, effect_nonce: string|null, effect_created_at: int|null, exit_code: int|null, finished_at: string|null} */
    public function receipt(Node $node, RemoteProgram $program, string $effectKey): array
    {
        return $this->receipts->read($node, 'remote_program.'.$program->name, $effectKey);
    }

    private static function ordered(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }
        $ordered = array_map(self::ordered(...), $value);
        if (! array_is_list($ordered)) {
            ksort($ordered);
        }

        return $ordered;
    }
}
