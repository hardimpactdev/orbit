<?php

declare(strict_types=1);

namespace App\Domain\Fleet;

use App\Domain\Nodes\RoleName;
use App\Domain\Shared\LifecycleStatus;
use App\Infrastructure\Nodes\NodeCliFootprint;
use App\Infrastructure\Swamp\SwampSshExecutionInput;
use App\Models\Node;
use Throwable;

/**
 * Gateway reads that pin the CLI child to one desired release and target.
 *
 * @phpstan-type CliInput array{user:string,release:?array{version:?string,url:string,sha256:string},profile:string,skip_reason:?string,plan_error:?string}
 */
final readonly class CliConvergePlan
{
    public function __construct(private DesiredFleetState $desired, private FleetRolloutMembership $membership) {}

    /** Host CI proof runners invoke the packaged CLI; other managed workloads have no caller. */
    public static function required(Node $node): bool
    {
        return $node->roles()->where('role', RoleName::CiRunner)
            ->where('status', '!=', LifecycleStatus::Removing)->exists();
    }

    public function exclusion(Node $node): ?string
    {
        $node->load('roles');
        $reason = $this->membership->exclusion($node);
        if ($reason === null) {
            return self::required($node) ? null : 'cli_not_required';
        }
        if ($reason === 'roleless' && $node->roles()->where('role', '!=', RoleName::Gateway->value)
            ->where('status', '!=', LifecycleStatus::Removing->value)->exists()) {
            return self::required($node) ? null : 'cli_not_required';
        }

        return $reason;
    }

    /** @return CliInput */
    public function input(Node $node): array
    {
        return $this->capture($node, $this->exclusion($node));
    }

    /**
     * An individually admitted Node is not limited to the automatic fleet rollout set.
     *
     * @return CliInput|null
     */
    public function nodeInput(Node $node): ?array
    {
        return self::required($node) ? $this->capture($node, null) : null;
    }

    /** @return CliInput */
    private function capture(Node $node, ?string $skipReason): array
    {
        $input = ['user' => $node->user, 'release' => null, 'profile' => '', 'skip_reason' => $skipReason, 'plan_error' => null];
        if ($input['skip_reason'] !== null) {
            return $input;
        }
        try {
            $release = $this->desired->current()->cli;
        } catch (Throwable) {
            return [...$input, 'plan_error' => 'fleet.cli_plan_failed'];
        }
        if (! $release->isAvailable()) {
            return [...$input, 'skip_reason' => $release->reason->value ?? 'release_unavailable'];
        }
        $platform = NodeCliFootprint::platform((string) $node->architecture);
        $asset = array_find($release->assets, static fn ($asset): bool => $asset->platform === $platform);
        if ($asset === null) {
            return [...$input, 'plan_error' => 'cli.architecture_unsupported'];
        }
        $gateway = Node::query()->where('status', LifecycleStatus::Active)
            ->whereHas('roles', static fn ($query) => $query->where('role', RoleName::Gateway)->where('status', LifecycleStatus::Active))
            ->value('wireguard_ip');
        if (! is_string($gateway) || filter_var($gateway, FILTER_VALIDATE_IP) === false) {
            return [...$input, 'plan_error' => 'cli.configuration_failed'];
        }

        return [...$input, 'release' => ['version' => $release->version, 'url' => $asset->url, 'sha256' => $asset->sha256],
            'profile' => NodeCliFootprint::configuration($gateway)];
    }

    /**
     * The fleet row: the plan plus the SSH target the native CLI child reaches when the Node installs.
     *
     * @return array{user:string,release:?array{version:?string,url:string,sha256:string},profile:string,skip_reason:?string,plan_error:?string,ssh:?array<string,mixed>}
     */
    public function fleetInput(Node $node, bool $observer = false): array
    {
        $input = $this->input($node);

        return [...$input, 'ssh' => ($observer || ($input['skip_reason'] === null && $input['plan_error'] === null))
            ? app(SwampSshExecutionInput::class)->forNode($node) : null];
    }
}
