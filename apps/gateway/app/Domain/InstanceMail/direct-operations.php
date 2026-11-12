<?php

declare(strict_types=1);

use App\Domain\Instances\Environment\DevelopmentMailUpdate;

return [
    'instance.mail_update' => ['workflow' => 'operation-instance-mail-update', 'workflow_inputs' => true,
        'projection' => DevelopmentMailUpdate::class,
        'terminal_result' => ['job' => 'mail', 'step' => 'result', 'data' => 'instance-mail-result']],
];
