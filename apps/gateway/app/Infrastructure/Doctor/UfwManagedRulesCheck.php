<?php

declare(strict_types=1);

namespace App\Infrastructure\Doctor;

use App\Domain\Doctor\DoctorInspectionException;
use App\Infrastructure\Firewall\UfwManagedRule;
use App\Infrastructure\Firewall\UfwRuleOwnership;
use App\Infrastructure\Firewall\UfwStatusParser;
use App\Infrastructure\Processes\CommandResult;
use InvalidArgumentException;

/**
 * Compares `ufw status numbered` output with managed rules for a Doctor probe. An inactive firewall
 * never matches, and an unreadable status makes the inspection fail.
 */
final readonly class UfwManagedRulesCheck
{
    public function __construct(
        private UfwStatusParser $parser = new UfwStatusParser,
    ) {}

    /**
     * @param  list<UfwManagedRule>  $rules
     * @param  list<string>  $exclusiveFamilies  Families that must not contain unintended rules.
     */
    public function matches(CommandResult $result, array $rules, array $exclusiveFamilies = []): bool
    {
        if (! $result->succeeded() || $result->truncated) {
            throw new DoctorInspectionException;
        }

        if (preg_match('/\AStatus:\s+inactive\s*\z/i', $result->stdout) === 1) {
            return false;
        }

        if (preg_match('/\AStatus:\s+active\s*$/mi', $result->stdout) !== 1) {
            throw new DoctorInspectionException;
        }

        foreach ($exclusiveFamilies as $prefix) {
            try {
                $observedRules = $this->parser->familyShapes($result->stdout, $prefix, includeIpv6: true);
            } catch (InvalidArgumentException) {
                return false;
            }

            foreach ($observedRules as $observed) {
                if (! array_any($rules, static fn (UfwManagedRule $rule): bool => $rule->shape->matches($observed))) {
                    return false;
                }
            }
        }

        return array_all(
            $rules,
            fn (UfwManagedRule $rule): bool => $this->parser->ownership($result->stdout, $rule->shape) === UfwRuleOwnership::Exact,
        );
    }
}
