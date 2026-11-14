<?php

declare(strict_types=1);

namespace App\Infrastructure\Instances;

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
use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Throwable;

/**
 * The read-only Git state of a container workspace, beyond its recorded baseline
 * (docs/reference/development-containers.md#workspace-work-state). One bounded SSH call to the Instance's Node runs the
 * fixed reader, as the container Process read does (docs/reference/node-agent.md#commands).
 */
final readonly class ContainerWorkspaceRead
{
    public const array Reasons = ['stopped', 'asleep', 'timeout', 'not_a_repository', 'unreachable'];

    /** The reader's own budget on the Node; SSH adds its connect time inside the 4 s command bound. */
    public const float BudgetSeconds = 2.0;

    /** A read answers in a few KiB; a record carries the untracked set, up to 2,000 names. */
    private const int ReadBytes = 65536;

    private const int RecordBytes = 1_048_576;

    public function __construct(private SshExecutor $ssh, private SshKeyProvider $keys, private KnownHostsStore $hosts) {}

    /**
     * `$published`: commits origin holds that no remote ref in the workspace names, such as the commit a task copy's publish pushed.
     * They and their history do not count as unpushed.
     *
     * @param  list<string>  $published
     * @return array{instance_id: int, state: string, reason: ?string, read_at: string, duration_ms: int, baseline: ?string,
     *     head: ?string, uncommitted: ?int, unpushed: ?int, modified: ?array<string, mixed>, untracked: ?array<string, mixed>,
     *     commits: ?array<string, mixed>, stashes: ?array<string, mixed>}
     */
    public function read(Instance $instance, array $published = []): array
    {
        $started = hrtime(true);
        $runtime = $this->runtime($instance);
        $answer = null;
        if ($runtime !== null) {
            $arguments = ['read', $runtime->incus_project, $runtime->container_name, RuntimeHibernation::asleepPath(RuntimeHibernation::key($instance->id))];
            $baseline = $runtime->workspace_baseline;
            if ($baseline === null && $instance->name !== DefaultInstanceName::Name && ($default = $this->defaultRuntime($instance)) !== null) {
                array_push($arguments, $default->incus_project, $default->container_name);
            }
            $answer = $this->decode($this->execute($instance, $arguments, $instance->name === DefaultInstanceName::Name ? null : $baseline, published: $published));
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
        $answer = $this->decode($this->execute($instance, ['record', $runtime->incus_project, $runtime->container_name], null, self::RecordBytes));
        if ($answer === null || ! is_array($answer['heads'] ?? null)) {
            return null;
        }
        $oids = static fn (mixed $values): array => array_values(array_filter(is_array($values) ? $values : [],
            static fn (mixed $oid): bool => is_string($oid) && preg_match('/^(?:[0-9a-f]{40}|[0-9a-f]{64})$/', $oid) === 1));
        $untracked = $answer['untracked'] ?? null;

        return ['heads' => $oids($answer['heads']), 'stashes' => $oids($answer['stashes'] ?? []),
            'untracked' => is_array($untracked) ? array_values(array_filter($untracked, is_string(...))) : null];
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
    private function execute(Instance $instance, array $arguments, ?array $baseline, int $bytes = self::ReadBytes, array $published = []): CommandResult|string|null
    {
        $node = $instance->node;
        if (! is_string($node->wireguard_ip) || $node->wireguard_ip === '') {
            return null;
        }
        // Base64 keeps every byte of a stored name intact on its way into the reader's Python source.
        $literal = static fn (mixed $value): string => 'json.loads(base64.b64decode("'.base64_encode(json_encode($value, JSON_THROW_ON_ERROR)).'"))';
        $prelude = "import base64\nimport json\nINSTANCE_PROJECTS = ".$literal(InstancePlacement::projects())."\nBASELINE = ".$literal($baseline)
            ."\nPUBLISHED = ".$literal($published)."\nBUDGET = ".self::BudgetSeconds."\n";
        try {
            return $this->ssh->execute(new SshConnection(
                host: $node->wireguard_ip, user: $node->user, port: 22,
                identityFile: $this->keys->privateKeyPath(), knownHostsFile: $this->hosts->path(),
                connectTimeout: 2, commandTimeout: 4,
            ), new RemoteCommand(
                ['timeout', '3', 'python3', '-', ...$arguments],
                input: $prelude.File::get(resource_path('instances/workspace-git-read.py')),
                maxOutputBytes: $bytes, timeout: 4,
            ));
        } catch (Throwable $error) {
            return $error instanceof ProcessTimedOutException || $error->getPrevious() instanceof ProcessTimedOutException ? 'timeout' : null;
        }
    }

    /**
     * An answer of the reader, an unknown answer with `timeout` when the SSH call or the outer bound (exit 124) ran out,
     * or null for every other failure.
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
     *     head: ?string, uncommitted: ?int, unpushed: ?int, modified: ?array<string, mixed>, untracked: ?array<string, mixed>,
     *     commits: ?array<string, mixed>, stashes: ?array<string, mixed>}
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
                'read_at' => $at, 'duration_ms' => $milliseconds, 'baseline' => null, 'head' => null, 'uncommitted' => null,
                'unpushed' => null, 'modified' => null, 'untracked' => null, 'commits' => null, 'stashes' => null];
        }
        $uncommitted = $classes['modified']['count'] + $classes['untracked']['count'];
        $dirty = $uncommitted + $commits['count'] + $classes['stashes']['count'] > 0;

        return ['instance_id' => $instanceId, 'state' => $dirty ? 'dirty' : 'clean', 'reason' => null, 'read_at' => $at,
            'duration_ms' => $milliseconds, 'baseline' => $answer['baseline'],
            'head' => is_string($answer['head'] ?? null) ? $answer['head'] : null,
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

    /** @return array{count: int, branch_count: int, branches: list<array{name: string, count: int}>}|null */
    private static function commits(mixed $class): ?array
    {
        if (! is_array($class) || ! is_int($class['count'] ?? null) || ! is_int($class['branch_count'] ?? null) || ! is_array($class['branches'] ?? null)) {
            return null;
        }
        $branches = [];
        foreach (array_slice($class['branches'], 0, 5) as $branch) {
            if (is_array($branch) && is_string($branch['name'] ?? null) && is_int($branch['count'] ?? null)) {
                $branches[] = ['name' => $branch['name'], 'count' => $branch['count']];
            }
        }

        return ['count' => $class['count'], 'branch_count' => $class['branch_count'], 'branches' => $branches];
    }
}
