<?php

declare(strict_types=1);

namespace App\Commands\Solo;

final class SoloCommandSignature
{
    public function withNodeOption(string $signature): string
    {
        $nodeOption = '{--node= : Target node with Solo installed}';
        $transportOption = '{--node-transport= : Node command transport preference (auto|agent-push|transitional-ssh-fallback)}';
        $firstOptionPosition = strpos(haystack: $signature, needle: ' {--');
        $nodeOptions = "{$nodeOption} {$transportOption}";

        if ($firstOptionPosition === false) {
            return "{$signature} {$nodeOptions}";
        }

        return (
            substr(string: $signature, offset: 0, length: $firstOptionPosition)
            .' '
            .$nodeOptions
            .substr(
                string: $signature,
                offset: $firstOptionPosition,
            )
        );
    }
}
