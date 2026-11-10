<?php

declare(strict_types=1);

namespace App\Actions\Fleet;

use App\Domain\Fleet\CliConvergePlan;
use App\Domain\Fleet\DesiredFleetState;
use App\Domain\Fleet\FleetReleaseLag;
use App\Domain\Fleet\FleetRolloutMembership;
use App\Domain\Fleet\FleetServingRelease;
use App\Domain\GatewayReleases\GatewayReleaseCommit;
use App\Models\GatewayRelease;

/** Reads desired intent and earlier rollout results. Scheduling and visits belong to Swamp. */
final readonly class FleetRolloutPlanAction
{
    public function __construct(private DesiredFleetState $desired, private FleetRolloutMembership $membership,
        private FleetReleaseLag $lag, private FleetServingRelease $serving) {}

    /**
     * The Nodes that need a visit, in rollout order: behind the desired state, and not visited in the last
     * {@see FleetReleaseLag::RetryAfterSeconds} seconds. A failed Node that waits holds back every later Node.
     *
     * @return array{status:string,commit?:string,targets?:list<int>,waiting_for_cli?:bool}
     */
    public function execute(): array
    {
        if (! config('fleet.rollout')) {
            return ['status' => 'disabled'];
        }
        $state = $this->desired->current();
        if ($state->commit === null) {
            return ['status' => 'commit_unknown'];
        }
        if ($this->serving->supersedes()) {
            return ['status' => 'superseded'];
        }
        $refusal = $this->gate($state->commit);
        if ($refusal !== null) {
            return ['status' => $refusal];
        }
        $members = $this->membership->members();
        $visits = $this->lag->visits($state->commit, array_map(static fn ($node): int => $node->id, $members));
        $retry = now()->subSeconds(FleetReleaseLag::RetryAfterSeconds);
        $targets = [];
        $waiting = false;
        foreach ($members as $node) {
            $visit = $visits[$node->id] ?? null;
            if ($this->lag->behind($node, $visit, $state) === null) {
                continue;
            }
            if (CliConvergePlan::required($node) && ! $state->cli->isAvailable()) {
                $waiting = true;

                continue;
            }
            if ($visit === null || $visit['finished_at']->lte($retry)) {
                $targets[] = $node->id;
            } elseif (in_array($visit['outcome'], ['failed', 'cancelled'], true)) {
                // A failed Node stays a barrier while it waits: no later Node goes before it converges.
                break;
            }
        }

        if ($targets === [] && $waiting) {
            return ['status' => 'waiting'];
        }

        return ['status' => 'ready', 'commit' => $state->commit, 'targets' => $targets, 'waiting_for_cli' => $waiting];
    }

    /**
     * Null when the commit may roll out. A Gateway in the release layout rolls out only a commit with a `verified`
     * release record whose verify step saw the Gateway serve that exact commit. An in-place Gateway has no release
     * records; its running commit is the desired state. An unreadable layout fails closed.
     */
    private function gate(string $commit): ?string
    {
        $verified = GatewayRelease::query()->where('sha', $commit)->where('outcome', 'verified')->get()
            ->contains(static fn (GatewayRelease $record): bool => self::servedExactly($record, $commit));

        return $verified ? null : match ($this->serving->read()['state']) {
            FleetServingRelease::InPlace => null,
            FleetServingRelease::Adopted => 'release_unverified',
            default => 'release_layout_unreadable',
        };
    }

    /** Whether the record's verify step saw the Gateway report the commit or its 12-digit release id. */
    private static function servedExactly(GatewayRelease $record, string $commit): bool
    {
        $verify = $record->phases['verify'] ?? null;
        $version = is_array($verify) && is_string($verify['version'] ?? null) ? strtolower(trim($verify['version'])) : '';

        return $version !== '' && ($version === strtolower($commit) || $version === GatewayReleaseCommit::id(strtolower($commit)));
    }
}
