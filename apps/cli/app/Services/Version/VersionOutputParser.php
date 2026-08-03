<?php

declare(strict_types=1);

namespace App\Services\Version;

use JsonException;

/**
 * Parses Orbit version from structured `--version --json` output.
 *
 * Prefer JSON so install/update metadata never scrapes human Version table
 * rows or the first dotted triple in mixed progress stdout.
 */
final class VersionOutputParser
{
    /**
     * Extract the installed version from JSON version command output.
     * Returns null when the payload is missing or malformed.
     */
    public function fromJsonOutput(string $output): ?string
    {
        $payload = $this->decodeJsonEnvelope($output);

        if ($payload === null) {
            return null;
        }

        $version = $payload['version'] ?? null;

        if (! is_string($version)) {
            return null;
        }

        $version = trim($version);

        return $version === '' ? null : $version;
    }

    /**
     * Prefer JSON; do not scrape human tables or arbitrary dotted triples.
     */
    public function fromAnyOutput(string $output): ?string
    {
        return $this->fromJsonOutput($output);
    }

    /**
     * @return array<string, mixed>|null
     */
    private function decodeJsonEnvelope(string $output): ?array
    {
        $trimmed = trim($output);

        if ($trimmed === '') {
            return null;
        }

        try {
            /** @var mixed $decoded */
            $decoded = json_decode($trimmed, associative: true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            // Progress lines may precede the JSON object; take the last JSON object line.
            $lines = preg_split('/\R/', $trimmed) ?: [];
            $decoded = null;

            for ($index = count($lines) - 1; $index >= 0; $index--) {
                $line = trim((string) $lines[$index]);

                if ($line === '' || ! str_starts_with($line, '{')) {
                    continue;
                }

                try {
                    $decoded = json_decode($line, associative: true, flags: JSON_THROW_ON_ERROR);
                    break;
                } catch (JsonException) {
                    continue;
                }
            }

            if ($decoded === null) {
                return null;
            }
        }

        if (! is_array($decoded)) {
            return null;
        }

        // Orbit success envelope: { "success": { "data": { "version": "..." } } }
        if (isset($decoded['success']) && is_array($decoded['success'])) {
            $data = $decoded['success']['data'] ?? null;

            return is_array($data) ? $data : null;
        }

        // Flat VersionInfo shape.
        if (array_key_exists('version', $decoded)) {
            return $decoded;
        }

        return null;
    }
}
