<?php

declare(strict_types=1);

namespace App\Domain\Tasks;

use App\Infrastructure\Activity\CommandActivityInputSanitizer;
use App\Support\ValidatedData;

final readonly class TaskCheckOutputBuilder
{
    private const int ExcerptBytes = 16 * 1024;

    private int $rawWindowBytes;

    public function __construct(private CommandActivityInputSanitizer $sanitizer)
    {
        $policy = ValidatedData::object(json_decode((string) file_get_contents(__DIR__.'/../../../resources/tasks/check-output.json'), true, flags: JSON_THROW_ON_ERROR));
        $this->rawWindowBytes = ValidatedData::integer($policy['raw_window_bytes']);
    }

    public function tail(string $output): string
    {
        $output = $this->rawWindow($output);
        $output = $this->sanitizer->redactText($output);

        return $this->trimPartialStart(substr($output, -self::ExcerptBytes));
    }

    /** Quote an already redacted output tail without changing its contents. */
    public function build(string $output): string
    {
        return $this->quote(trim($output));
    }

    /** @param iterable<string> $chunks */
    public function buildStream(iterable $chunks): string
    {
        $output = '';
        foreach ($chunks as $chunk) {
            // Keep the preceding byte so tail() can recognize an exact line boundary.
            $output = substr($output.$chunk, -($this->rawWindowBytes + 1));
        }

        return $this->build($this->tail($output));
    }

    private function rawWindow(string $output): string
    {
        $start = max(0, strlen($output) - $this->rawWindowBytes);
        if ($start > 0 && $output[$start - 1] !== "\n") {
            $newline = strpos($output, "\n", $start);
            $start = $newline === false ? $start : $newline + 1;
        }

        $window = $this->normalize(substr($output, $start));
        preg_match_all('/-----(BEGIN|END) [A-Z0-9 ]+-----/', $window, $markers, PREG_OFFSET_CAPTURE);
        $open = false;
        $safeStart = 0;
        foreach ($markers[1] as $index => [$kind]) {
            if ($kind === 'BEGIN') {
                $open = true;
            } else {
                if (! $open) {
                    $safeStart = $markers[0][$index][1] + strlen($markers[0][$index][0]);
                }
                $open = false;
            }
        }

        return $this->trimPartialStart(substr($window, $safeStart));
    }

    private function trimPartialStart(string $output): string
    {
        // A byte window may start in the middle of a UTF-8 code point.
        $start = 0;
        while ($start < strlen($output) && (ord($output[$start]) & 0xC0) === 0x80) {
            $start++;
        }

        return substr($output, $start);
    }

    private function normalize(string $output): string
    {
        $output = preg_replace('/\x1b\[[0-?]*[ -\/]*[@-~]/', '', $output) ?? '';

        return preg_replace('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d+)?Z /m', '', $output) ?? '';
    }

    private function quote(string $output): string
    {
        if ($output === '') {
            return '';
        }
        preg_match_all('/`+/', $output, $ticks);
        $fence = str_repeat('`', max([3, ...array_map(static fn (string $run): int => strlen($run) + 1, $ticks[0])]));

        return $fence."\n".$output."\n".$fence;
    }
}
