<?php

declare(strict_types=1);

namespace App\Domain\Operations;

use App\Domain\Shared\ResourceOperationException;
use App\Models\Operation;
use App\Support\ValidatedData;
use Illuminate\Support\Arr;
use LogicException;

/** One validated proof shape, selected only from immutable Operation input. */
final class OperationCompletionContracts
{
    /** @param array<string,mixed> $contract
     * @return list<string>
     */
    public static function settledKinds(array $contract): array
    {
        $variants = isset($contract['completion']) ? ValidatedData::object(ValidatedData::object($contract['completion'])['map']) : [$contract];
        $kinds = [];
        foreach ($variants as $proof) {
            $proof = ValidatedData::object($proof);
            $kinds = [...$kinds, ...ValidatedData::stringList($proof['children_settled'] ?? [])];
        }

        return array_values(array_unique($kinds));
    }

    /** @param array<string,mixed> $contract
     * @param  list<string>  $childKinds
     */
    public static function validate(array $contract, array $childKinds): void
    {
        if (array_key_exists('completion', $contract)) {
            self::keys($contract, ['completion']);
            if (! is_array($contract['completion'])) {
                self::invalid();
            }
            $variant = $contract['completion'];
            self::keys($variant, ['by', 'map']);
            if (! is_string($variant['by'] ?? null) || preg_match('/\A[a-zA-Z_][a-zA-Z0-9_.-]*\z/D', $variant['by']) !== 1
                || ! is_array($variant['map'] ?? null) || $variant['map'] === []) {
                self::invalid();
            }
            foreach ($variant['map'] as $key => $proof) {
                if (! is_string($key) || $key === '' || ! is_array($proof)) {
                    self::invalid();
                }
                self::validateProof($proof, $childKinds);
            }

            return;
        }
        self::validateProof($contract, $childKinds);
    }

    /** @param array<array-key,mixed> $proof
     * @param  list<string>  $childKinds
     */
    private static function validateProof(array $proof, array $childKinds): void
    {
        self::keys($proof, ['step', 'transport', 'name', 'result', 'children_succeeded', 'children_settled']);
        $stepKeys = array_intersect(['step', 'transport', 'name', 'result'], array_keys($proof));
        if (($stepKeys !== [] && count($stepKeys) !== 4)
            || ($stepKeys === [] && ($proof['children_succeeded'] ?? []) === [] && ($proof['children_settled'] ?? []) === [])) {
            throw new LogicException('An Operation kind requires a complete enforced completion proof.');
        }
        if ($stepKeys !== []) {
            foreach (['step', 'transport', 'name'] as $field) {
                if (! is_string($proof[$field]) || $proof[$field] === '') {
                    self::invalid();
                }
            }
            if (! is_array($proof['result']) || ($proof['result'] !== [] && array_is_list($proof['result']))) {
                self::invalid();
            }
        }
        foreach (['children_succeeded', 'children_settled'] as $mode) {
            $required = $proof[$mode] ?? [];
            if (! is_array($required) || ! array_is_list($required)) {
                self::invalid();
            }
            foreach ($required as $kind) {
                if (! is_string($kind) || ! in_array($kind, $childKinds, true)) {
                    self::invalid();
                }
            }
        }
    }

    /** @param array<array-key,mixed> $value
     * @param  list<string>  $allowed
     */
    private static function keys(array $value, array $allowed): void
    {
        if (array_diff(array_keys($value), $allowed) !== []) {
            throw new LogicException('Operation kind has unsupported completion_contract keys: '.implode(', ', array_diff(array_keys($value), $allowed)).'.');
        }
    }

    private static function invalid(): never
    {
        throw new LogicException('Operation completion contracts require a supported proof and declared variants.');
    }

    /** @param array<string,mixed> $contract
     * @return array{step?:string,transport?:string,name?:string,result?:array<string,mixed>,children_succeeded?:list<string>,children_settled?:list<string>}
     */
    public static function select(Operation $operation, array $contract): array
    {
        if (isset($contract['completion'])) {
            $selection = ValidatedData::object($contract['completion']);
            $path = ValidatedData::string($selection['by']);
            $variant = Arr::get($operation->input, $path);
            $map = ValidatedData::object($selection['map']);
            if (! is_string($variant) || ! array_key_exists($variant, $map)) {
                throw new ResourceOperationException('operation.completion_unproven', 'The admitted completion variant is unavailable.', 409);
            }
            $contract = ValidatedData::object($map[$variant]);
        }
        $proof = [];
        if (isset($contract['step'])) {
            $proof = ['step' => ValidatedData::string($contract['step']), 'transport' => ValidatedData::string($contract['transport']),
                'name' => ValidatedData::string($contract['name']), 'result' => ValidatedData::object($contract['result'])];
        }
        foreach (['children_succeeded', 'children_settled'] as $mode) {
            if (isset($contract[$mode])) {
                $proof[$mode] = ValidatedData::stringList($contract[$mode]);
            }
        }

        return $proof;
    }
}
