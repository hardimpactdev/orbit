<?php

declare(strict_types=1);

use App\Domain\SwampCredentials\SwampCredentialAlerts;

return [SwampCredentialAlerts::Workflow => ['workflow' => 'swamp-credential-monitor',
    'terminal_result' => ['job' => 'monitor', 'step' => 'renew'], 'projection' => SwampCredentialAlerts::class]];
