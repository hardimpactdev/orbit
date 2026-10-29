<?php

declare(strict_types=1);
use App\Domain\Instances\Deployment\InstanceChildReceiptCommand;
use App\Domain\Instances\Deployment\InstanceDeploymentBudgetCommand;
use App\Domain\Instances\Deployment\InstanceDeploymentReceiptCommand;
use App\Domain\Instances\Deployment\InstanceExecutorRuntimeCommand;
use App\Domain\Instances\Deployment\InstanceProjectionReceiptCommand;
use App\Domain\Instances\Environment\InstanceServedWebRootsReceiptCommand;
use App\Domain\Instances\PhpRuntime\InstancePhpPlanCommand;
use App\Domain\Instances\PhpRuntime\InstancePhpReceiptCommand;
use App\Domain\Instances\StepLists\InstanceStepBudgetCommand;
use App\Domain\Instances\StepLists\InstanceStepListReceiptCommand;
use App\Domain\Instances\VpEnvironment\InstanceVpPlanCommand;
use App\Domain\Instances\VpEnvironment\InstanceVpReceiptCommand;

return [
    ...require __DIR__.'/lifecycle-operation-commands.php',
    ...require __DIR__.'/environment-operation-commands.php',
    'instance.web-roots-receipt' => InstanceServedWebRootsReceiptCommand::class,
    'instance.projection-receipt' => InstanceProjectionReceiptCommand::class,
    'instance.executor-runtime' => InstanceExecutorRuntimeCommand::class,
    'instance.child-receipt' => InstanceChildReceiptCommand::class,
    'instance.deployment-budget' => InstanceDeploymentBudgetCommand::class,
    'instance.step-budget' => InstanceStepBudgetCommand::class,
    'instance.php-plan' => InstancePhpPlanCommand::class,
    'instance.php-receipt' => InstancePhpReceiptCommand::class,
    'instance.vp-plan' => InstanceVpPlanCommand::class,
    'instance.vp-receipt' => InstanceVpReceiptCommand::class,
    'instance.deployment-receipt' => InstanceDeploymentReceiptCommand::class,
    'instance.step-list-receipt' => InstanceStepListReceiptCommand::class,
];
