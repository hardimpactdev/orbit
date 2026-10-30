<?php

declare(strict_types=1);

use App\Domain\Firewall\FirewallOperationCompletion;

return [
    ...array_fill_keys(['firewall.allow', 'firewall.deny'], ['executor' => 'swamp', 'result_fields' => ['backend', 'error_code']]),
    'firewall.remove' => [
        'executor' => 'swamp', 'completion' => FirewallOperationCompletion::class,
        'runs' => [[
            'transport' => 'agent', 'name' => 'firewall.rule', 'resumable' => true,
            'input_fields' => ['name', 'action', 'source', 'port', 'protocol'],
            'input_values' => ['operation' => 'remove'], 'node_field' => 'node_id',
        ]],
        'completion_contract' => [
            'step' => 'ufw-remove', 'transport' => 'agent', 'name' => 'firewall.rule', 'result' => ['backend' => 'absent'],
        ],
        'result_fields' => ['backend', 'error_code'],
    ],
];
