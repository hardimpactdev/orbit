<?php

declare(strict_types=1);

namespace App\Domain\InstanceMigration;

use App\Domain\Instances\InstanceContainer;
use App\Domain\Instances\InstanceHostIntent;
use App\Domain\Operations\OperationStatus;
use App\Domain\Operations\OperationStore;
use App\Domain\Schedules\ScheduleOperationException;
use App\Domain\Schedules\ScheduleTargetResolver;
use App\Domain\Schedules\ScheduleTrigger;
use App\Domain\Shared\LifecycleStatus;
use App\Domain\Shared\ResourceOperationException;
use App\Infrastructure\TaskVms\RecipeImageBuilds;
use App\Models\Instance;
use App\Models\Operation;
use App\Models\Schedule;
use App\Support\ValidatedData;

/** The registered Node timers repointed by one move; their commands stay private until terminal projection. */
final readonly class MigrateSchedules
{
    /** Bare names the container runtime puts on the Schedule's PATH. */
    private const RuntimeNames = ['php', 'node', 'bun', 'vp', 'composer', 'git', 'true', 'false', 'echo', 'printf'];

    public function __construct(private ScheduleTargetResolver $targets, private RecipeImageBuilds $recipes) {}

    /** @return list<array<string,mixed>> */
    public function plans(Instance $instance): array
    {
        $plans = [];
        foreach (Schedule::query()->whereIn('target_type', Instance::morphTypes())->where('target_id', $instance->id)->orderBy('id')->lockForUpdate()->get() as $schedule) {
            if ($schedule->trigger !== ScheduleTrigger::Node) {
                $this->refuse($schedule, 'trigger_unsupported', 'its native workflow has fixed execution context; only registered Node timers move');
            }
            if ($schedule->status !== LifecycleStatus::Active || $schedule->host_node_id !== $instance->node_id
                || Operation::query()->where('target_type', 'instance_schedules')->where('target_id', $instance->id)
                    ->whereNotIn('status', OperationStatus::terminalValues())->exists()) {
                $this->refuse($schedule, 'state_unsupported', 'its timer is not active on this Node or a Schedule operation is unfinished');
            }
            $directory = $instance->source_is_laravel === true ? $instance->applicationDirectory() : $instance->checkout_path;
            if (! $this->inside($directory, $instance->checkout_path)) {
                $this->refuse($schedule, 'path_unsupported', 'its working directory must stay inside the checkout without dot segments');
            }
            try {
                $target = $this->targets->forInspection($schedule);
            } catch (ResourceOperationException|ScheduleOperationException) {
                $this->refuse($schedule, 'account_unsupported', 'its recorded runtime account is unavailable');
            }
            $directory = $target->workingDirectory;
            if (! is_string($directory) || ! $this->inside($directory, $instance->checkout_path)) {
                $this->refuse($schedule, 'path_unsupported', 'its working directory must stay inside the checkout without dot segments');
            }
            $compiled = $this->command($schedule, $instance, $directory);
            $script = "# X-Orbit-Schedule-ID={$schedule->id}\n# X-Orbit-Execution-Context=".json_encode([
                'user' => $target->user, 'group' => $target->group, 'home' => $target->home,
                'working_directory' => $directory, 'shell' => $target->shell, 'login_shell' => $target->loginShell,
            ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)."\n".$schedule->command."\n";
            $plans[] = [
                'id' => $schedule->id, 'name' => $schedule->name,
                'service' => 'orbit-schedule-'.$schedule->id.'.service', 'timer' => 'orbit-schedule-'.$schedule->id.'.timer',
                'user' => $target->user, 'group' => $target->group, 'home' => $target->home, 'shell' => $target->shell, 'login_shell' => $target->loginShell,
                'source_directory' => $directory, 'directory' => InstanceHostIntent::Workspace.substr($directory, strlen(rtrim($instance->checkout_path, '/'))),
                'container_user' => 'orbit', 'container_home' => '/home/orbit', 'container_shell' => '/bin/bash',
                'command_hash' => hash('sha256', $schedule->command), 'mapped_command_hash' => hash('sha256', $compiled['command']),
                'source_script_hash' => hash('sha256', $script), 'source_command_paths' => $compiled['paths'],
                'environment_file' => InstanceHostIntent::Workspace.substr($directory, strlen(rtrim($instance->checkout_path, '/'))).'/.env',
                'path' => InstanceContainer::path($instance->project),
                'calendar' => $schedule->calendar, 'timeout_seconds' => $schedule->timeout_seconds, 'desired' => $schedule->desired_timer_state->value,
            ];
        }

        return $plans;
    }

    /** Only the move that crossed cutover changes the Schedule's command. No identity, calendar, state or history changes.
     * @param  list<array<string,mixed>>  $admitted
     */
    public function complete(Instance $instance, array $admitted, ?int $migrationId = null): void
    {
        foreach ($admitted as $plan) {
            $schedule = Schedule::query()->whereIn('target_type', Instance::morphTypes())->where('target_id', $instance->id)
                ->whereKey(ValidatedData::string($plan['id']))->lockForUpdate()->firstOrFail();
            if (! hash_equals(ValidatedData::string($plan['command_hash']), hash('sha256', $schedule->command))) {
                throw new ResourceOperationException('operation.execution_forbidden', 'The admitted Schedule command changed during migration.', 409);
            }
            $schedule->update([...(($plan['path'] ?? null) === null ? [] : ['trigger' => ScheduleTrigger::Container]), 'command' => $this->command($schedule, $instance, ValidatedData::string($plan['source_directory']))['command']]);
            if ($schedule->trigger === ScheduleTrigger::Container && $migrationId !== null) {
                foreach (Operation::query()->where('kind', 'schedule.run')->where('target_type', 'instance_schedules')
                    ->where('target_id', $instance->id)->where('status', OperationStatus::Waiting)
                    ->where('input->id', $schedule->id)->where('input->deferred_instance_migration', $migrationId)
                    ->lockForUpdate()->get() as $fire) {
                    app(OperationStore::class)->change($fire, [
                        'status' => OperationStatus::Succeeded, 'finished_at' => now(),
                        'result' => ['skipped' => true, 'reason' => 'container_timer_missed_during_migration'],
                    ]);
                }
            }
        }
    }

    /** Private command copies, revalidated against the frozen plans before dispatch.
     * @param  list<array<string,mixed>>  $admitted
     */
    public function commands(Instance $instance, array $admitted): string
    {
        $commands = [];
        foreach ($admitted as $plan) {
            $schedule = Schedule::query()->whereIn('target_type', Instance::morphTypes())
                ->where('target_id', $instance->id)->whereKey($plan['id'])->firstOrFail();
            $mapped = $this->command($schedule, $instance, ValidatedData::string($plan['source_directory']))['command'];
            if (! hash_equals(ValidatedData::string($plan['command_hash']), hash('sha256', $schedule->command))
                || ! hash_equals(ValidatedData::string($plan['mapped_command_hash']), hash('sha256', $mapped))) {
                throw new ResourceOperationException('operation.execution_forbidden', 'The admitted Schedule changed before dispatch.', 403);
            }
            $commands[$schedule->id] = $mapped;
        }

        return json_encode((object) $commands, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
    }

    private function inside(string $path, string $checkout): bool
    {
        $checkout = rtrim($checkout, '/');

        return ($path === $checkout || str_starts_with($path, $checkout.'/'))
            && preg_match('~[\x00-\x1f]|(?:^|/)(?:\.|\.\.)(?:/|$)|//~', $path) !== 1;
    }

    /** Compile one literal invocation, with an optional null-output redirect. Never interpret shell programs.
     * @return array{command:string,paths:list<string>}
     */
    private function command(Schedule $schedule, Instance $instance, string $directory): array
    {
        $command = $this->runtimeName($schedule, $instance, $schedule->command);
        $checkout = $instance->checkout_path;
        $invocation = preg_replace('~\s+>>?\s*/dev/null(?:\s+2>&1)?\s*$~', '', $command) ?? $command;
        if (preg_match('~[$`\\\\]|(?:^|[\s"\x27=])\~|(?:^|[\s"\x27=/])\.\.?(?:/|[\s"\x27|;&()<>]|$)~', $invocation) === 1) {
            $this->refuse($schedule, 'command_unsupported', 'its command uses shell expansion, escaping or a dot-segment path that cannot be mapped literally');
        }
        preg_match_all('~(?<![a-zA-Z0-9_/])/(?:[^\s"\x27`|;&()<>]*)~', $invocation, $matches);
        foreach ($matches[0] as $path) {
            if (! $this->inside($path, $checkout)) {
                $this->refuse($schedule, 'command_unsupported', 'its command references an absolute path outside the checkout');
            }
        }
        if (preg_match('~[\x00-\x1f;|&()<>*?\[\]{}]~', $invocation) === 1) {
            $this->refuse($schedule, 'command_unsupported', 'its command must be one literal invocation without shell operators or patterns');
        }
        $tokens = [];
        $offset = 0;
        while ($offset < strlen($invocation)) {
            if (trim(substr($invocation, $offset)) === '') {
                break;
            }
            if (preg_match('~\G\s*(?:\x27([^\x27]*)\x27|"([^"]*)"|([^\s\x27"]+))(?=\s|$)~', $invocation, $token, PREG_UNMATCHED_AS_NULL, $offset) !== 1) {
                $this->refuse($schedule, 'command_unsupported', 'its command arguments must be literal words or whole quoted strings');
            }
            if (str_starts_with($token[3] ?? '', '=')) {
                $this->refuse($schedule, 'command_unsupported', 'its command has an unquoted word that starts with "=", which zsh expands to a command path; quote that word');
            }
            $offset += strlen($token[0]);
            $tokens[] = ValidatedData::string($token[1] ?? $token[2] ?? $token[3]);
        }
        $executable = $tokens[0] ?? '';
        if (preg_match('/\A[A-Za-z_][A-Za-z0-9_]*=/', $executable) === 1) {
            $this->refuse($schedule, 'command_unsupported', 'its command uses an assignment prefix rather than one executable');
        }
        if (! str_contains($executable, '/') && ! in_array($executable, self::RuntimeNames, true)) {
            $this->refuse($schedule, 'command_unsupported', 'its bare executable is not supplied by the container runtime');
        }
        if (in_array($executable, ['php', 'composer'], true)) {
            $version = $instance->project->php_version;
            if (! is_string($version) || ($this->recipes->currentRecipe()->instances?->php[$version]->artifact ?? null) === null) {
                $this->refuse($schedule, 'command_unsupported', 'its PHP invocation needs a selected PHP version available in the container runtime');
            }
        }
        foreach (array_slice($tokens, 1) as $argument) {
            if (in_array($argument, ['-r', '-B', '-R', '-F', '-E', '-e', '-p', '-c', '--eval', '--print', '--run', '--process-begin', '--process-code', '--process-file', '--process-end'], true)
                || preg_match('~^(?:--(?:eval|print|run|process-begin|process-code|process-file|process-end)=|-[rBREFepc].+)~', $argument) === 1) {
                $this->refuse($schedule, 'command_unsupported', 'its command uses inline evaluation or executable configuration');
            }
        }
        $scriptIndex = null;
        if (in_array($executable, ['php', 'node', 'bun'], true)) {
            $scriptIndex = ($tokens[1] ?? null) === '--' ? 2 : 1;
            $script = $tokens[$scriptIndex] ?? '';
            if ($script === '' || str_starts_with($script, '-') || str_contains($script, '=')
                || ($executable === 'bun' && ! str_contains($script, '/'))) {
                $this->refuse($schedule, 'command_unsupported', 'its interpreter needs a direct script operand; options and ambiguous subcommands cannot be mapped');
            }
        }
        $paths = [];
        foreach ($tokens as $index => $token) {
            $value = $index !== 0 && str_contains($token, '=') ? substr($token, strpos($token, '=') + 1) : $token;
            if (str_contains($value, '/') && preg_match('~[:,=]~', $value) === 1) {
                $this->refuse($schedule, 'command_unsupported', 'its command path must be one literal workspace path');
            }
            if (str_starts_with($value, '/')) {
                $paths[] = $value;
            } elseif ($index === $scriptIndex || str_contains($value, '/') || $value === 'artisan' || preg_match('~^[^\s:]+\.(?:php|[cm]?js|ts|sh|py)$~', $value) === 1) {
                if (str_contains($value, ':') || ! $this->inside($directory.'/'.$value, $checkout)) {
                    $this->refuse($schedule, 'command_unsupported', 'its command path cannot be resolved inside the checkout');
                }
                $paths[] = $directory.'/'.$value;
            }
        }
        $root = preg_quote(rtrim($checkout, '/'), '~');

        return ['command' => preg_replace('~(?<![a-zA-Z0-9_:/])'.$root.'(?=/|[\s"\x27|;&()<>]|$)~', InstanceHostIntent::Workspace, $command) ?? $command,
            'paths' => array_values(array_unique($paths))];
    }

    /** The host account's shell never runs in the container, so the command must not depend on it. A host install path of a
     * runtime name becomes that bare name, which the container PATH supplies; `phpX.Y` must match the Project's PHP version.
     */
    private function runtimeName(Schedule $schedule, Instance $instance, string $command): string
    {
        if (preg_match('~^(\s*)/(?:usr/local/bin|usr/bin|bin)/([^\s/"\x27]+)(?=\s|$)~', $command, $match) !== 1) {
            return $command;
        }
        $name = $match[2];
        if (preg_match('/^php([0-9]+[.][0-9]+)$/', $name, $php) === 1) {
            $selected = $instance->project->php_version;
            if ($php[1] !== $selected) {
                $this->refuse($schedule, 'command_unsupported', "its command runs host PHP [{$php[1]}], but the container runs the Project's PHP ["
                    .($selected ?? 'none')."]; set the Project's PHP version to {$php[1]}, or start the command with `php` instead of `".trim($match[0]).'`');
            }
            $name = 'php';
        }

        return in_array($name, self::RuntimeNames, true) ? $match[1].$name.substr($command, strlen($match[0])) : $command;
    }

    private function refuse(Schedule $schedule, string $reason, string $message): never
    {
        throw new ResourceOperationException('schedule.container_'.$reason, "Schedule [{$schedule->id}:{$schedule->name}] cannot move into the container: {$message}.", 409);
    }
}
