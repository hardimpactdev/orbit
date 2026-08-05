<?php

declare(strict_types=1);

namespace App\Services\ApplicationLogs;

final readonly class ApplicationLogFlags
{
    public function __construct(
        public int $lines,
        public bool $follow,
        public bool $json,
        public ?string $node,
    ) {}

    /**
     * @return array{ok: true, flags: self}|array{ok: false, field: string, message: string}
     */
    public static function fromOptions(mixed $lines, mixed $follow, mixed $json, mixed $node): array
    {
        $lineCount = self::parsePositiveIntegerLines($lines);

        if ($lineCount === null) {
            return [
                'ok' => false,
                'field' => 'lines',
                'message' => 'The --lines value must be a positive integer.',
            ];
        }

        $followEnabled = $follow === true;
        $jsonEnabled = $json === true;

        if ($followEnabled && $jsonEnabled) {
            return [
                'ok' => false,
                'field' => 'json',
                'message' => '--json cannot be combined with --follow for log streams.',
            ];
        }

        $nodeName = is_string($node) && trim($node) !== '' ? trim($node) : null;

        return [
            'ok' => true,
            'flags' => new self(
                lines: $lineCount,
                follow: $followEnabled,
                json: $jsonEnabled,
                node: $nodeName,
            ),
        ];
    }

    public static function parsePositiveIntegerLines(mixed $value): ?int
    {
        if (is_int($value)) {
            return $value >= 1 ? $value : null;
        }

        if (is_float($value) || ! is_string($value)) {
            return null;
        }

        $text = trim($value);

        if ($text === '' || preg_match('/\A[1-9][0-9]*\z/', $text) !== 1) {
            return null;
        }

        $max = (string) PHP_INT_MAX;

        if (strlen($text) > strlen($max) || strlen($text) === strlen($max) && $text > $max) {
            return null;
        }

        return (int) $text;
    }

    /**
     * @return array<string, mixed>
     */
    public function query(array $extra = []): array
    {
        return array_filter(
            [
                ...$extra,
                'lines' => $this->lines,
                'node' => $this->node,
            ],
            static fn (mixed $value): bool => $value !== null && $value !== '',
        );
    }
}
