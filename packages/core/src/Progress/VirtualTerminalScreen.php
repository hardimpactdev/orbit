<?php

declare(strict_types=1);

namespace Orbit\Core\Progress;

/**
 * Minimal ANSI terminal emulator for validating in-place progress-tree repaints.
 */
final class VirtualTerminalScreen
{
    public const string SPINNER_CYAN_OPEN = 'cyan-open';

    public const string SPINNER_CYAN_FILLED = 'cyan-filled';

    private const string COLOR_DEFAULT = 'default';

    private const string COLOR_CYAN = 'cyan';

    private const string COLOR_DIM = 'dim';

    /**
     * @var array<int, array<int, array{char: string, color: string}>>
     */
    private array $cells = [];

    private int $cursorRow = 0;

    private int $cursorCol = 0;

    private string $currentColor = self::COLOR_DEFAULT;

    public function feed(string $chunk): void
    {
        $length = strlen($chunk);
        $index = 0;

        while ($index < $length) {
            if ($chunk[$index] === "\e") {
                $index = $this->consumeEscapeSequence($chunk, $index, $length);

                continue;
            }

            if ($chunk[$index] === "\r") {
                $this->cursorCol = 0;
                $index++;

                continue;
            }

            if ($chunk[$index] === "\n") {
                $this->cursorRow++;
                $this->cursorCol = 0;
                $index++;

                continue;
            }

            if ($chunk[$index] === "\b") {
                $this->cursorCol = max(0, $this->cursorCol - 1);
                $index++;

                continue;
            }

            [$character, $nextIndex] = $this->readCharacter($chunk, $index, $length);
            $this->writeCharacter($character);
            $index = $nextIndex;
        }
    }

    /**
     * @return list<string>
     */
    public function lines(): array
    {
        if ($this->cells === []) {
            return [];
        }

        $maxRow = max(array_keys($this->cells));

        return array_map(
            $this->lineText(...),
            range(0, $maxRow),
        );
    }

    /**
     * @return list<array{row: int, text: string, spinner: string}>
     */
    public function progressRows(): array
    {
        $rows = [];

        if ($this->cells === []) {
            return [];
        }

        foreach (range(0, max(array_keys($this->cells))) as $rowIndex) {
            $spinner = $this->classifyRowSpinner($rowIndex);

            if ($spinner === null) {
                continue;
            }

            $rows[] = [
                'row' => $rowIndex,
                'text' => $this->lineText($rowIndex),
                'spinner' => $spinner,
            ];
        }

        return $rows;
    }

    /**
     * @return list<array{row: int, text: string, spinner: string}>
     */
    public function rowsMatching(string $label, string $message): array
    {
        return array_values(array_filter(
            $this->progressRows(),
            static fn (array $row): bool => str_contains((string) $row['text'], $label)
                && str_contains((string) $row['text'], $message),
        ));
    }

    /**
     * @param  array{row: int, spinner: string}|null  $lastObservation
     * @return list<array{row: int, label: string, message: string, spinner: string}>
     */
    public function feedAndCollectMatchingSpinnerStates(
        string $chunk,
        string $label,
        string $message,
        ?array &$lastObservation = null,
    ): array {
        $this->feed($chunk);

        $row = $this->rowsMatching($label, $message)[0] ?? null;

        if ($row === null) {
            return [];
        }

        $observation = [
            'row' => $row['row'],
            'label' => $label,
            'message' => $message,
            'spinner' => $row['spinner'],
        ];

        if ($lastObservation !== null
            && $lastObservation['row'] === $observation['row']
            && $lastObservation['spinner'] === $observation['spinner']) {
            return [];
        }

        $lastObservation = [
            'row' => $observation['row'],
            'spinner' => $observation['spinner'],
        ];

        return [$observation];
    }

    public function classifySpinner(string $text): ?string
    {
        if (preg_match('/\e\[36m○/u', $text) === 1) {
            return self::SPINNER_CYAN_OPEN;
        }

        if (preg_match('/\e\[36m◉/u', $text) === 1) {
            return self::SPINNER_CYAN_FILLED;
        }

        if (preg_match('/\e\[38;5;242m○/u', $text) === 1) {
            return null;
        }

        if (str_contains($text, '◉')) {
            return self::SPINNER_CYAN_FILLED;
        }

        if (str_contains($text, '○')) {
            return self::SPINNER_CYAN_OPEN;
        }

        return null;
    }

