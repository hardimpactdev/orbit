<?php

declare(strict_types=1);

namespace OrbitDocsLinter;

final class JsonExampleParser
{
    /**
     * @return list<JsonExample>
     */
    public function parse(string $path, string $contents): array
    {
        preg_match_all('/```json[ \t]*\R(?<json>.*?)```/s', $contents, $matches, PREG_SET_ORDER | PREG_OFFSET_CAPTURE);

        $examples = [];

        foreach ($matches as $index => $match) {
            $raw = trim($match['json'][0]);
            $decoded = json_decode($raw, true);
            $parseError = json_last_error() === JSON_ERROR_NONE ? null : json_last_error_msg();

            $examples[] = new JsonExample(
                path: $path,
                blockIndex: $index,
                line: $this->lineForOffset($contents, $match[0][1]),
                raw: $raw,
                decoded: $decoded,
                parseError: $parseError,
            );
        }

        return $examples;
    }

    private function lineForOffset(string $contents, int $offset): int
    {
        return substr_count(substr($contents, 0, $offset), "\n") + 1;
    }
}
