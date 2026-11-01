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
        // One `*` grant gives every workflow run and read; only schedule input producers need an exact write grant.
        $wildcard = false;
        $writes = [];
        foreach ($policy['workflows'] as $entry) {
            if (! is_array($entry) || array_diff(array_keys($entry), ['workflow', 'actions', 'publishes_inputs']) !== []
                || (array_key_exists('publishes_inputs', $entry) && $entry['publishes_inputs'] !== true)
                || ! is_string($entry['workflow'] ?? null)
                || preg_match('/\A(?:\*|[a-z][a-z0-9_.-]*)\z/D', $entry['workflow']) !== 1
                || ! is_array($entry['actions'] ?? null) || ! array_is_list($entry['actions'])) {
                throw new LogicException('Each Gateway workflow grant intent needs workflow, actions and an optional schedule producer marker.');
            }
            $actions = $entry['actions'];
            sort($actions);
            if ($entry['workflow'] === '*') {
                if ($wildcard || $actions !== ['read', 'run'] || array_key_exists('publishes_inputs', $entry)) {
                    throw new LogicException('The Gateway needs exactly one run and read grant on every workflow.');
                }
                $wildcard = true;

                continue;
            }
            if ($actions !== ['read', 'run', 'write'] || ($entry['publishes_inputs'] ?? false) !== true || ! isset($scheduleWrites[$entry['workflow']])) {
                throw new LogicException("Only a declared schedule input producer needs its own grant, with run, read and write [{$entry['workflow']}].");
            }
            if (isset($writes[$entry['workflow']])) {
                throw new LogicException("Duplicate Gateway workflow grant intent [{$entry['workflow']}].");
            }
            $writes[$entry['workflow']] = true;
        }
        if (! $wildcard) {
            throw new LogicException('Missing the run and read grant on every workflow (`*`) for user:orbit-gateway.');
        }
        $missing = array_diff(array_keys($scheduleWrites), array_keys($writes));
        if ($missing !== []) {
            throw new LogicException('Missing intended write grants for user:orbit-gateway schedule producers: '.implode(', ', $missing).'.');
        }
        $required = array_unique(array_column($definitions, 'workflow'));

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
