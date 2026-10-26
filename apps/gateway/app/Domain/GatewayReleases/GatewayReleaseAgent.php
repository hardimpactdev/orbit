<?php

declare(strict_types=1);

namespace App\Domain\GatewayReleases;

use App\Models\Node;

/** The agent build a prepared release pins, from its commit's published release ([Prepare a release](/reference/gateway-recovery#prepare-a-release)). */
interface GatewayReleaseAgent
{
    /**
     * Finds the commit's published release and verifies its agent for the Gateway before anything changes.
     *
     * @return array{sha: string, manifest: string} the full commit and the published manifest
     *
     * @throws GatewayReleaseException `gateway.release_agent_delivery_unavailable` while the commit has no release yet
     */
    public function fetch(string $revision, Node $gateway): array;

    /**
     * Puts the manifest into the release and installs its agent on the Gateway.
     *
     * @param  array{sha: string, manifest: string}  $delivery
     *
     * @throws GatewayReleaseException
     */
    public function install(array $delivery, GatewayReleaseLayout $layout, Node $gateway): void;
}
