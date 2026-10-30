<?php

declare(strict_types=1);

use App\Domain\Instances\InstanceRenameProjection;

return [
    'instance.rename' => [
        'workflow' => 'operation-instance-lifecycle-direct',
        'writer_config' => 'swamp.direct_writers',
        'writer_state' => 'instance_lifecycle_writer',
        'projection' => InstanceRenameProjection::class,
    ],
    'instance.prepare_served_web_roots' => [
        'workflow' => 'operation-instance-web-roots',
        'writer_config' => 'swamp.instance_writers',
        'writer_state' => 'instance_writer',
        'token_config' => 'swamp.instance_run_token',
    ],
];
