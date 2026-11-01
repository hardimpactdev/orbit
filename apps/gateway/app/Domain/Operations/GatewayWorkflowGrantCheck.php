<?php

declare(strict_types=1);

namespace App\Domain\Operations;

use LogicException;

/** Checks intended source policy only; never inspects or changes live grants. */
final readonly class GatewayWorkflowGrantCheck
{
    /**
     * @param  array<string,array{workflow:string,schedule_write?:bool,execution_copies?:list<array{vault:string,key_pattern:string}>}>  $definitions
     * @param  array<string,mixed>  $policy
     */
    public function execute(array $definitions, array $policy): int
    {
        if (count($policy) !== 4 || ($policy['version'] ?? null) !== 2
            || ($policy['principal'] ?? null) !== 'user:orbit-gateway'
            || ! is_array($policy['vaults'] ?? null) || ! array_is_list($policy['vaults'])
            || ! is_array($policy['workflows'] ?? null) || ! array_is_list($policy['workflows'])) {
            throw new LogicException('Gateway workflow grant intent needs version 2, principal user:orbit-gateway and workflow and vault lists.');
        }

        $scheduleWrites = [];
        foreach ($definitions as $definition) {
            if (($definition['schedule_write'] ?? false) === true) {
                $scheduleWrites[$definition['workflow']] = true;
            }
        }
        $grants = [];
        foreach ($policy['workflows'] as $entry) {
            if (! is_array($entry) || array_diff(array_keys($entry), ['workflow', 'actions', 'publishes_inputs']) !== []
                || (array_key_exists('publishes_inputs', $entry) && $entry['publishes_inputs'] !== true)
                || ! is_string($entry['workflow'] ?? null)
                || preg_match('/\A[a-z][a-z0-9_.-]*\z/D', $entry['workflow']) !== 1
                || ! is_array($entry['actions'] ?? null) || ! array_is_list($entry['actions'])) {
                throw new LogicException('Each exact Gateway workflow grant intent needs workflow, actions and an optional schedule producer marker.');
            }
            $actions = $entry['actions'];
            sort($actions);
            $expected = isset($scheduleWrites[$entry['workflow']]) ? ['read', 'run', 'write'] : ['read', 'run'];
            if ($actions !== $expected || (($entry['publishes_inputs'] ?? false) === true) !== isset($scheduleWrites[$entry['workflow']])) {
                throw new LogicException('Each exact workflow needs run and read, plus write only for its declared schedule input producer.');
            }
            if (isset($grants[$entry['workflow']])) {
                throw new LogicException("Duplicate Gateway workflow grant intent [{$entry['workflow']}].");
            }
            $grants[$entry['workflow']] = true;
        }

        $required = array_unique(array_column($definitions, 'workflow'));
        $missing = array_diff($required, array_keys($grants));
        if ($missing !== []) {
            throw new LogicException('Missing intended run and read grants for user:orbit-gateway workflows: '.implode(', ', $missing).'.');
        }
        $retired = array_diff(array_keys($grants), $required);
        if ($retired !== []) {
            throw new LogicException('Remove undeclared Gateway workflow grant intent: '.implode(', ', $retired).'.');
        }

        $requiredVaults = [];
        foreach ($definitions as $definition) {
            foreach ($definition['execution_copies'] ?? [] as $copy) {
                $requiredVaults[DirectOperation::executionCopySignature($copy)] = true;
            }
        }
        $vaults = [];
        foreach ($policy['vaults'] as $copy) {
            if (! is_array($copy) || count($copy) !== 3
                || ! in_array($copy['actions'] ?? null, [['write', 'read'], ['read', 'write']], true)) {
                throw new LogicException('Each vault intent needs vault, key_pattern and only write and read actions.');
            }
            unset($copy['actions']);
            $signature = DirectOperation::executionCopySignature($copy);
            if (isset($vaults[$signature])) {
                throw new LogicException('Duplicate execution copy vault intent.');
            }
            $vaults[$signature] = true;
        }
        if (array_diff_key($requiredVaults, $vaults) !== [] || array_diff_key($vaults, $requiredVaults) !== []) {
            throw new LogicException('Execution copy vault intent must match all current declarations exactly.');
        }

        return count($required);
    }
}
