<?php

declare(strict_types=1);

namespace App\Infrastructure\Instances;

use App\Domain\Engine\NodeReadBus;
use App\Domain\Executor\ExecutorOrderSigner;
use App\Domain\Hibernation\RuntimeHibernation;
use App\Domain\Instances\DefaultInstanceName;
use App\Domain\Instances\InstanceContainer;
use App\Domain\Instances\InstancePlacement;
use App\Domain\Shared\LifecycleStatus;
use App\Infrastructure\Processes\CommandResult;
use App\Infrastructure\Ssh\KnownHostsStore;
use App\Infrastructure\Ssh\RemoteCommand;
use App\Infrastructure\Ssh\SshConnection;
use App\Infrastructure\Ssh\SshExecutor;
use App\Infrastructure\Ssh\SshKeyProvider;
use App\Models\Instance;
use App\Models\InstanceRuntime;
use App\Support\ValidatedData;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Sleep;
use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Throwable;

/**
 * The read-only Git state of a container workspace, beyond its recorded baseline
 * (docs/reference/development-containers.md#workspace-work-state). A Node whose executor reports `instance.work_read` answers a
 * signed read over the machine bus (docs/reference/executor.md#signed-reads); any other Node gets one bounded SSH call that runs
 * the fixed reader, as the container Process read does (docs/reference/node-agent.md#commands). Both give the same answer.
 */
