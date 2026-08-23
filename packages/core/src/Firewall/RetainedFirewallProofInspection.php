<?php

declare(strict_types=1);

namespace Orbit\Core\Firewall;

/**
 * @mago-expect lint:cyclomatic-complexity
 */
final readonly class RetainedFirewallProofInspection
{
    /**
     * @param  list<array{index: int, action: string, port: string, comment: string}>  $rules
     */
    private function __construct(
        private array $rules,
    ) {}

    public static function fromUfwStatus(string $output): self
    {
        $rules = [];

        $lines = preg_split('/\R/', $output);

        foreach (is_array($lines) ? $lines : [] as $line) {
            $parsed = self::parseLine(trim($line));

            if ($parsed !== null) {
                $rules[] = $parsed;
            }
        }

        return new self($rules);
    }

    /**
     * @return list<string>
     */
    public function managedComments(?string $port = null): array
    {
        $comments = [];

        foreach ($this->rules as $rule) {
            if ($port !== null && $rule['port'] !== $port) {
                continue;
            }

            if (str_starts_with($rule['comment'], 'orbit:')) {
                $comments[] = $rule['comment'];
            }
        }

        return array_values(array_unique($comments));
    }

    public function hasComment(string $comment): bool
    {
        return array_any($this->rules, static fn (array $rule): bool => $rule['comment'] === $comment);
    }

    public function managedAllowPrecedesBroadDeny(): bool
    {
        $managedIndex = null;
        $denyIndex = null;

        foreach ($this->rules as $rule) {
            if (
                $rule['port'] === RetainedFirewallProofScenario::PORT
                && $rule['action'] === 'allow'
                && $rule['comment'] === RetainedFirewallProofScenario::MANAGED_IDENTITY
            ) {
                $managedIndex = $rule['index'];
            }

            if (
                $rule['port'] === RetainedFirewallProofScenario::PORT
                && $rule['action'] === 'deny'
                && $rule['comment'] === ''
            ) {
                $denyIndex ??= $rule['index'];
            }
        }

        return $managedIndex !== null && $denyIndex !== null && $managedIndex < $denyIndex;
    }

    /**
     * @return array{index: int, action: string, port: string, comment: string}|null
     */
    private static function parseLine(string $line): ?array
    {
        $matches = [];

        if (
            preg_match(
                '/^\[\s*(\d+)\]\s+(\d{1,5}(?::\d{1,5})?)\/(tcp|udp)\b.*\b(ALLOW|DENY)\b.*?(?:#\s*(.*))?$/',
                $line,
                $matches,
            ) !== 1
        ) {
            return null;
        }

        return [
            'index' => (int) $matches[1],
            'action' => strtolower($matches[4]),
            'port' => $matches[2],
            'comment' => trim($matches[5] ?? ''),
        ];
    }
}
