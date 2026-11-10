<?php

declare(strict_types=1);

namespace App\Infrastructure\Shared;

/**
 * The one rule for every Orbit unit with a long stop drain (ADR 0253, docs/reference/node-provisioning.md#shutdown-never-waits):
 * a normal stop or restart drains as the unit configures, but a system shutdown never waits for it. systemd runs `ExecStop`
 * before it sends the kill signal, so while `systemctl is-system-running` reports `stopping`, the privileged guard kills the
 * whole unit at once, itself included, which `-` accepts. Otherwise it exits at once and the unit's own drain applies.
 * The planned reboot drains before it reboots, while the network is up; WireGuard and SSH stop during a shutdown, so the
 * work a drain would wait for cannot finish there. Each unit keeps its own `KillMode`, `SendSIGKILL` and `TimeoutStopSec`.
 */
final class ShutdownFastStop
{
    public const string Directive = 'ExecStop=-+/bin/sh -c \'[ "$$(/usr/bin/systemctl is-system-running)" != stopping ] || exec /usr/bin/systemctl kill --kill-whom=all --signal=SIGKILL %n\'';
}
