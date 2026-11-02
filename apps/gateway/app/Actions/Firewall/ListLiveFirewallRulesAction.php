<?php

declare(strict_types=1);

namespace App\Actions\Firewall;

use App\Domain\Firewall\FirewallInspectionInput;
use App\Models\Node;

/** Live UFW facts come only from orbit-agent observations. Until the agent reports UFW, every desired rule is unverifiable. */
final readonly class ListLiveFirewallRulesAction
{
    public function __construct(private FirewallInspectionInput $desired) {}

    /** @return array{backend_status:string,observed_at:?string,live:list<array<string,mixed>>,missing:list<array<string,mixed>>} */
    public function execute(Node $node): array
    {
        $rules = $node->platform !== 'linux' ? [] : array_map(static fn (array $rule): array => [
            'name' => $rule['name'], 'comment' => $rule['shape']['comment'], 'action' => $rule['shape']['action'],
            'source' => $rule['shape']['source'], 'destination' => $rule['shape']['destination'], 'port' => $rule['shape']['port'],
            'protocol' => $rule['shape']['protocol'], 'interface' => $rule['shape']['in_interface'], 'family' => $rule['shape']['family'],
            'match' => 'unverifiable',
        ], $this->desired->expected($node));

        return ['backend_status' => 'unverifiable', 'observed_at' => null, 'live' => $rules, 'missing' => []];
    }
}
