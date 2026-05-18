<?php

declare(strict_types=1);

namespace App\Docs\Librarian\Rules;

use App\Docs\Librarian\OrbitCommandDocs;
use HardImpact\Librarian\Linting\Finding;
use HardImpact\Librarian\Linting\FindingSeverity;
use HardImpact\Librarian\Linting\GroupedRule;

final readonly class SignatureArgumentOrderRule implements GroupedRule
{
    public function __construct(
        private OrbitCommandDocs $docs,
    ) {}

    public function group(): string
    {
        return 'contracts';
    }

    public function check(): array
    {
        $findings = [];

        foreach ($this->docs->familyDirectories() as $familyDirectory) {
            foreach ($this->docs->markdownFiles($familyDirectory) as $file) {
                if (preg_match('#/technical/1_[a-z0-9-]+\.md$#', $file) !== 1) {
                    continue;
                }

                $signature = $this->signature(file_get_contents($file) ?: '');

                if ($signature === null) {
                    continue;
                }

                $expected = $this->expectedSignature($signature['line']);

                if ($expected === $signature['line']) {
                    continue;
                }

                $findings[] = new Finding(
                    path: $this->docs->relativePath($file),
                    line: $signature['lineNumber'],
                    severity: FindingSeverity::Error,
                    rule: 'command_docs.signature_argument_order',
                    message: 'Command signature arguments must come before flags. Required entries come before optional entries inside each group. Shared target flags use `--app`, `--workspace`, then `--node`; `--json` stays last. Expected signature: `'.$expected.'`.',
                );
            }
        }

        return $findings;
    }

    /**
     * @return array{line: string, lineNumber: int}|null
     */
    private function signature(string $contents): ?array
    {
        if (preg_match('/\n## Signature\s*.*?```(?:bash|text)?\s*\n(?<signature>orbit .+?)\n```/s', $contents, $matches, PREG_OFFSET_CAPTURE) !== 1) {
            return null;
        }

        return [
            'line' => $matches['signature'][0],
            'lineNumber' => substr_count(substr($contents, 0, $matches['signature'][1]), "\n") + 1,
        ];
    }

    private function expectedSignature(string $signature): string
    {
        $parts = $this->splitSignature($signature);

        if (count($parts) <= 2) {
            return $signature;
        }

        $prefix = array_slice($parts, 0, 2);
        $tokens = [];

        foreach (array_slice($parts, 2) as $index => $token) {
            $tokens[] = [
                'raw' => $token,
                'index' => $index,
                'key' => $this->orderKey($token, $index),
            ];
        }

        usort(
            $tokens,
            fn (array $first, array $second): int => $first['key'] <=> $second['key'],
        );

        return implode(' ', [
            ...$prefix,
            ...array_map(fn (array $token): string => $token['raw'], $tokens),
        ]);
    }

    /**
     * @return list<string>
     */
    private function splitSignature(string $signature): array
    {
        $tokens = [];
        $buffer = '';
        $depth = 0;
        $length = strlen($signature);

        for ($i = 0; $i < $length; $i++) {
            $character = $signature[$i];

            if ($depth === 0 && ctype_space($character)) {
                if ($buffer !== '') {
                    $tokens[] = $buffer;
                    $buffer = '';
                }

                continue;
            }

            if ($character === '[' || $character === '(') {
                $depth++;
            }

            if ($character === ']' || $character === ')') {
                $depth = max(0, $depth - 1);
            }

            $buffer .= $character;
        }

        if ($buffer !== '') {
            $tokens[] = $buffer;
        }

        return $tokens;
    }

    /**
     * @return array{int, int, int, int}
     */
    private function orderKey(string $token, int $index): array
    {
        return [
            $this->isOptionToken($token) ? 1 : 0,
            $this->isOptional($token) ? 1 : 0,
            $this->specificityRank($token),
            $index,
        ];
    }

    private function isOptional(string $token): bool
    {
        return str_starts_with($token, '[') && str_ends_with($token, ']');
    }

    private function isOptionToken(string $token): bool
    {
        return preg_match('/--[a-z0-9][a-z0-9-]*/', $token) === 1;
    }

    private function specificityRank(string $token): int
    {
        preg_match_all('/--(?<name>[a-z0-9][a-z0-9-]*)/', $token, $matches);

        if ($matches['name'] === []) {
            return 500;
        }

        $ranks = array_map(
            fn (string $name): int => $this->optionRank($name),
            $matches['name'],
        );

        return min($ranks);
    }

    private function optionRank(string $name): int
    {
        return match ($name) {
            'app' => 10,
            'workspace' => 20,
            'node', 'self' => 30,
            'json' => 1000,
            default => 500,
        };
    }
}
