<?php

declare(strict_types=1);

namespace App\Domain\GatewayReleases;

use App\Models\Node;

/** The agent build a prepared release pins, from the frozen published delivery ([Prepare a release](/reference/gateway-recovery#prepare-a-release)). */
interface GatewayReleaseAgent
{
    /**
     * Resolves the Gateway commit and verifies the frozen agent before anything changes.
     *
     * @return array{sha: string, manifest: string} the full commit and the published manifest
     *
     * @throws GatewayReleaseException `gateway.release_agent_delivery_unavailable` when the frozen published assets are unreachable, and
     *                                 `gateway.release_provenance_invalid` when its release has no valid provenance
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
