<?php

declare(strict_types=1);

use Tests\Support\GuardsSudo;
use Tests\TestCase;

uses(GuardsSudo::class)->in('Feature', 'Unit');

uses(TestCase::class)->in('Feature', 'Unit');
