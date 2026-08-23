<?php

declare(strict_types=1);

namespace Orbit\Core\Firewall;

final class RetainedFirewallProofReceipt
{
    /**
     * @param  array{candidate: string, target: string, expected: string, observed: string, result: string, evidence: string}  $fields
     */
    public static function line(array $fields): string
    {
        return sprintf(
            '%s - candidate=%s; venue=retained-incus; environment=dev-fixture; target=%s; expected=%s; observed=%s; result=%s; evidence=`%s`',
            $fields['result'],
            $fields['candidate'],
            $fields['target'],
            $fields['expected'],
            $fields['observed'],
            $fields['result'],
            $fields['evidence'],
        );
    }
}
