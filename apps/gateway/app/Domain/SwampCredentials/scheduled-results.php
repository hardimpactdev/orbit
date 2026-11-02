<?php

declare(strict_types=1);

use App\Domain\SwampCredentials\SwampCredentialAlerts;

return [SwampCredentialAlerts::Workflow => ['workflow' => 'swamp-credential-monitor',
    'terminal_result' => ['job' => 'monitor', 'step' => 'check', 'native' => true,
        'captures' => ['alerts' => ['job' => 'monitor', 'step' => 'check', 'field' => 'alerts']]],
    'projection' => SwampCredentialAlerts::class]];
