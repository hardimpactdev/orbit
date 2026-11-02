<?php

declare(strict_types=1);

use App\Domain\SwampDatastore\SwampDatastoreWatch;

return [SwampDatastoreWatch::Workflow => ['workflow' => 'swamp-datastore-watch',
    'terminal_result' => ['job' => 'watch', 'step' => 'measure'], 'projection' => SwampDatastoreWatch::class]];
