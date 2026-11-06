<?php

declare(strict_types=1);

namespace App\Domain\Proof;

/** One line of the selection list: a path glob and the value it selects. */
final readonly class ProofSelectionRule
{
    private string $pattern;

    /** @param list<string> $commands */
    public function __construct(
        public string $glob,
        public ProofRuleKind $kind,
        public array $commands = [],
        public ?string $reason = null,
    ) {
        $this->pattern = self::regex($glob);
    }

    public function matches(string $path): bool
    {
        return preg_match($this->pattern, $path) === 1;
    }

    /** `**` crosses directories, `*` and `?` stay inside one name, and `**` followed by `/` also matches no directory. */
    private static function regex(string $glob): string
    {
        $pattern = '';
        $length = strlen($glob);
        for ($i = 0; $i < $length; $i++) {
            $char = $glob[$i];
            if ($char === '*' && ($glob[$i + 1] ?? '') === '*') {
                $i++;
                if (($glob[$i + 1] ?? '') === '/') {
                    $i++;
                    $pattern .= '(?:.*/)?';
                } else {
                    $pattern .= '.*';
                }
            } elseif ($char === '*') {
                $pattern .= '[^/]*';
            } elseif ($char === '?') {
                $pattern .= '[^/]';
            } else {
                $pattern .= preg_quote($char, '~');
            }
        }

        return '~\A'.$pattern.'\z~D';
    }
}
