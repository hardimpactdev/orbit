<?php

declare(strict_types=1);

namespace App\Domain\Tasks;

use RuntimeException;

/**
 * Preparing the subtask's declared topology failed. This is not a wait: the subtask asks for assistance with this
 * message, and a resolution prepares the topology again.
 */
final class TaskTopologyFailedException extends RuntimeException {}
