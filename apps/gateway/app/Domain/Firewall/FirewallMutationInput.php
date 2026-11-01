<?php

declare(strict_types=1);

namespace App\Domain\Firewall;

use App\Infrastructure\Swamp\SwampSshExecutionInput;
use App\Models\FirewallRule;

/** Capture complete public intent before the native run starts. */
final readonly class FirewallMutationInput
{
    public function __construct(private SwampSshExecutionInput $ssh) {}

    /** @return array<string,mixed> */
    public function capture(FirewallRule $rule, bool $removing = false): array
    {
        $rule->loadMissing('node');

        $ssh = $this->ssh->forNode($rule->node);
        $survivors = FirewallRule::query()->where('node_id', $rule->node_id)
            ->whereKeyNot($rule->id)->orderBy('id')->get();
        $ports = array_map(intval(...), explode(':', $rule->port));
        $tuples = [];
        foreach (range($ports[0], $ports[1] ?? $ports[0]) as $port) {
            $retained = $survivors->contains(fn (FirewallRule $other): bool => $other->action === $rule->action && $other->source === $rule->source
                && $other->protocol === $rule->protocol && FirewallPort::contains($other->port, $port));
            $tuples[] = [
                'port' => $port,
                'ensure' => $removing && ! $retained ? 'absent' : 'present',
            ];
        }

        return ['ssh' => $ssh,
            'rule' => $this->intent($rule),
            'survivors' => $survivors->map(fn (FirewallRule $survivor): array => $this->intent($survivor))->all(),
            'tuples' => $tuples,
            'legacy_remove' => ['/usr/sbin/ufw', '--force', 'delete', $rule->action->value, 'in',
                'from', $rule->source, 'to', 'any', 'port', $rule->port, 'proto', $rule->protocol,
                'comment', "orbit:node:{$rule->node_id}:firewall:{$rule->name}"],
            'ensure' => $removing ? 'absent' : 'present'];
    }

    /** @return array{node_id:int,name:string,action:string,source:string,port:string,protocol:string} */
    private function intent(FirewallRule $rule): array
    {
        return ['node_id' => $rule->node_id, 'name' => $rule->name, 'action' => $rule->action->value,
            'source' => $rule->source, 'port' => $rule->port, 'protocol' => $rule->protocol];
    }
}
