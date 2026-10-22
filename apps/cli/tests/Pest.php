<?php

declare(strict_types=1);

use Tests\TestCase;

require_once __DIR__.'/Helpers/GatewayFixtures.php';
require_once __DIR__.'/Helpers/InstanceCommandFixtures.php';
require_once __DIR__.'/Helpers/RealtimeFixtures.php';
require_once __DIR__.'/Helpers/SelfUpdateFixtures.php';
require_once __DIR__.'/Support/SubprocessFixture.php';

uses(TestCase::class)->in('Feature');
