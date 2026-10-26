<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Domain\GatewayReleases\GatewayReleaseAgent;
use App\Domain\GatewayReleases\GatewayReleaseCommit;
use App\Domain\GatewayReleases\GatewayReleaseException;
use App\Domain\GatewayReleases\GatewayReleaseLayout;
use App\Models\Node;
use Closure;

/** A release agent whose published releases are the commits a test names; it records each fetch and install. */
final class RecordingReleaseAgent implements GatewayReleaseAgent
{
    /** @var list<string> */
    public array $calls = [];

    /** @param Closure(string): ?string $published the full commit of the published release a revision names, or null */
    public function __construct(private readonly Closure $published) {}

    public function fetch(string $revision, Node $gateway): array
    {
        $revision = GatewayReleaseCommit::parse($revision);
        $sha = ($this->published)($revision)
            ?? throw new GatewayReleaseException('agent-delivery', 'gateway.release_agent_delivery_unavailable', "No published release holds commit [{$revision}] yet.", 503);
        $this->calls[] = 'fetch:'.$sha;

        return ['sha' => $sha, 'manifest' => '{}'];
    }

    public function install(array $delivery, GatewayReleaseLayout $layout, Node $gateway): void
    {
        $this->calls[] = 'install:'.$delivery['sha'];
    }
}
