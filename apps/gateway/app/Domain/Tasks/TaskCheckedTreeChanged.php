<?php

declare(strict_types=1);

namespace App\Domain\Tasks;

use RuntimeException;

/** The atomic handoff commit refused a workspace that no longer matches the checked snapshot. */
final class TaskCheckedTreeChanged extends RuntimeException {}
