<?php

declare(strict_types=1);

use App\Domain\Instances\Deployment\InstanceDeploymentTransport;

return [...require __DIR__.'/lifecycle-operation-transports.php', 'instance-deploy-agent' => InstanceDeploymentTransport::class];
