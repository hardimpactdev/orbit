<?php

declare(strict_types=1);

namespace App\E2E;

use App\E2E\State\AtomicJsonStore;
use App\E2E\State\StatePaths;
use App\E2E\Value\AttemptId;
use App\E2E\Value\OperationId;
use App\E2E\Value\TopologyRecipe;
use App\E2E\Value\TopologyRecord;
use App\E2E\Value\TopologyTarget;
use InvalidArgumentException;
use RuntimeException;

/**
 * The state of one issue's topology, kept under `<worktree>/.e2e/`.
 *
 * `attempt.json` is the lease: it names the attempt, the operation, and the declared Nodes before any
 * resource exists, so release can find every guest of a failed acquisition. `topology.json` is the
 * composed topology after readiness passed. `log` is a plain-text line per harness command.
 */
final readonly class IssueState
{
    public const string ATTEMPT = 'attempt.json';

    public const string TOPOLOGY = 'topology.json';

    /** Read by the guests through the mount to prove which worktree they see. */
    public const string MOUNT_TOKEN = 'mount-token';

    private AtomicJsonStore $store;

    public function __construct(
        public string $issue,
        public string $worktree,
        private StatePaths $paths,
    ) {
        $this->store = new AtomicJsonStore($paths);
    }

    public static function forWorktree(string $issue, string $worktree): self
    {
        return new self($issue, $worktree, StatePaths::forWorktree($worktree));
    }

    public function hasAttempt(): bool
    {
        return $this->store->read(self::ATTEMPT) !== null;
    }

    /**
     * @return array{issue:string,attempt_id:string,operation_id:string,acquired_at:string,recipe:array{workloads:list<string>}}
     */
    public function attempt(): array
    {
        $lease = $this->store->read(self::ATTEMPT) ?? throw new RuntimeException("{$this->issue} has no active attempt.");

        return $this->validatedAttempt($lease);
    }

    /**
     * @param  array<array-key, mixed>  $lease
     * @return array{issue:string,attempt_id:string,operation_id:string,acquired_at:string,recipe:array{workloads:list<string>}}
     */
    private function validatedAttempt(array $lease): array
    {
        if (
            array_keys($lease) !== ['issue', 'attempt_id', 'operation_id', 'acquired_at', 'recipe']
            || $lease['issue'] !== $this->issue
            || ! is_string($lease['attempt_id'])
            || preg_match('/\A[0-9a-f]{32}\z/D', $lease['attempt_id']) !== 1
            || ! is_string($lease['operation_id'])
            || preg_match('/\A[0-9a-f]{32}\z/D', $lease['operation_id']) !== 1
            || ! is_string($lease['acquired_at'])
            || ! is_array($lease['recipe'])
        ) {
            throw new RuntimeException(
                "The {$this->issue} attempt lease is invalid or from an older harness; release it with the harness that created it.",
            );
        }
        try {
            $recipe = TopologyRecipe::fromArray($lease['recipe']);
        } catch (InvalidArgumentException $exception) {
            throw new RuntimeException("The {$this->issue} attempt lease is invalid.", previous: $exception);
        }

        return [
            'issue' => $lease['issue'],
            'attempt_id' => $lease['attempt_id'],
            'operation_id' => $lease['operation_id'],
            'acquired_at' => $lease['acquired_at'],
            'recipe' => $recipe->toArray(),
        ];
    }

    public function attemptId(): AttemptId
    {
        return new AttemptId($this->attempt()['attempt_id']);
    }

    public function operationId(): OperationId
    {
        return new OperationId($this->attempt()['operation_id']);
    }

    public function recipe(): TopologyRecipe
    {
        return TopologyRecipe::fromArray($this->attempt()['recipe']);
    }

    public function releaseTarget(): TopologyTarget
    {
        return $this->topology()->target ?? TopologyTarget::for($this->issue, $this->attemptId(), $this->recipe());
    }

    public function writeAttempt(AttemptId $attempt, OperationId $operation, TopologyRecipe $recipe): void
    {
        $this->store->write(self::ATTEMPT, [
            'issue' => $this->issue,
            'attempt_id' => $attempt->value,
            'operation_id' => $operation->value,
            'acquired_at' => gmdate('Y-m-d\TH:i:s\Z'),
            'recipe' => $recipe->toArray(),
        ]);
    }

    public function topology(): ?TopologyRecord
    {
        if (! $this->hasAttempt()) {
            return null;
        }
        $value = $this->store->read(self::TOPOLOGY);
        if ($value === null) {
            return null;
        }
        $topology = TopologyRecord::fromArray($value);
        if ($topology->target->issue !== $this->issue) {
            throw new RuntimeException('The topology record belongs to another issue.');
        }
        $lease = $this->attempt();
        if ($topology->attempt->value !== $lease['attempt_id']) {
            throw new RuntimeException('The attempt lease and the topology record name different attempts.');
        }
        if ($topology->target->recipe->toArray() !== $lease['recipe']) {
            throw new RuntimeException('The attempt lease and the topology record declare different Nodes.');
        }

        return $topology;
    }

    /** The topology; its lease and record must name the same attempt. */
    public function requireTopology(): TopologyRecord
    {
        $this->attemptId();

        return $this->topology() ?? throw new RuntimeException(
            "{$this->issue} has an active lease but no topology record.",
        );
    }

    public function writeTopology(TopologyRecord $topology): void
    {
        $this->store->write(self::TOPOLOGY, $topology->toArray());
    }

    /**
     * A fresh random token in `.e2e/mount-token`. The guests hash the file they see through the mount,
     * so a matching hash proves they read this worktree. Returns the token's SHA-256.
     */
    public function writeMountToken(): string
    {
        $path = $this->paths->ensureParent(self::MOUNT_TOKEN);
        $token = bin2hex(random_bytes(32))."\n";
        $candidate = $path.'.'.getmypid();
        if (file_put_contents($candidate, $token) === false || ! chmod($candidate, 0644) || ! rename($candidate, $path)) {
            throw new RuntimeException('Unable to write the worktree mount token.');
        }

        return hash('sha256', $token);
    }

    /** The SHA-256 of the current mount token, or null when there is none. */
    public function mountTokenHash(): ?string
    {
        $path = $this->paths->root().'/'.self::MOUNT_TOKEN;
        if (is_link($path) || ! is_file($path)) {
            return null;
        }
        $token = file_get_contents($path);

        return is_string($token) && $token !== '' ? hash('sha256', $token) : null;
    }

    /** Drop the attempt lease and record; the log stays. */
    public function forgetAttempt(): void
    {
        $this->store->delete(self::TOPOLOGY);
        $this->store->delete(self::ATTEMPT);
    }

    public function log(string $line): void
    {
        $path = $this->paths->ensureParent('log');
        $entry = gmdate('Y-m-d\TH:i:s\Z').' '.str_replace(["\r", "\n"], ' ', $line)."\n";
        if (file_put_contents($path, $entry, FILE_APPEND | LOCK_EX) === false) {
            throw new RuntimeException('Unable to append to the issue log.');
        }
    }

    public function root(): string
    {
        return $this->paths->root();
    }
}
