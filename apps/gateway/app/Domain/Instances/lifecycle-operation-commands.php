<?php

declare(strict_types=1);

use App\Domain\Instances\InstanceLifecycleCommand;
use App\Domain\Instances\TransferCompensation;

return ['instance.lifecycle-state' => InstanceLifecycleCommand::class, 'instance.transfer-compensation' => TransferCompensation::class];
