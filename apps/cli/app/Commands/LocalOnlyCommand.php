<?php

declare(strict_types=1);

namespace App\Commands;

use App\Commands\Concerns\EmitsCanonicalEnvelopes;
use LaravelZero\Framework\Commands\Command;

/**
 * Base for public commands that mutate only caller-local config or environment.
 * No gateway accessor. May validate against the gateway via injected client,
 * but storage is local.
 */
abstract class LocalOnlyCommand extends Command
{
    use EmitsCanonicalEnvelopes;
}
