<?php

declare(strict_types=1);

namespace App\Domain\Engine;

/**
 * Content a step's input carries (docs/reference/gateway-engine.md#content): each `{"blob": {"content": …}}` goes into the
 * Node's own content keys, and the signed order names only its SHA-256. An order stays small, and a secret travels only to
 * the one Node that may read it.
 */
final readonly class EngineContent
{
    /**
     * @param  array<array-key, mixed>  $input
     * @return array<array-key, mixed>
     */
    public static function upload(MachineBus $bus, int $node, array $input): array
    {
        foreach ($input as $key => $value) {
            if (! is_array($value)) {
                continue;
            }
            $blob = $value['blob'] ?? null;
            $input[$key] = is_array($blob) && is_string($blob['content'] ?? null) && count($value) === 1
                ? ['blob' => $bus->putContent($node, $blob['content'])]
                : self::upload($bus, $node, $value);
        }

        return $input;
    }
}