    private function consumeEscapeSequence(string $chunk, int $index, int $length): int
    {
        if ($index + 1 >= $length) {
            return $length;
        }

        if ($chunk[$index + 1] === '[') {
            return $this->consumeCsiSequence($chunk, $index, $length);
        }

        return $index + 2;
    }

    private function consumeCsiSequence(string $chunk, int $index, int $length): int
    {
        $start = $index + 2;
        $sequence = '';
        $private = false;

        for ($cursor = $start; $cursor < $length; $cursor++) {
            $character = $chunk[$cursor];

            if ($character === '?' && $sequence === '' && ! $private) {
                $private = true;
                $sequence .= $character;

                continue;
            }

            if (($character >= '0' && $character <= '9') || $character === ';') {
                $sequence .= $character;

                continue;
            }

            if ($character >= '@' && $character <= '~') {
                if ($character === 'm') {
                    $this->applySgr($sequence);
                } elseif (! $private) {
                    $this->applyCsiCommand($this->firstParameter($sequence), $character);
                }

                return $cursor + 1;
            }

            break;
        }

        return $length;
    }

    private function applyCsiCommand(int $parameter, string $finalByte): void
    {
        match ($finalByte) {
            'A' => $this->cursorRow = max(0, $this->cursorRow - $parameter),
            'B' => $this->cursorRow += $parameter,
            'C' => $this->cursorCol += $parameter,
            'D' => $this->cursorCol = max(0, $this->cursorCol - $parameter),
            'G' => $this->cursorCol = max(0, $parameter - 1),
            'K' => $this->clearLine($parameter),
            'J' => $this->clearDisplay($parameter),
            default => null,
        };
    }

    private function applySgr(string $sequence): void
    {
        $parameters = $sequence === ''
            ? [0]
            : array_map(intval(...), explode(';', $sequence));

        for ($index = 0; $index < count($parameters); $index++) {
            $parameter = $parameters[$index];

            if ($parameter === 0 || $parameter === 39) {
                $this->currentColor = self::COLOR_DEFAULT;

                continue;
            }

            if ($parameter === 36) {
                $this->currentColor = self::COLOR_CYAN;

                continue;
            }

            if ($parameter === 38
                && ($parameters[$index + 1] ?? null) === 5
                && ($parameters[$index + 2] ?? null) === 242) {
                $this->currentColor = self::COLOR_DIM;
                $index += 2;
            }
        }
    }

    private function firstParameter(string $sequence): int
    {
        $first = explode(';', $sequence)[0] ?? '';

        return $first === '' ? 1 : max(1, (int) $first);
    }

    private function clearLine(int $mode): void
    {
        if ($mode !== 2) {
            return;
        }

        $this->cells[$this->cursorRow] = [];
        $this->cursorCol = 0;
    }

    private function clearDisplay(int $mode): void
    {
        if ($mode !== 2) {
            return;
        }

        $this->cells = [];
        $this->cursorRow = 0;
        $this->cursorCol = 0;
    }

    /**
     * @return array{0: string, 1: int}
     */
    private function readCharacter(string $chunk, int $index, int $length): array
    {
        $byte = ord($chunk[$index]);
        $characterLength = match (true) {
            ($byte & 0b1000_0000) === 0 => 1,
            ($byte & 0b1110_0000) === 0b1100_0000 => 2,
            ($byte & 0b1111_0000) === 0b1110_0000 => 3,
            ($byte & 0b1111_1000) === 0b1111_0000 => 4,
            default => 1,
        };

        $characterLength = min($characterLength, $length - $index);

        return [substr($chunk, $index, $characterLength), $index + $characterLength];
    }

    private function writeCharacter(string $character): void
    {
        $this->cells[$this->cursorRow][$this->cursorCol] = [
            'char' => $character,
            'color' => $this->currentColor,
        ];
        $this->cursorCol++;
    }

    private function lineText(int $row): string
    {
        $line = $this->cells[$row] ?? [];

        if ($line === []) {
            return '';
        }

        $maxColumn = max(array_keys($line));
        $text = '';

        for ($column = 0; $column <= $maxColumn; $column++) {
            $text .= $line[$column]['char'] ?? ' ';
        }

        return rtrim($text);
    }

    private function classifyRowSpinner(int $row): ?string
    {
        foreach ($this->cells[$row] ?? [] as $cell) {
            if ($cell['char'] === '○') {
                return $cell['color'] === self::COLOR_CYAN ? self::SPINNER_CYAN_OPEN : null;
            }

            if ($cell['char'] === '◉') {
                return $cell['color'] === self::COLOR_CYAN ? self::SPINNER_CYAN_FILLED : null;
            }
        }

        return null;
    }
}
