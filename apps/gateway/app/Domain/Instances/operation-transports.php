<?php

declare(strict_types=1);

use App\Domain\Instances\Deployment\InstanceDeploymentTransport;

return [...require __DIR__.'/lifecycle-operation-transports.php', ...require __DIR__.'/environment-operation-transports.php', 'instance-deploy-agent' => InstanceDeploymentTransport::class];
