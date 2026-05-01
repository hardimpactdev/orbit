<?php

declare(strict_types=1);

namespace OrbitDocsLinter;

final readonly class CommandDocsLintBaseline
{
    /**
     * @param  array<string, int>  $counts
     */
    private function __construct(private array $counts) {}

    public static function empty(): self
    {
        return new self([]);
    }

    public static function fromFile(string $path): self
    {
        if (! is_file($path)) {
            return self::empty();
        }

        $decoded = json_decode(file_get_contents($path) ?: '', associative: true);

        if (! is_array($decoded)) {
            return self::empty();
        }

        return self::fromArray($decoded);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public static function fromArray(array $payload): self
    {
        $counts = [];

        foreach ($payload['findings'] ?? [] as $entry) {
            if (! is_array($entry)) {
                continue;
            }

            $path = $entry['path'] ?? null;
            $ruleId = $entry['rule_id'] ?? null;
            $severity = is_string($entry['severity'] ?? null)
                ? CommandDocsLintSeverity::tryFrom($entry['severity'])
                : null;
            $count = $entry['count'] ?? null;

            if (! is_string($path) || ! is_string($ruleId) || $severity === null || ! is_int($count) || $count < 1) {
                continue;
            }

            $counts[self::key($path, $ruleId, $severity)] = $count;
        }

        return new self($counts);
    }

    /**
     * @param  list<CommandDocsLintFinding>  $findings
     * @return list<CommandDocsLintFinding>
     */
    public function apply(array $findings): array
    {
        if ($this->counts === []) {
            return $findings;
        }

        $seen = [];
        $filtered = [];
        $exceeded = [];

        foreach ($findings as $finding) {
            $key = self::key($finding->path, $finding->ruleId, $finding->severity);
            $seen[$key] = ($seen[$key] ?? 0) + 1;

            if (! isset($this->counts[$key])) {
                $filtered[] = $finding;

                continue;
            }

            if ($seen[$key] <= $this->counts[$key]) {
                continue;
            }

            $filtered[] = $finding;
            $exceeded[$key] = $finding;
        }

        foreach ($exceeded as $key => $finding) {
            $filtered[] = new CommandDocsLintFinding(
                path: $finding->path,
                ruleId: 'command_docs.baseline',
                message: "{$finding->path} {$finding->ruleId} {$finding->severity->value} has {$seen[$key]} finding(s), above baseline count {$this->counts[$key]}. Fix the new finding(s) or revise the baseline deliberately.",
                severity: CommandDocsLintSeverity::Error,
                line: $finding->line,
            );
        }

        return $filtered;
    }

    private static function key(string $path, string $ruleId, CommandDocsLintSeverity $severity): string
    {
        return "{$path}\0{$ruleId}\0{$severity->value}";
    }
}
