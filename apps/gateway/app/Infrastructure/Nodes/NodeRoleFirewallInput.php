<?php

declare(strict_types=1);

namespace App\Infrastructure\Nodes;

use App\Domain\Firewall\RouterLanIngressPolicy;
use App\Domain\Nodes\RoleName;
use App\Infrastructure\Firewall\NodeFirewallRuleCatalog;
use App\Infrastructure\Firewall\UfwManagedRule;
use App\Models\Node;

/** Pure firewall payloads shared by Node admission and bootstrap. */
final readonly class NodeRoleFirewallInput
{
    public function __construct(private NodeFirewallRuleCatalog $catalog = new NodeFirewallRuleCatalog) {}

    /** @return array<string, mixed> */
    public function converge(Node $node, RoleName $role): array
    {
        return $this->rules([$this->catalog->forNode($node)[1], ...$this->roleRules($node, $role)],
            [$this->catalog->forNode($node)[0], ...$this->catalog->retiredForRole($node, $role)]);
    }

    /** @return array<string, mixed> */
    public function remove(Node $node, RoleName $role): array
    {
        return $this->rules([], $this->roleRules($node, $role, true));
    }

    /** @return array<string, mixed> */
    public function publicSsh(Node $node, bool $enable = false): array
    {
        return $this->rules([$this->catalog->forNode($node)[0]], enable: $enable);
    }

    /** @return array<string, mixed> */
    public function trust(Node $node): array
    {
        return $this->rules([$this->catalog->forNode($node)[1]]);
    }

    /** @return array<string, mixed> */
    public function routerLan(Node $node, bool $remove = false): array
    {
        return ['desired' => array_map(static fn (UfwManagedRule $rule): array => $rule->shape->toAgentInput(),
            $remove ? [] : $this->catalog->routerLanIngress($node)), 'expand' => ! $remove, 'prune' => true];
    }

    /** @return list<UfwManagedRule> */
    private function roleRules(Node $node, RoleName $role, bool $owned = false): array
    {
        $baseline = array_map(static fn (UfwManagedRule $rule): string => $rule->shape->comment, $this->catalog->forNode($node));
        $policy = new RouterLanIngressPolicy;

        return array_values(array_filter($owned ? $this->catalog->ownedByRole($node, $role) : $this->catalog->forRole($node, $role),
            static fn (UfwManagedRule $rule): bool => ! in_array($rule->shape->comment, $baseline, true) && ! $policy->ownsComment($rule->shape->comment)));
    }

    /** @param list<UfwManagedRule> $desired
     * @param list<UfwManagedRule> $remove
     * @return array<string, mixed>
     */
    private function rules(array $desired, array $remove = [], bool $enable = false): array
    {
        $unique = static function (array $rules): array {
            $byComment = [];
            foreach ($rules as $rule) {
                $byComment[$rule->shape->comment] = $rule;
            }

            return array_values($byComment);
        };

        return ['desired' => array_map(static fn (UfwManagedRule $rule): array => ['shape' => $rule->shape->toAgentInput(), 'replace_port' => $rule->replacePort], $unique($desired)),
            'remove' => array_map(static fn (UfwManagedRule $rule): array => $rule->shape->toAgentInput(), $unique($remove)), 'enable' => $enable];
    }
}
