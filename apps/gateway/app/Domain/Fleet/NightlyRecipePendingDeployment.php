<?php

declare(strict_types=1);

namespace App\Domain\Fleet;

/** A normal release handoff wait; the next scheduled run checks the same date again. */
final class NightlyRecipePendingDeployment extends \RuntimeException {}
