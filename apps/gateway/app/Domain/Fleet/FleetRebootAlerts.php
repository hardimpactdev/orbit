<?php

declare(strict_types=1);

namespace App\Domain\Fleet;

use App\Domain\GatewayReleases\GatewayVersion;
use App\Domain\Releases\ReleaseAlert;
use App\Domain\Releases\ReleaseAlertKind;
use App\Domain\Releases\ReleaseAlertNotifier;
use App\Domain\Releases\ReleaseAlertSubject;
use App\Domain\Releases\ReleaseIncident;
use App\Models\Operation;
use App\Support\ValidatedData;
use Carbon\CarbonImmutable;
use InvalidArgumentException;
use Throwable;

/**
 * The alerts of the fleet reboot pass (ADR 0251, [Release alerts](/reference/gateway-recovery#release-alerts)): an Activity
 * entry, a filed problem and the signed webhook. Each incident names the Node and what went wrong, so a repeat of the same
 * fault raises no new alert. They run after the result is written, outside a transaction.
 */
final readonly class FleetRebootAlerts
{
    public const string Target = 'fleet-reboot';

    /** A Node due for longer than this is overdue (decided 2026-11-09). */
    public const int OverdueDays = 3;

    public function __construct(private ReleaseAlertNotifier $notifier) {}

    /** @param array<string, mixed> $node The failed Node's record. */
    public function halted(Operation $operation, array $node): void
    {
        $code = is_string($node['error_code'] ?? null) ? $node['error_code'] : 'fleet.reboot_failed';
        $boot = is_string($node['boot_id_before'] ?? null) && $node['boot_id_before'] !== '' ? $node['boot_id_before'] : 'unknown';
        $this->raise(ReleaseAlertKind::RebootHalted, sprintf('Fleet reboot Operation %d stopped at node %s at step %s: %s', $operation->id,
            self::name($node), is_string($node['step'] ?? null) ? $node['step'] : 'unknown', $code),
            new ReleaseIncident('fleet-node:'.ValidatedData::integer($node['node_id']), substr($boot, 0, 100), $code), 'operation-'.$operation->id);
    }

    /** @param array<string, mixed> $node A Node's record; only one that is still due after three days raises. */
    public function overdue(array $node): void
    {
        if (! in_array($node['outcome'] ?? null, ['deferred', 'failed', 'skipped'], true) || ! is_string($node['due_since'] ?? null)) {
            return;
        }
        try {
            $since = CarbonImmutable::parse($node['due_since']);
        } catch (Throwable) {
            return;
        }
        if ($since->greaterThan(CarbonImmutable::now()->subDays(self::OverdueDays))) {
            return;
        }
        $this->raise(ReleaseAlertKind::RebootOverdue, sprintf('Node %s has needed a reboot since %s; the last pass ended it %s%s.', self::name($node),
            $since->toIso8601String(), $node['outcome'], is_string($node['reason'] ?? null) ? ' ('.$node['reason'].')' : ''),
            new ReleaseIncident('fleet-node:'.ValidatedData::integer($node['node_id']), 'due-'.$since->getTimestamp(), 'fleet.reboot_overdue'));
    }

    /** Node 209 scheduled its own reboot and has not reported a new boot since the pass ended. */
    public function unconfirmed(Operation $operation, int $nodeId, string $name): void
    {
        $this->raise(ReleaseAlertKind::RebootUnconfirmed, sprintf('Node %s scheduled its reboot in fleet reboot Operation %d and has not reported a new boot.',
            $name, $operation->id), new ReleaseIncident('fleet-node:'.$nodeId, 'operation-'.$operation->id, 'fleet.reboot_unconfirmed'), 'operation-'.$operation->id);
    }

    private function raise(ReleaseAlertKind $kind, string $summary, ReleaseIncident $incident, ?string $release = null): void
    {
        $version = GatewayVersion::current();
        try {
            $this->notifier->alert(new ReleaseAlert($kind, new ReleaseAlertSubject(self::Target, FleetRolloutAlerts::repository(),
                preg_match('/\A[0-9a-f]{40}\z/', $version) === 1 ? $version : str_repeat('0', 40), $release), $summary, $incident));
        } catch (InvalidArgumentException $exception) {
            report($exception);
        }
    }

    /** @param array<string, mixed> $node */
    private static function name(array $node): string
    {
        return is_string($node['name'] ?? null) ? $node['name'] : (string) ValidatedData::integer($node['node_id']);
    }
}
