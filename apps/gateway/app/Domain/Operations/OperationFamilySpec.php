<?php

declare(strict_types=1);

namespace App\Domain\Operations;

use App\Domain\Shared\ResourceOperationException;
use LogicException;

/** Compiles a fixed-command family spec into the existing enforced executor contract. */
final class OperationFamilySpec
{
    public static function available(string $kind): bool
    {
        return app(OperationKinds::class)->definition($kind) !== null;
    }

    public static function assertAvailable(string $kind): void
    {
        if (! self::available($kind)) {
            throw new ResourceOperationException('operation.kind_unavailable', 'The operation requires its shared executor contracts.', 503);
        }
    }

    /**
     * @param  array{completion: class-string<OperationCompletion>, execution: class-string<OperationFamilyExecution>, transport: string, done_step: string, variant: string, requires?: list<string>, result_fields: array<mixed>}  $family
     * @param  array<string, array{commands: list<string>, done_when: array<string, string>, admission?: class-string<OperationChildAdmission>}>  $actions
     * @return array<string, array<string, mixed>>
     */
    public static function compile(array $family, array $actions): array
    {
        if (array_diff(array_keys($family), ['completion', 'execution', 'transport', 'done_step', 'variant', 'requires', 'result_fields']) !== []) {
            throw new LogicException('Unsupported family spec field.');
        }
        if (array_any($family['requires'] ?? [], static fn (string $capability): bool => ! OperationKinds::supports($capability))) {
            return [];
        }
        $kinds = [];
        foreach ($actions as $kind => $action) {
            if (array_diff(array_keys($action), ['commands', 'done_when', 'admission']) !== [] || $action['done_when'] === []) {
                throw new LogicException('Unsupported family action spec.');
            }
            $commands = array_values(array_unique($action['commands']));
            $proofs = [];
            foreach ($action['done_when'] as $variant => $command) {
                if (! in_array($command, $commands, true)) {
                    throw new LogicException('Completion command must belong to the family action.');
                }
                $proofs[$variant] = ['step' => $family['done_step'], 'transport' => $family['transport'], 'name' => $command, 'result' => []];
            }
            $kinds[$kind] = [
                'executor' => 'swamp', 'completion' => $family['completion'], 'execution' => $family['execution'],
                'runs' => array_map(static fn (string $command): array => [
                    'transport' => $family['transport'], 'name' => $command, 'input_fields' => [], 'input_values' => [], 'secrets' => [],
                ], $commands),
                'children' => [], 'completion_contract' => ['completion' => ['by' => $family['variant'], 'map' => $proofs]],
                'result_fields' => $family['result_fields'],
                ...isset($action['admission']) ? ['admission' => $action['admission']] : [],
            ];
        }

        return $kinds;
    }
}
