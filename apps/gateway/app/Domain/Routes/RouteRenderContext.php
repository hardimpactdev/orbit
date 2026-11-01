<?php

declare(strict_types=1);

namespace App\Domain\Routes;

use App\Domain\Nodes\NodeEnrollmentCandidate;
use App\Domain\Shared\ResourceOperationException;
use App\Models\Instance;
use App\Models\Node;
use Closure;
use Illuminate\Container\Attributes\Scoped;

/** Request-scoped prospective data. Stored Route ownership and actual Node identity stay authoritative. */
#[Scoped]
final class RouteRenderContext
{
    private NativeRouteNodeRender|NodeEnrollmentCandidate|null $candidate = null;

    private ?NativeRouteInstanceFacts $instanceFacts = null;

    /** @template T
     * @param  Closure():T  $render
     * @return T
     */
    public function renderInstanceFacts(NativeRouteInstanceFacts $facts, Closure $render): mixed
    {
        $previous = $this->instanceFacts;
        $this->instanceFacts = $facts;
        try {
            return $render();
        } finally {
            $this->instanceFacts = $previous;
        }
    }

    public function instanceFacts(Instance $instance): ?NativeRouteInstanceFacts
    {
        if ($this->instanceFacts?->instanceId !== $instance->id) {
            return null;
        }
        if ($this->instanceFacts->nodeId !== $instance->node_id) {
            throw new ResourceOperationException('route.source_facts_invalid', 'The prospective source facts belong to the admitted Instance placement.', 409);
        }

        return $this->instanceFacts;
    }

    public function phpVersion(Instance $instance): ?string
    {
        $facts = $this->instanceFacts($instance);

        return $facts->phpVersion ?? $instance->selected_php_version;
    }

    public function laravel(Instance $instance): ?bool
    {
        $facts = $this->instanceFacts($instance);

        return $facts->laravel ?? $instance->source_is_laravel;
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
        $facts = $this->instanceFacts;
        $this->candidate = null;
        $this->roleCertificates = null;
        $this->instanceFacts = null;
        try {
            return $render();
        } finally {
            $this->candidate = $candidate;
            $this->roleCertificates = $certificates;
            $this->instanceFacts = $facts;
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