final readonly class ContainerWorkspaceRead
{
    public const array Reasons = ['stopped', 'asleep', 'timeout', 'not_a_repository', 'unreachable'];

    /** The normal read's budget on the Node; SSH adds its connect time inside the 4 s command bound. */
    public const float BudgetSeconds = 2.0;

    /** A read answers in a few KiB; a record carries the untracked set, up to 2,000 names. */
    private const int ReadBytes = 65536;

    private const int RecordBytes = 1_048_576;

    /**
     * The baseline read's bounds: the reader's budget, the outer `timeout` and the SSH command bound. A clone's create fails when its
     * baseline cannot be read, and that read runs while the host may be under load, where a read took up to 2.2 s: the normal read's
     * 2 s budget is too short for it. With its one retry, a stuck read ends in about 21 s (docs/reference/development-containers.md#workspace-work-state).
     */
    public const float BaselineBudgetSeconds = 6.0;

    public const int BaselineTimeoutSeconds = 8;

    public const int BaselineCommandSeconds = 10;

    /**
     * The pause before the second and last baseline read. Horizon reads it on a connection of its own: one lost connect, such as a
     * dropped SYN on the WireGuard path, must not fail the create.
     */
    public const array BaselineRetrySeconds = [1];

    /** The executor's read action; a Node that does not report it is read over SSH. */
    public const string ReadAction = 'instance.work_read';

    /** A signed read's whole budget inside the request, as the SSH read's 4 s command bound. */
    public const float RequestSeconds = 4.0;

    /** Time a reply needs to come back after the Node's budget ends. */
    private const float ReplySeconds = 0.5;

    /** A lost reply is read once more: the first send and one fresh send. */
    private const int Sends = 2;

    /** The most of the budget the Node's capability report may take. */
    private const float LookupSeconds = 1.0;

    public function __construct(private SshExecutor $ssh, private SshKeyProvider $keys, private KnownHostsStore $hosts,
        private NodeReadBus $reads) {}

    /**
     * `$published`: commits origin holds that no remote ref in the workspace names, such as the commit a task copy's publish pushed.
     * They and their history do not count as unpushed.
     *
     * @param  list<string>  $published
     * @return array{instance_id: int, state: string, reason: ?string, read_at: string, duration_ms: int, baseline: ?string,
     *     head: ?string, head_commit: ?string, uncommitted: ?int, unpushed: ?int, modified: ?array<string, mixed>, untracked: ?array<string, mixed>,
     *     commits: ?array{count: int, branch_count: int, branches: list<array{name: string, count: int}>, head: int}, stashes: ?array<string, mixed>}
     */
    public function read(Instance $instance, array $published = []): array
    {
        $started = hrtime(true);
        $runtime = $this->runtime($instance);
        $answer = null;
        if ($runtime !== null) {
            $baseline = $runtime->workspace_baseline;
            $default = $baseline === null && $instance->name !== DefaultInstanceName::Name ? $this->defaultRuntime($instance) : null;
            $baseline = $instance->name === DefaultInstanceName::Name ? null : $baseline;
            // The signed read's whole request budget counts from here: the capability lookup is part of it.
            $deadline = $started / 1e9 + self::RequestSeconds;
            if (in_array(self::ReadAction, $this->reads->actions($instance->node_id, min($deadline, hrtime(true) / 1e9 + self::LookupSeconds)), true)) {
                $answer = $this->signed($instance, $runtime, $default, $baseline, $published, $deadline);
            } else {
                $arguments = ['read', $runtime->incus_project, $runtime->container_name, RuntimeHibernation::asleepPath(RuntimeHibernation::key($instance->id))];
                if ($default !== null) {
                    array_push($arguments, $default->incus_project, $default->container_name);
                }
                $answer = $this->decode($this->execute($instance, $arguments, $baseline, published: $published));
            }
        }

        return self::shape($instance->id, $answer, intdiv(hrtime(true) - $started, 1_000_000));
    }

    /**
     * The baseline a container create or clone records: local branch heads, stash ids and the untracked set (null above
     * 2,000 entries). Null when the workspace cannot be read.
     *
     * @return array{heads: list<string>, stashes: list<string>, untracked: list<string>|null}|null
     */
    public function baseline(Instance $instance): ?array
    {
        $runtime = $this->runtime($instance);
        if ($runtime === null) {
            return null;
        }
        $answer = null;
        foreach ([0, ...self::BaselineRetrySeconds] as $try => $pause) {
            if ($pause > 0) {
                Sleep::for($pause)->seconds();
            }
            $result = $this->execute($instance, ['record', $runtime->incus_project, $runtime->container_name], null, self::RecordBytes,
                budget: self::BaselineBudgetSeconds, timeout: self::BaselineTimeoutSeconds, command: self::BaselineCommandSeconds);
            $answer = $this->decode($result);
            if ($answer !== null && is_array($answer['heads'] ?? null)) {
                break;
            }
            Log::warning('A workspace baseline read failed.', ['instance_id' => $instance->id, 'try' => $try + 1,
                'tries' => count(self::BaselineRetrySeconds) + 1, ...self::failure($result), 'reason' => $answer['reason'] ?? null]);
            $answer = null;
        }
        if ($answer === null) {
            return null;
        }
        $oids = static fn (mixed $values): array => array_values(array_filter(is_array($values) ? $values : [],
            static fn (mixed $oid): bool => is_string($oid) && preg_match('/^(?:[0-9a-f]{40}|[0-9a-f]{64})$/', $oid) === 1));
        $untracked = $answer['untracked'] ?? null;

        return ['heads' => $oids($answer['heads']), 'stashes' => $oids($answer['stashes'] ?? []),
            'untracked' => is_array($untracked) ? array_values(array_filter($untracked, is_string(...))) : null];
    }

    /**
     * The signed read: one order to the Node's executor, and its reply by `$deadline` (`hrtime` seconds, the request's budget).
     * A reply that is lost, also by a connection that closed after the order went out, is read once more with a fresh nonce
     * when the budget allows it; a reply still missing at its end is `timeout`, and no reply is taken after it. The read changes
     * nothing, so a second send is harmless. Null, which reads as `unreachable`, when no send reached the bus or a line is not
     * this read's answer.
     *
     * @param  array<string, mixed>|null  $baseline
     * @param  list<string>  $published
     * @return array<string, mixed>|null
     */
    private function signed(Instance $instance, InstanceRuntime $runtime, ?InstanceRuntime $default, ?array $baseline, array $published, float $deadline): ?array
    {
        $node = $instance->node_id;
        $input = ['project' => $runtime->incus_project, 'name' => $runtime->container_name,
            ...($default === null ? [] : ['default' => ['project' => $default->incus_project, 'name' => $default->container_name]])];
        $now = static fn (): float => hrtime(true) / 1e9;
        $failed = 0;
        try {
            $signer = ExecutorOrderSigner::fromConfig();
            if ($baseline !== null || $published !== []) {
                // A baseline may name up to 2,000 untracked files: the order names it by its hash.
                $blob = $this->reads->content($node, json_encode(['baseline' => $baseline, 'published' => $published],
                    JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES), $deadline);
                if ($blob === null) {
                    // The content went out and its acknowledgement is missing at the deadline: a lost answer, not a failure to send.
                    return ['state' => 'unknown', 'reason' => 'timeout'];
                }
                $input['state'] = ['blob' => $blob];
            }
        } catch (Throwable $error) {
            Log::warning('A signed workspace read could not start.', ['instance_id' => $instance->id, 'error' => $error::class.': '.mb_strcut($error->getMessage(), 0, 200)]);

            return null;
        }
        $sends = 0;
        while ($sends < self::Sends && ($left = $deadline - $now()) > self::ReplySeconds) {
            $sends++;
            $budget = min(self::BudgetSeconds, $left - self::ReplySeconds);
            try {
                $order = $signer->signRead($node, self::ReadAction, (string) $instance->id, [...$input, 'budget_ms' => max(1, (int) floor($budget * 1000))],
                    (int) ceil($left * 1000));
                $line = $this->reads->read($node, $order, min($deadline, $now() + $budget + self::ReplySeconds));
            } catch (Throwable $error) {
                // The connection may have closed after the order went out: a lost reply, which the next send reads again.
                Log::warning('A signed workspace read lost its connection.', ['instance_id' => $instance->id, 'send' => $sends,
                    'error' => $error::class.': '.mb_strcut($error->getMessage(), 0, 200)]);
                $failed++;

                continue;
            }
            if ($line !== null) {
                return self::answer($line, $order->operation, (string) $instance->id);
            }
        }

        return $sends > 0 && $failed === $sends ? null : ['state' => 'unknown', 'reason' => 'timeout'];
    }

    /**
     * The reader's answer from an executor's result line for this read. An order that expired, whether the Node found it expired
     * on arrival (`refused` `expired`) or before it ran (`executor.order_expired`), is `timeout`; any other line is null.
     *
     * @param  array<string, mixed>  $line
     * @return array<string, mixed>|null
     */
    private static function answer(array $line, string $operation, string $target): ?array
    {
        if (($line['operation'] ?? null) !== $operation || ($line['target'] ?? null) !== $target || ($line['action'] ?? null) !== self::ReadAction) {
            return null;
        }
        $status = $line['status'] ?? null;
        if (($status === 'failed' && ($line['code'] ?? null) === 'executor.order_expired') || ($status === 'refused' && ($line['reason'] ?? null) === 'expired')) {
            return ['state' => 'unknown', 'reason' => 'timeout'];
        }
        if ($status !== 'output' || ! is_string($line['stdout'] ?? null)) {
            return null;
        }
        try {
            return ValidatedData::object(json_decode($line['stdout'], true, 16, JSON_THROW_ON_ERROR));
        } catch (Throwable) {
            return null;
        }
    }

    private function runtime(Instance $instance): ?InstanceRuntime
    {
        $runtime = $instance->runtime()->first();
        if ($runtime === null || $instance->node->status !== LifecycleStatus::Active
            || ! in_array($runtime->incus_project, InstancePlacement::projects(), true)
            || $runtime->container_name !== InstanceContainer::name($instance)) {
            return null;
        }

        return $runtime;
    }

    /** The Project default's container on the same Node: the fall-back baseline of an Instance that has none. */
    private function defaultRuntime(Instance $instance): ?InstanceRuntime
    {
        $default = Instance::query()->where('project_id', $instance->project_id)->where('node_id', $instance->node_id)
            ->where('name', DefaultInstanceName::Name)->first();

        return $default === null ? null : $this->runtime($default);
    }

    /**
     * @param  list<string>  $arguments
     * @param  array<string, mixed>|null  $baseline
     * @param  list<string>  $published
     */
    private function execute(Instance $instance, array $arguments, ?array $baseline, int $bytes = self::ReadBytes, array $published = [],
        float $budget = self::BudgetSeconds, int $timeout = 3, int $command = 4): CommandResult|string|null
    {
        $node = $instance->node;
        if (! is_string($node->wireguard_ip) || $node->wireguard_ip === '') {
            return null;
        }
        // Base64 keeps every byte of a stored name intact on its way into the reader's Python source.
        $literal = static fn (mixed $value): string => 'json.loads(base64.b64decode("'.base64_encode(json_encode($value, JSON_THROW_ON_ERROR)).'"))';
        $prelude = "import base64\nimport json\nINSTANCE_PROJECTS = ".$literal(InstancePlacement::projects())."\nBASELINE = ".$literal($baseline)
            ."\nPUBLISHED = ".$literal($published)."\nBUDGET = ".$budget."\n";
        try {
            return $this->ssh->execute(new SshConnection(
                host: $node->wireguard_ip, user: $node->user, port: 22,
                identityFile: $this->keys->privateKeyPath(), knownHostsFile: $this->hosts->path(),
                connectTimeout: 2, commandTimeout: $command,
            ), new RemoteCommand(
                ['timeout', (string) $timeout, 'python3', '-', ...$arguments],
                input: $prelude.File::get(resource_path('instances/workspace-git-read.py')),
                maxOutputBytes: $bytes, timeout: $command,
            ));
        } catch (Throwable $error) {
            return $error instanceof ProcessTimedOutException || $error->getPrevious() instanceof ProcessTimedOutException
                ? 'timeout' : $error::class.': '.mb_strcut($error->getMessage(), 0, 500);
        }
    }

    /**
     * What a failed read's SSH call returned, for the log: its exit code and the end of its stderr, `timeout`, or the error it threw.
     *
     * @return array<string, mixed>
     */
    private static function failure(CommandResult|string|null $result): array
    {
        return match (true) {
            $result instanceof CommandResult => ['exit_code' => $result->exitCode, 'stderr' => mb_strcut(trim($result->stderr), -500),
                'stdout_bytes' => strlen($result->stdout)],
            default => ['error' => $result ?? 'no connection'],
        };
    }

    /**
     * An answer of the reader, an unknown answer with `timeout` when the SSH call or the outer bound (exit 124) ran out,
     * or null for every other failure, such as the error text of a call that threw.
     *
     * @return array<string, mixed>|null
     */
    private function decode(CommandResult|string|null $result): ?array
    {
        if ($result === 'timeout' || ($result instanceof CommandResult && $result->exitCode === 124)) {
            return ['state' => 'unknown', 'reason' => 'timeout'];
        }
        if (! $result instanceof CommandResult || ! $result->succeeded()) {
            return null;
        }
        try {
            return ValidatedData::object(json_decode($result->stdout, true, 16, JSON_THROW_ON_ERROR));
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * @param  array<string, mixed>|null  $answer
     * @return array{instance_id: int, state: string, reason: ?string, read_at: string, duration_ms: int, baseline: ?string,
     *     head: ?string, head_commit: ?string, uncommitted: ?int, unpushed: ?int, modified: ?array<string, mixed>, untracked: ?array<string, mixed>,
     *     commits: ?array{count: int, branch_count: int, branches: list<array{name: string, count: int}>, head: int}, stashes: ?array<string, mixed>}
     */
    private static function shape(int $instanceId, ?array $answer, int $milliseconds): array
    {
        $at = now()->toIso8601ZuluString();
        $state = $answer['state'] ?? null;
        $classes = [];
        foreach (['modified', 'untracked', 'stashes'] as $class) {
            $classes[$class] = self::listed($answer[$class] ?? null);
        }
        $commits = self::commits($answer['commits'] ?? null);
        if (! in_array($state, ['clean', 'dirty'], true) || in_array(null, $classes, true) || $commits === null
            || ! in_array($answer['baseline'] ?? null, ['recorded', 'default_now', 'none'], true)) {
            $reason = $answer['reason'] ?? null;

            return ['instance_id' => $instanceId, 'state' => 'unknown',
                'reason' => $state === 'unknown' && in_array($reason, self::Reasons, true) ? $reason : 'unreachable',
                'read_at' => $at, 'duration_ms' => $milliseconds, 'baseline' => null, 'head' => null, 'head_commit' => null, 'uncommitted' => null,
                'unpushed' => null, 'modified' => null, 'untracked' => null, 'commits' => null, 'stashes' => null];
        }
        $uncommitted = $classes['modified']['count'] + $classes['untracked']['count'];
        $dirty = $uncommitted + $commits['count'] + $classes['stashes']['count'] > 0;

        return ['instance_id' => $instanceId, 'state' => $dirty ? 'dirty' : 'clean', 'reason' => null, 'read_at' => $at,
            'duration_ms' => $milliseconds, 'baseline' => $answer['baseline'],
            'head' => is_string($answer['head'] ?? null) ? $answer['head'] : null,
            'head_commit' => is_string($answer['head_commit'] ?? null) && preg_match('/\A(?:[0-9a-f]{40}|[0-9a-f]{64})\z/', $answer['head_commit']) === 1 ? $answer['head_commit'] : null,
            'uncommitted' => $uncommitted, 'unpushed' => $commits['count'],
            'modified' => $classes['modified'], 'untracked' => $classes['untracked'], 'commits' => $commits, 'stashes' => $classes['stashes']];
    }

    /** @return array{count: int, names: list<string>}|null */
    private static function listed(mixed $class): ?array
    {
        if (! is_array($class) || ! is_int($class['count'] ?? null) || $class['count'] < 0 || ! is_array($class['names'] ?? null)) {
            return null;
        }
        $names = array_values(array_filter($class['names'], is_string(...)));

        return ['count' => $class['count'], 'names' => array_slice($names, 0, 5)];
    }

    /**
     * `head`: how many of the counted commits the checked-out branch holds; an answer without it holds none, so none is cleared.
     *
     * @return array{count: int, branch_count: int, branches: list<array{name: string, count: int}>, head: int}|null
     */
    private static function commits(mixed $class): ?array
    {
        if (! is_array($class) || ! is_int($class['count'] ?? null) || ! is_int($class['branch_count'] ?? null) || ! is_array($class['branches'] ?? null)) {
            return null;
        }
        $head = $class['head'] ?? 0;
        $head = is_int($head) && $head >= 0 && $head <= $class['count'] ? $head : 0;
        $branches = [];
        foreach (array_slice($class['branches'], 0, 5) as $branch) {
            if (is_array($branch) && is_string($branch['name'] ?? null) && is_int($branch['count'] ?? null)) {
                $branches[] = ['name' => $branch['name'], 'count' => $branch['count']];
            }
        }

        return ['count' => $class['count'], 'branch_count' => $class['branch_count'], 'branches' => $branches, 'head' => $head];
    }
}
