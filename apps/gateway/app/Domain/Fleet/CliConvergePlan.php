<?php

declare(strict_types=1);

namespace App\Domain\Fleet;

use App\Domain\Nodes\MachineArchitecture;
use App\Domain\Nodes\NodeEnrollmentCandidate;
use App\Domain\Nodes\RoleName;
use App\Domain\Routes\RouteRenderContext;
use App\Domain\Shared\LifecycleStatus;
use App\Infrastructure\Nodes\NodeCliFootprint;
use App\Models\Node;
use Throwable;

/** Gateway reads that pin the CLI child to one desired release and target. */
final readonly class CliConvergePlan
{
    public function __construct(private DesiredFleetState $desired, private FleetRolloutMembership $membership) {}

    public function exclusion(Node $node, ?NodeEnrollmentCandidate $candidate = null): ?string
    {
        $node->load('roles');
        $reason = $this->membership->exclusion($node, $this->candidate($node, $candidate));
        if ($reason === null || $reason === 'foreign_cli') {
            return null;
        }
        if ($reason === 'roleless' && $node->roles()->where('role', '!=', RoleName::Gateway->value)
            ->where('status', '!=', LifecycleStatus::Removing->value)->exists()) {
            return null;
        }

        return $reason;
    }

    /** @return array{user:string,release:?array{version:?string,url:string,sha256:string},profile:string,skip_reason:?string,plan_error:?string} */
    public function input(Node $node, ?NodeEnrollmentCandidate $candidate = null): array
    {
        $candidate = $this->candidate($node, $candidate);
        $input = ['user' => $candidate === null ? $node->user : $candidate->user, 'release' => null, 'profile' => '',
            'skip_reason' => $this->exclusion($node, $candidate), 'plan_error' => null];
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
        $architecture = $candidate === null ? $node->architecture
            : MachineArchitecture::normalize($candidate->architecture) ?? MachineArchitecture::Linux;
        $platform = NodeCliFootprint::platform((string) $architecture);
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

    private function candidate(Node $node, ?NodeEnrollmentCandidate $explicit): ?NodeEnrollmentCandidate
    {
        $scoped = app(RouteRenderContext::class)->enrollment();

        return $explicit ?? ($scoped?->nodeId === $node->id ? $scoped : null);
    }
}
