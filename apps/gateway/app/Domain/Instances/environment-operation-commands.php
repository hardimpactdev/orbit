<?php

declare(strict_types=1);

use App\Domain\Instances\Environment\EnvironmentSyncPrepareCommand;
use App\Domain\Instances\Environment\EnvironmentSyncReceiptCommand;

return ['instance.environment-prepare' => EnvironmentSyncPrepareCommand::class,
    'instance.environment-receipt' => EnvironmentSyncReceiptCommand::class];
