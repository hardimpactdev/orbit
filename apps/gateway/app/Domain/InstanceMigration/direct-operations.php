<?php

declare(strict_types=1);

use App\Domain\InstanceMigration\InstanceMigrateProjection;

// Temporary: the kind goes when the last checkout Instance has moved into a container (docs/reference/old-paths.md).
return [
    'instance.migrate' => ['workflow' => 'operation-instance-migrate', 'workflow_inputs' => true,
        'projection' => InstanceMigrateProjection::class,
        // `finish` reports the failed step, the commit and whether a failed move restored; the check after it says the container is gone.
        'terminal_result' => ['job' => 'migrate', 'step' => 'finish'],
        'execution_copies' => [['vault' => 'orbit-control-inputs', 'key_pattern' => '^instance-migrate-model-[1-9][0-9]*$']]],
];
