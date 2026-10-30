<?php

declare(strict_types=1);

use App\Domain\Firewall\FirewallIntentProjection;

$intent = [
    'workflow' => 'operation-firewall-direct',
    'writer_config' => 'swamp.direct_writers',
    'writer_state' => 'firewall_writer',
    'projection' => FirewallIntentProjection::class,
    'writer_scope' => ['node_id' => 209, 'parent_operation_id' => null],
];

return [
    'firewall.allow' => $intent,
    'firewall.deny' => $intent,
    'firewall.remove' => $intent,
];
