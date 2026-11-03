<?php

declare(strict_types=1);

use App\Domain\Instances\Deployment\AutomaticDeployFireProjection;

return [AutomaticDeployFireProjection::Workflow => ['workflow' => 'automatic-production-deploy',
    'terminal_result' => ['job' => 'policy', 'step' => 'failures', 'native' => true,
        'captures' => ['fire_json' => ['job' => 'policy', 'step' => 'tick', 'field' => 'fire_json']]], 'projection' => AutomaticDeployFireProjection::class]];
