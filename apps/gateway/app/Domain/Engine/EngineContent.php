<?php

declare(strict_types=1);

namespace App\Domain\Engine;

/**
 * Content a step's input carries (docs/reference/gateway-engine.md#content): each `{"blob": {"content": …}}`, also one with
 * its `from_sha256` guard, goes into the
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
            // A guarded file keeps its `from_sha256` beside the blob (dns.publish_records with `dns.guarded_records`).
            $guarded = count($value) === 2 && is_string($value['from_sha256'] ?? null);
            $input[$key] = is_array($blob) && is_string($blob['content'] ?? null) && (count($value) === 1 || $guarded)
                ? ['blob' => $bus->putContent($node, $blob['content']), ...($guarded ? ['from_sha256' => $value['from_sha256']] : [])]
                : self::upload($bus, $node, $value);
        }

        return $input;
    }
}
