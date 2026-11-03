<?php

declare(strict_types=1);

namespace App\Infrastructure\Fleet\Footprint;

use App\Domain\Fleet\NodeFootprintArtifact;
use App\Domain\Nodes\NodeNetworkConvergeInput;
use App\Domain\Nodes\RoleName;
use App\Domain\Shared\LifecycleStatus;
use App\Models\Node;
use App\Support\ValidatedData;

/** Desired network state has its own digest; live WireGuard drift is checked in the run. */
final readonly class NetworkFootprintArtifact implements NodeFootprintArtifact
{
    public function name(): string
    {
        return 'network';
    }

    public function applies(Node $node): bool
    {
        return $node->platform === 'linux' && $node->status === LifecycleStatus::Active
            && Node::query()->whereHas('roles', static fn ($query) => $query->where('role', RoleName::Vpn)->where('status', LifecycleStatus::Active))->exists();
    }

    public function digest(Node $node): string
    {
        $network = app(NodeNetworkConvergeInput::class)->converge($node);
        unset($network['observations'], $network['probe_ip'], $network['endpoint_host']);
        $apply = ValidatedData::object($network['apply']);
        unset($apply['ssh'], $apply['other_public_keys']);
        if (is_array($apply['hub'] ?? null)) {
            unset($apply['hub']['ssh']);
        }
        $network['apply'] = $apply;

        return hash('sha256', json_encode($network, JSON_THROW_ON_ERROR));
    }
}
