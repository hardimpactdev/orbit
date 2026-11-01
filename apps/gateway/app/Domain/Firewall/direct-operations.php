<?php

declare(strict_types=1);

use App\Domain\Firewall\FirewallIntentProjection;

$intent = [
    'workflow' => 'operation-firewall-direct',
    'projection' => FirewallIntentProjection::class,
    'workflow_inputs' => true, 'terminal_result' => ['job' => 'firewall', 'step' => 'result'],
];

return [
    'firewall.allow' => $intent,
    'firewall.allowlist' => $intent,
    'firewall.deny' => $intent,
    'firewall.remove' => $intent,
];
