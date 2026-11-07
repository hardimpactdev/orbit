<?php

declare(strict_types=1);

use App\Domain\Firewall\FirewallIntentProjection;

$intent = [
    'workflow' => 'operation-firewall-ansible',
    'projection' => FirewallIntentProjection::class,
    'workflow_inputs' => true, 'terminal_result' => ['job' => 'firewall', 'step' => 'Check the rules'],
];

return [
    'firewall.allow' => $intent,
    'firewall.deny' => $intent,
    'firewall.remove' => $intent,
];
