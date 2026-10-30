<?php

declare(strict_types=1);

use App\Domain\Instances\Environment\InstanceWebRootsProjection;
use App\Domain\Instances\InstanceTransferDiscardProjection;

return [
    'instance.transfer_discard' => [
        'workflow' => 'operation-instance-lifecycle-direct',
        'writer_config' => 'swamp.direct_writers',
        'writer_state' => 'instance_lifecycle_writer',
        'projection' => InstanceTransferDiscardProjection::class,
    ],
    'instance.prepare_served_web_roots' => [
        'workflow' => 'operation-instance-web-roots',
        'writer_config' => 'swamp.direct_writers',
        'writer_state' => 'instance_writer',
        'projection' => InstanceWebRootsProjection::class,
    ],
];
