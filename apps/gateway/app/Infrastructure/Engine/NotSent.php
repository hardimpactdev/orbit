<?php

declare(strict_types=1);

namespace App\Infrastructure\Engine;

use RuntimeException;

/** A request that was not written ([DeadlineRequest]): nothing reached the bus, so no reply can be missing. */
final class NotSent extends RuntimeException {}
