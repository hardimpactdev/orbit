<?php

declare(strict_types=1);

namespace App\E2E\Value;

use InvalidArgumentException;

/** The resource names of one issue attempt: its network and one guest per declared Node. */
final readonly class TopologyTarget
{
    private const string ISSUE_PATTERN = '/\A[A-Z][A-Z0-9]{1,9}-[1-9][0-9]{0,8}\z/D';

    private function __construct(
        public string $issue,
        public AttemptId $attempt,
        public TopologyRecipe $recipe,
    ) {}

    public static function feature(string $issue, AttemptId $attempt, TopologyRecipe $recipe): self
    {
        self::assertIssue($issue);

        return new self($issue, $attempt, $recipe);
    }

    public static function assertIssue(string $issue): void
    {
        if (preg_match(self::ISSUE_PATTERN, $issue) !== 1) {
            throw new InvalidArgumentException('The topology issue ID is invalid; it looks like TASK-123.');
        }
    }

    public static function ipv4For(int $slot, int $address): string
    {
        if ($slot < 1 || $slot > 200) {
            throw new InvalidArgumentException('The topology slot is invalid.');
        }
        if ($address < 10 || $address > 254) {
            throw new InvalidArgumentException('The topology Node address position is invalid.');
        }

        return '10.232.'.$slot.'.'.$address;
    }

    public function matchesBranch(string $branch): bool
    {
        return self::issueMatchesBranch($this->issue, $branch);
    }

    public static function issueMatchesBranch(string $issue, string $branch): bool
    {
        self::assertIssue($issue);

        return
            preg_match(
                '/(?:\A|[^a-z0-9])'.preg_quote($issue, '/').'(?=\z|[^a-z0-9])/i',
                $branch,
            ) === 1;
    }

    public function network(): string
    {
        return 'oe-'.substr(hash('sha256', $this->issue.':'.$this->attempt->value), 0, 12);
    }

    public function instance(string $node): string
    {
        return 'orbit-e2e-'.strtolower($this->issue).'-'.$this->attempt->short().'-'.$this->recipe->node($node)->key;
    }

    public function mac(string $node): string
    {
        return self::macFor($this->network(), $this->recipe->node($node)->key);
    }

    public static function macFor(string $topology, string $node): string
    {
        if (preg_match('/\A[a-zA-Z0-9][a-zA-Z0-9_.-]{0,62}\z/D', $topology) !== 1) {
            throw new InvalidArgumentException('The topology network identity is invalid.');
        }
        TopologyNode::assertKey($node);

        $hash = substr(sha1($topology.':'.$node), 0, 6);

        return '00:16:3e:'.implode(':', str_split($hash, 2));
    }

    /** @return array<string, string> Node key => instance name, in recipe order. */
    public function instances(): array
    {
        $instances = [];
        foreach ($this->recipe->nodeKeys() as $node) {
            $instances[$node] = $this->instance($node);
        }

        return $instances;
    }
}
