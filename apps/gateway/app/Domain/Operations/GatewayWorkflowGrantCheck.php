<?php

declare(strict_types=1);

namespace App\Domain\Operations;

use LogicException;

/** Checks intended source policy only; never inspects or changes live grants. */
final readonly class GatewayWorkflowGrantCheck
{
    /**
     * @param  array<string,array{workflow:string}>  $definitions
     * @param  array<string,mixed>  $policy
     */
    public function execute(array $definitions, array $policy): int
    {
        if (count($policy) !== 3 || ($policy['version'] ?? null) !== 1
            || ($policy['principal'] ?? null) !== 'user:orbit-gateway'
            || ! is_array($policy['workflows'] ?? null) || ! array_is_list($policy['workflows'])) {
            throw new LogicException('Gateway workflow grant intent needs version 1, principal user:orbit-gateway and a workflow list.');
        }

        $grants = [];
        foreach ($policy['workflows'] as $entry) {
            if (! is_array($entry) || count($entry) !== 2 || ! is_string($entry['workflow'] ?? null)
                || preg_match('/\A[a-z][a-z0-9_.-]*\z/D', $entry['workflow']) !== 1
                || ! in_array($entry['actions'] ?? null, [['run', 'read'], ['read', 'run']], true)) {
                throw new LogicException('Each exact Gateway workflow grant intent needs only workflow and both run and read actions.');
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

        return count($required);
    }
}
