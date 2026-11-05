<?php

declare(strict_types=1);

namespace App\Domain\Fleet;

use App\Domain\Releases\ReleaseAlert;
use App\Domain\Releases\ReleaseAlertKind;
use App\Domain\Releases\ReleaseAlertNotifier;
use App\Domain\Releases\ReleaseAlertSubject;
use App\Domain\Releases\ReleaseIncident;
use App\Models\Node;
use App\Models\Operation;
use Illuminate\Support\Facades\Config;
use InvalidArgumentException;

/**
 * Raises the `rollout_halted` release alert for the Node that stopped a fleet rollout run: an Activity entry,
 * a filed problem, and the signed webhook ([Release alerts](/reference/gateway-recovery#release-alerts)).
 * The incident is the Node, the commit and the error code, so a retry of the same failure raises no new alert.
 * It runs after the result is written, outside a transaction.
 */
final readonly class FleetRolloutAlerts
{
    public const string Target = 'fleet';

    public function __construct(private ReleaseAlertNotifier $notifier, private FleetReleaseLag $lag) {}

    /** @param array{node_id: int, step: string, error_code: ?string} $node */
    public function failed(Operation $operation, array $node): void
    {
        $commit = $operation->input['commit'] ?? null;
        $code = $node['error_code'] ?? 'fleet.node_failed';
        $previous = is_string($commit) ? ($this->lag->visits($commit, [$node['node_id']], before: $operation->id)[$node['node_id']] ?? null) : null;

        // A retry that fails the same way already has its alert.
        if ($previous !== null && $previous['outcome'] === 'failed' && $previous['error_code'] === $node['error_code']) {
            return;
        }

        $name = Node::query()->whereKey($node['node_id'])->value('name');

        try {
            $this->notifier->alert(new ReleaseAlert(
                ReleaseAlertKind::RolloutHalted,
                new ReleaseAlertSubject(self::Target, self::repository(), is_string($commit) ? $commit : '', 'operation-'.$operation->id),
                sprintf(
                    'Fleet rollout Operation %d stopped at node %s at step %s: %s',
                    $operation->id,
                    is_string($name) ? $name : (string) $node['node_id'],
                    $node['step'],
                    $code,
                ),
                new ReleaseIncident('fleet-node:'.$node['node_id'], is_string($commit) ? $commit : 'unknown', $code),
            ));
        } catch (InvalidArgumentException $exception) {
            report($exception);
        }
    }

    /** The GitHub `owner/name` of the Orbit repository, from the CLI release repository URL. */
    public static function repository(): string
    {
        $configured = Config::get('orbit.cli_releases.repository');
        $path = is_string($configured) ? trim((string) parse_url($configured, PHP_URL_PATH), '/') : '';

        return preg_match('#\A[A-Za-z0-9-]+/[A-Za-z0-9._-]+\z#', $path) === 1 ? $path : 'hardimpactdev/orbit';
    }
}
