<?php

declare(strict_types=1);

namespace App\Services\Version;

use JsonException;

/**
 * Parses Orbit version from structured `version --json` output.
 *
 * Accepts only the shared success.data envelope. Install progress may precede
 * the JSON object on stdout; the last JSON object line is used. Flat
 * top-level `{version: ...}` is not accepted.
 *
 * @mago-expect lint:cyclomatic-complexity -- Last-JSON-line scan plus envelope unwrap.
 */
final class VersionOutputParser
{
    /**
     * Extract the installed version from JSON version command output.
     * Returns null when the payload is missing or malformed.
     */
    public function fromJsonOutput(string $output): ?string
    {
        $payload = $this->decodeSuccessData($output);

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
     * @return array<string, mixed>|null
     */
    private function decodeSuccessData(string $output): ?array
    {
        $decoded = $this->decodeLastJsonObject($output);

        if ($decoded === null) {
            return null;
        }

        // Orbit success envelope only: { "success": { "data": { "version": "..." } } }
        $success = $decoded['success'] ?? null;

        if (! is_array($success)) {
            return null;
        }

        $data = $success['data'] ?? null;

        if (! is_array($data)) {
            return null;
        }

        return $this->stringKeyedArray($data);
    }

    /**
     * @return array<string, mixed>|null
     */
    private function decodeLastJsonObject(string $output): ?array
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
            $split = preg_split('/\R/', $trimmed);
            $lines = is_array($split) ? $split : [];
            $decoded = null;

            for ($index = count($lines) - 1; $index >= 0; $index--) {
                $line = trim($lines[$index]);

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

        return $this->stringKeyedArray($decoded);
    }

    /**
     * @param  array<array-key, mixed>  $payload
     * @return array<string, mixed>|null
     */
    private function stringKeyedArray(array $payload): ?array
    {
        $result = [];

        foreach ($payload as $key => $value) {
            if (! is_string($key)) {
                return null;
            }

            $result[$key] = $value;
        }

        return $result;
    }
}
