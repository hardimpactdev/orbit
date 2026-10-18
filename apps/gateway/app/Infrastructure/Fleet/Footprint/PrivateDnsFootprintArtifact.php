<?php

declare(strict_types=1);

namespace App\Infrastructure\Fleet\Footprint;

use App\Domain\AppDev\PrivateDnsManager;
use App\Domain\Fleet\NodeFootprintArtifact;
use App\Domain\Nodes\RoleName;
use App\Domain\Routes\ContributedPrivateHostnames;
use App\Domain\Shared\LifecycleStatus;
use App\Infrastructure\AppDev\PrivateDnsListenerRelease;
use App\Models\Node;
use App\Models\NodeRole;
use Illuminate\Container\Attributes\Tag;

/**
 * The private-DNS listener release, its units, the dnsmasq records, and the catalog on the `vpn` Node,
 * when that Node is not the Gateway's. The publication compares every file with the live copy and
 * restarts the listener or dnsmasq only for a change.
 */
final readonly class PrivateDnsFootprintArtifact implements NodeFootprintArtifact
{
    /** @param iterable<ContributedPrivateHostnames> $contributions */
    public function __construct(private PrivateDnsManager $dns,
        #[Tag(ContributedPrivateHostnames::class)] private iterable $contributions,
    ) {}

    public function name(): string
    {
        return 'private-dns';
    }

    public function applies(Node $node): bool
    {
        $holds = static fn (Node $candidate, RoleName $role): bool => $candidate->roles->contains(
            static fn (NodeRole $assignment): bool => $assignment->role === $role && $assignment->status === LifecycleStatus::Active,
        );

        return $holds($node, RoleName::Vpn) && ! $holds($node, RoleName::Gateway);
    }

    /**
     * The listener release and the publication code, not the records: a Route or Node change publishes its
     * own records and never makes the `vpn` Node drift.
     */
    public function digest(Node $node): string
    {
        return hash('sha256', PrivateDnsListenerRelease::fromGateway()->id()."\n".SourceDigest::of($this->inputs()));
    }

    /**
     * The publication code: the dnsmasq manager, the records renderer, the listener units, and the record sources
     * that extensions contribute with the classes they use, such as the state that decides a record.
     *
     * @return list<string> Paths relative to the Gateway's base path.
     */
    public function inputs(): array
    {
        return [
            'app/Infrastructure/AppDev/DnsmasqPrivateDnsManager.php',
            'app/Infrastructure/AppDev/DevelopmentDnsConfigRenderer.php',
            'app/Infrastructure/AppDev/PrivateDnsListenerUnitRenderer.php',
            ...SourceClosure::of([], array_map(
                static fn (ContributedPrivateHostnames $records): string => $records::class,
                array_values([...$this->contributions]),
            )),
        ];
    }

    public function apply(Node $node): ?bool
    {
        $this->dns->converge();

        return null;
    }
}
