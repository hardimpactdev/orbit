<?php

declare(strict_types=1);

namespace App\Domain\Proof;

use App\Domain\Shared\ResourceOperationException;

/** The parsed `swamp/proof/selection.txt`: path globs that select commands. The last matching line wins. */
final readonly class ProofSelectionList
{
    public const string Path = 'swamp/proof/selection.txt';

    private const string CommandPattern = '~\A[a-z][a-z0-9_-]*(?::[a-z][a-z0-9_-]*)+\z~D';

    /** @param list<ProofSelectionRule> $rules */
    private function __construct(private array $rules) {}

    public static function parse(string $text): self
    {
        $rules = [];
        foreach (preg_split('/\r\n|\n|\r/', $text) ?: [] as $number => $line) {
            $line = trim($line);
            if ($line === '' || $line[0] === '#') {
                continue;
            }
            $parts = preg_split('/\s+/', $line, 2) ?: [];
            if (count($parts) !== 2 || preg_match('~\A[^\s\\\\\[\]{}!/][^\s\\\\\[\]{}!]*\z~D', $parts[0]) !== 1) {
                throw self::invalid($number + 1, 'a line holds a path glob and its value');
            }
            $rules[] = self::rule($parts[0], trim($parts[1]), $number + 1);
        }

        return new self($rules);
    }

    /** The rule of the last line that matches the path, or null when no line does. */
    public function match(string $path): ?ProofSelectionRule
    {
        foreach (array_reverse($this->rules) as $rule) {
            if ($rule->matches($path)) {
                return $rule;
            }
        }

        return null;
    }

    private static function rule(string $glob, string $value, int $line): ProofSelectionRule
    {
        if ($value === 'skip') {
            return new ProofSelectionRule($glob, ProofRuleKind::Skip);
        }
        if ($value === '*') {
            return new ProofSelectionRule($glob, ProofRuleKind::Everything);
        }
        if (str_starts_with($value, 'none:')) {
            $reason = trim(substr($value, 5));

            return $reason === ''
                ? throw self::invalid($line, '`none:` needs a reason')
                : new ProofSelectionRule($glob, ProofRuleKind::None, reason: $reason);
        }
        $commands = preg_split('/\s+/', $value) ?: [];
        foreach ($commands as $command) {
            if (preg_match(self::CommandPattern, $command) !== 1) {
                throw self::invalid($line, "[{$command}] is not skip, *, none: or a family:command");
            }
        }

        return new ProofSelectionRule($glob, ProofRuleKind::Commands, array_values(array_unique($commands)));
    }

    private static function invalid(int $line, string $why): ResourceOperationException
    {
        return new ResourceOperationException('proof.selection_invalid', 'The selection list is malformed at line '.$line.': '.$why.'.', 422, details: ['line' => $line]);
    }
}
