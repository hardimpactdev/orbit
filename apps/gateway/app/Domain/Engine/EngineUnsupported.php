<?php

declare(strict_types=1);

namespace App\Domain\Engine;

use RuntimeException;

/**
 * An Operation of an engine kind that needs a part the engine does not run yet, such as a Route with private DNS. It starts
 * on Swamp, as an Operation on a Node without the primitive does (docs/reference/gateway-engine.md#which-kinds-run-on-the-engine).
 */
final class EngineUnsupported extends RuntimeException {}
