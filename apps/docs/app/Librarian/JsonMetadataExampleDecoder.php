<?php

declare(strict_types=1);

namespace App\Librarian;

use JsonException;
use stdClass;

final class JsonMetadataExampleDecoder
{
    /**
     * @return list<stdClass|array<array-key, mixed>>
     */
    public function decodeExamples(string $raw): array
    {
        $decoded = $this->decode($raw);

        if ($decoded !== null) {
            return [$decoded];
        }

        return $this->decodeLineDelimitedExamples($raw);
    }

    /**
     * @return stdClass|array<array-key, mixed>|null
     */
    private function decode(string $raw): stdClass|array|null
    {
        try {
            return $this->jsonStructure(json_decode(
                $raw,
                associative: false,
                depth: 512,
                flags: JSON_THROW_ON_ERROR,
            ));
        } catch (JsonException) {
            return null;
        }
    }

    /**
     * @return list<stdClass|array<array-key, mixed>>
     */
    private function decodeLineDelimitedExamples(string $raw): array
    {
        $lines = preg_split('/\R/', trim($raw));

        if ($lines === false) {
            return [];
        }

        $structures = [];

        foreach ($lines as $line) {
            $decoded = $this->decode(trim($line));

            if ($decoded !== null) {
                $structures[] = $decoded;
            }
        }

        return $structures;
    }

    /**
     * @return stdClass|array<array-key, mixed>|null
     */
    private function jsonStructure(mixed $value): stdClass|array|null
    {
        if ($value instanceof stdClass || is_array($value)) {
            return $value;
        }

        return null;
    }
}
