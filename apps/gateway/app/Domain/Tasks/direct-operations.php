<?php

declare(strict_types=1);

use App\Domain\Tasks\TaskParkProjection;

return ['tasks.park' => [
    'workflow' => 'operation-task-park',
    'writer_config' => 'swamp.direct_writers',
    'writer_state' => 'task_park_writer',
    'projection' => TaskParkProjection::class,
    'private_material' => ['upcloud_token' => 'upcloud.api'],
]];
