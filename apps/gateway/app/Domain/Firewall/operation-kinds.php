<?php

declare(strict_types=1);

use App\Domain\Firewall\FirewallOperationCompletion;

return (static function (): array {
    $kinds = [];
    foreach (['allow', 'deny', 'remove'] as $verb) {
        $kinds['firewall.'.$verb] = [
            'executor' => 'swamp',
            'completion' => FirewallOperationCompletion::class,
            'runs' => [[
                'transport' => 'agent', 'name' => 'firewall.rule',
                'input_fields' => ['name', 'action', 'source', 'port', 'protocol'],
                'input_values' => ['operation' => $verb === 'remove' ? 'remove' : 'converge'],
                'node_field' => 'node_id',
            ]],
            'completion_contract' => [
                'step' => $verb === 'remove' ? 'ufw-remove' : 'ufw-converge',
                'transport' => 'agent', 'name' => 'firewall.rule',
                'result' => ['backend' => $verb === 'remove' ? 'absent' : 'active'],
            ],
            'result_fields' => ['backend', 'error_code'],
        ];
    }

    return $kinds;
})();
