<?php

declare(strict_types=1);

namespace App\Domain\Routes;

use App\Domain\Nodes\NodeEnrollmentCandidate;
use App\Models\Node;
use Closure;
use Illuminate\Container\Attributes\Scoped;

/** Request-scoped prospective data. Stored Route ownership and actual Node identity stay authoritative. */
#[Scoped]
final class RouteRenderContext
{
    private NativeRouteNodeRender|NodeEnrollmentCandidate|null $candidate = null;

    /** @var ?array{node_id:int,settings:array<string,mixed>} */
    private ?array $preparedSettings = null;

    /** @template T
     * @param  array<string,mixed>  $settings
     * @param  Closure():T  $render
     * @return T
     */
    public function renderSettings(Node $node, array $settings, Closure $render): mixed
    {
        $previous = $this->preparedSettings;
        $this->preparedSettings = ['node_id' => $node->id, 'settings' => $settings];
        try {
            return $render();
        } finally {
            $this->preparedSettings = $previous;
        }
    }

    /** @return array<string,mixed>|null */
    public function settings(Node $node): ?array
    {
        return $this->preparedSettings !== null && $this->preparedSettings['node_id'] === $node->id
            ? $this->preparedSettings['settings'] : $node->settings;
    }

    public function dnsServerOverride(Node $node): ?string
    {
        $candidate = $this->enrollment();

        return $candidate?->nodeId === $node->id ? $candidate->dnsServerOverride : $node->dns_server_override;
    }

    /** @var ?array{leaves:list<array{node_id:int,scope:string}>,withdrawals:list<array{node_id:int,scope:string}>} */
    private ?array $roleCertificates = null;

    /** Admission-only readiness for the configuration that follows native certificate publication.
     * @template T
     *
     * @param  array{leaves:list<array{node_id:int,scope:string}>,withdrawals:list<array{node_id:int,scope:string}>}  $plan
     * @param  Closure():T  $render
     * @return T
     */
    public function renderRoleCertificates(array $plan, Closure $render): mixed
    {
        $previous = $this->roleCertificates;
        $this->roleCertificates = $plan;
        try {
            return $render();
        } finally {
            $this->roleCertificates = $previous;
        }
    }

    /** Null keeps the stored marker authoritative outside the captured role plan. */
    public function roleCertificatePublished(int $nodeId, string $site): ?bool
    {
        foreach (['withdrawals' => false, 'leaves' => true] as $entries => $published) {
            foreach ($this->roleCertificates[$entries] ?? [] as $leaf) {
                if ($leaf['node_id'] === $nodeId && $leaf['scope'] === 'role-'.$site) {
                    return $published;
                }
            }
        }

        return null;
    }

    /** The owning parent authenticates and selects its immutable admitted candidate before calling.
     * @template T
     *
     * @param  Closure():T  $render
     * @return T
     */
    public function render(NativeRouteNodeRender $candidate, Closure $render): mixed
    {
        Node::query()->findOrFail($candidate->nodeId);
        $previous = $this->candidate;
        $this->candidate = $candidate;
        try {
            return $render();
        } finally {
            $this->candidate = $previous;
        }
    }

    /** @template T
     * @param  Closure():T  $render
     * @return T
     */
    public function renderEnrollment(NodeEnrollmentCandidate $candidate, Closure $render): mixed
    {
        Node::query()->findOrFail($candidate->nodeId);
        $previous = $this->candidate;
        $this->candidate = $candidate;
        try {
            return $render();
        } finally {
            $this->candidate = $previous;
        }
    }

    /** Capture stored BEFORE values even when the caller is already rendering a prospective view.
     * @template T
     *
     * @param  Closure():T  $render
     * @return T
     */
    public function renderActual(Closure $render): mixed
    {
        $candidate = $this->candidate;
        $certificates = $this->roleCertificates;
        $settings = $this->preparedSettings;
        $this->candidate = null;
        $this->roleCertificates = null;
        $this->preparedSettings = null;
        try {
            return $render();
        } finally {
            $this->candidate = $candidate;
            $this->roleCertificates = $certificates;
            $this->preparedSettings = $settings;
        }
    }

    public function enrollment(): ?NodeEnrollmentCandidate
    {
        return $this->candidate instanceof NodeEnrollmentCandidate ? $this->candidate : null;
    }

    public function name(Node $node): string
    {
        $candidate = $this->enrollment();

        return $candidate?->nodeId === $node->id ? $candidate->name : $node->name;
    }

    public function user(Node $node): string
    {
        $candidate = $this->enrollment();

        return $candidate?->nodeId === $node->id ? $candidate->user : $node->user;
    }

    public function lanIp(Node $node): ?string
    {
        $candidate = $this->enrollment();

        return $candidate?->nodeId === $node->id ? $candidate->lanIp : $node->lan_ip;
    }

    public function clusterId(Node $node): ?int
    {
        $candidate = $this->enrollment();

        return $candidate?->nodeId === $node->id ? $candidate->clusterId : $node->cluster_id;
    }

    public function nodeId(): ?int
    {
        return $this->candidate?->nodeId;
    }

    public function wireguardIp(Node $node): ?string
    {
        return $this->candidate?->nodeId === $node->id ? $this->candidate->wireguardIp : $node->wireguard_ip;
    }

    public function tld(Node $node): ?string
    {
        return $this->candidate?->nodeId === $node->id ? $this->candidate->tld : $node->tld;
    }
}
