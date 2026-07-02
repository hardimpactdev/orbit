<?php

declare(strict_types=1);

namespace App\Commands\Solo;

final class SoloCommandSignature
{
    public function withNodeOption(string $signature): string
    {
        $nodeOption = '{--node= : Target node with Solo installed}';
        $firstOptionPosition = strpos(haystack: $signature, needle: ' {--');

        if ($firstOptionPosition === false) {
            return "{$signature} {$nodeOption}";
        }

        return (
            substr(string: $signature, offset: 0, length: $firstOptionPosition)
            .' '
            .$nodeOption
            .substr(
                string: $signature,
                offset: $firstOptionPosition,
            )
        );
    }
}
