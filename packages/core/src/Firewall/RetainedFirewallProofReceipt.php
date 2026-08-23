<?php

declare(strict_types=1);

namespace Orbit\Core\Firewall;

final class RetainedFirewallProofReceipt
{
    public static function line(
        string $candidate,
        string $target,
        string $expected,
        string $observed,
        string $result,
        string $evidence,
    ): string {
        return sprintf(
            '%s - candidate=%s; venue=retained-incus; environment=dev-fixture; target=%s; expected=%s; observed=%s; result=%s; evidence=`%s`',
            $result,
            $candidate,
            $target,
            $expected,
            $observed,
            $result,
            $evidence,
        );
    }
}
