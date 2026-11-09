<?php

declare(strict_types=1);

/*
 * The fleet rollout of ADR 0202 ([Fleet rollout](/reference/gateway-recovery#fleet-rollout)).
 */
return [
    // Whether orbit-fleet-converge.service rolls out and catches up. Off until an operator turns it on, so a
    // Gateway release that adds the units does not reach the fleet before its Nodes are ready.
    'rollout' => filter_var(env('ORBIT_FLEET_ROLLOUT', false), FILTER_VALIDATE_BOOL),

    // The nightly drained reboot of the managed Linux Nodes (ADR 0253, /reference/gateway-recovery#fleet-reboot). On by default:
    // a Node that runs agent containers must boot into its kernel fixes without manual work.
    'reboot' => filter_var(env('ORBIT_FLEET_REBOOT', true), FILTER_VALIDATE_BOOL),

    // How long a pass waits for a Node's work before it reboots it (decided 2026-11-09). Work that is not safe to cut defers
    // the Node instead.
    'reboot_drain_seconds' => 900,

    // Test-only: a local CLI release manifest that replaces Git history and GitHub on a disposable topology
    // (App\Infrastructure\Fleet\StaticCliRelease). Never set it on a real Gateway.
    'static_cli_release' => env('ORBIT_CLI_RELEASE_STATIC_MANIFEST'),

    // Node names, comma-separated, that the rollout visits first and in this order. Other Nodes follow in the
    // default order. It never adds or removes a Node.
    'order' => array_values(array_filter(array_map(
        trim(...),
        explode(',', (string) env('ORBIT_FLEET_ROLLOUT_ORDER', '')),
    ), static fn (string $name): bool => $name !== '')),
];
