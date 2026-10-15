<?php

declare(strict_types=1);

namespace App\Domain\Activity;

/**
 * A plug-in point: what Activity keeps of an extension command's input ([Extensions](/reference/extensions#plug-in-points)).
 *
 * Activity records the input before the switch check, so a redaction applies whether or not the extension is enabled.
 * Core sanitizes the result like any other input.
 */
interface ActivityInputRedaction
{
    /**
     * The route names whose input this redaction decides.
     *
     * @return list<string>
     */
    public function commands(): array;

    /**
     * The part of the request input that Activity may keep.
     *
     * @param  array<array-key, mixed>  $input  the request input
     * @return array<array-key, mixed>
     */
    public function input(string $command, array $input): array;
}
