<?php

declare(strict_types=1);

use App\Domain\Instances\Environment\DevelopmentMailUpdate;

return [
    'instance.mail_update' => ['workflow' => 'operation-instance-mail-update', 'workflow_inputs' => true,
        'projection' => DevelopmentMailUpdate::class,
        'terminal_result' => ['job' => 'mail', 'step' => 'result'],
        'execution_copies' => [['vault' => 'orbit-control-inputs', 'key_pattern' => '^instance-mail-resolver-[1-9][0-9]*$']]],
];
