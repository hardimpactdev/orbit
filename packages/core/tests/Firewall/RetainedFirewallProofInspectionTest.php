<?php

declare(strict_types=1);

use Orbit\Core\Firewall\RetainedFirewallProofInspection;
use Orbit\Core\Firewall\RetainedFirewallProofScenario;

it('selects only the managed comment and preserves an unrelated same-port allow', function (): void {
    $inspection = RetainedFirewallProofInspection::fromUfwStatus(<<<'UFW'
        Status: active

             To                         Action      From
             --                         ------      ----
        [ 1] 8080/tcp                   ALLOW IN    192.168.1.0/24             # orbit:private-api
        [ 2] 8080/tcp                   ALLOW IN    Anywhere                   # protected unrelated rule
        [ 3] 8080/tcp                   DENY IN     Anywhere
        UFW);

    expect($inspection->managedComments(RetainedFirewallProofScenario::PORT))
        ->toBe([RetainedFirewallProofScenario::MANAGED_IDENTITY])
        ->and($inspection->hasComment(RetainedFirewallProofScenario::PROTECTED_COMMENT))
        ->toBeTrue()
        ->and($inspection->managedAllowPrecedesBroadDeny())
        ->toBeTrue();
});

it('fails when a managed allow is missing or follows the broad deny', function (): void {
    $inspection = RetainedFirewallProofInspection::fromUfwStatus(<<<'UFW'
        Status: active

             To                         Action      From
             --                         ------      ----
        [ 1] 8080/tcp                   DENY IN     Anywhere
        [ 2] 8080/tcp                   ALLOW IN    Anywhere                   # protected unrelated rule
        UFW);

    expect($inspection->hasComment(RetainedFirewallProofScenario::MANAGED_IDENTITY))
        ->toBeFalse()
        ->and($inspection->managedAllowPrecedesBroadDeny())
        ->toBeFalse()
        ->and($inspection->hasComment(RetainedFirewallProofScenario::PROTECTED_COMMENT))
        ->toBeTrue();
});
