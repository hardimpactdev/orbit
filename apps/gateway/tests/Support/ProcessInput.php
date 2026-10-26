<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Infrastructure\Processes\ProtectedInput;

/** Reads small fixture inputs at a fake process boundary. Production streams them without buffering. */
final class ProcessInput
{
    public static function contents(?ProtectedInput $input): string
    {
        $contents = '';
        foreach ($input?->chunks() ?? [] as $chunk) {
            $contents .= is_resource($chunk) ? stream_get_contents($chunk) : $chunk;
        }

        return $contents;
    }
}
