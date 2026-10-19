<?php

declare(strict_types=1);

namespace App\Infrastructure\TaskVms;

use App\Domain\Shared\ResourceOperationException;
use App\Domain\TaskVms\TaskVmError;
use App\Domain\TaskVms\TaskVmException;
use App\Infrastructure\Activity\CommandActivityInputSanitizer;
use App\Infrastructure\MachineSoftware\MachineRecipe;
use App\Infrastructure\MachineSoftware\PhpBuildInstaller;
use App\Infrastructure\MachineSoftware\RecipeProgramInput;
use App\Infrastructure\MachineSoftware\VitePlusRuntimeLock;
use App\Infrastructure\Processes\CommandDeadline;
use App\Infrastructure\Processes\CommandResult;
use App\Infrastructure\Processes\ProtectedInput;
use App\Infrastructure\RemotePrograms\RemoteProgramCatalog;
use App\Infrastructure\Tasks\TaskUnitProgram;
use Closure;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Sleep;
use RuntimeException;
use Symfony\Component\Process\Exception\ProcessTimedOutException;

/** Runs only code-owned installers; the persisted recipe supplies package names and integrity pins. */
final readonly class RecipeImagePrograms
{
    public const array Phases = ['apt-source', 'packages', 'javascript', 'rust', 'agent', 'pi'];

    /** A cold packages install takes about eight minutes. Bound the whole guest cgroup, including children. */
    public const int PhaseSeconds = 1440;

    public const int ObservationGraceSeconds = 60;

    public const int TransferSeconds = 900;

    private const int ControlSeconds = 30;

    public function __construct(private CommandDeadline $deadline) {}

    public function apply(TaskVmGuestShell $shell, MachineRecipe $recipe, string $profile, string $phase): void
    {
        $state = [];
        while (! $this->poll($shell, $recipe, $profile, $phase, 'upcloud', $state)) {
            Sleep::for(5)->seconds();
        }
    }

    /**
     * One observation bound and reboot fence for both providers. Persist before issuing any start.
     *
     * @param  array{observed_at?: float, boot_id?: string, attempt?: int, restart_from?: string}  $state
     * @param  (Closure(array{observed_at?: float, boot_id?: string, attempt?: int, restart_from?: string}): void)|null  $save
     */
    public function poll(TaskVmGuestShell $shell, MachineRecipe $recipe, string $profile, string $phase, string $execution, array &$state, ?Closure $save = null, int $attempt = 1): bool
    {
        if (! isset($state['observed_at'])) {
            $state['observed_at'] = microtime(true);
            $save?->__invoke($state);
        }
        $observedAt = $state['observed_at'];
        $unknown = null;
        try {
            if ($this->deadline->within(self::PhaseSeconds, function () use ($shell, $recipe, $profile, $phase, $execution, &$state, $save, $attempt): bool {
                return $this->observe($shell, $recipe, $profile, $phase, $execution, $state, $save, $attempt);
            })) {
                return true;
            }
        } catch (TaskVmException $error) {
            if ($error->errorCode !== TaskVmError::ImageChannelLost->value) {
                throw $error;
            }
            $unknown = $error;
            Log::warning($error->getMessage(), ['phase' => $profile.':'.$phase]);
        }
        if (microtime(true) - $observedAt > self::PhaseSeconds + self::ObservationGraceSeconds) {
            throw $unknown ?? new TaskVmException(TaskVmError::ImageChannelLost, "Recipe image phase [{$profile}:{$phase}] could not be observed within its runtime bound. The guest phase outcome is unknown.");
        }

        return false;
    }

    /**
     * @param  array{observed_at?: float, boot_id?: string, attempt?: int, restart_from?: string}  $state
     * @param  (Closure(array{observed_at?: float, boot_id?: string, attempt?: int, restart_from?: string}): void)|null  $save
     */
    private function observe(TaskVmGuestShell $shell, MachineRecipe $recipe, string $profile, string $phase, string $execution, array &$state, ?Closure $save, int $attempt): bool
    {
        $boot = $this->control($shell, ['cat', '/proc/sys/kernel/random/boot_id'], required: false, observation: true);
        $bootId = trim($boot->stdout);
        if (! $boot->succeeded() || preg_match('/\A[0-9a-f]{8}(?:-[0-9a-f]{4}){3}-[0-9a-f]{12}\z/D', $bootId) !== 1) {
            throw $this->unknown('The builder returned no readable boot ID.', $boot);
        }
        if (isset($state['boot_id']) && $state['boot_id'] !== $bootId) {
            throw new TaskVmException('task_vm.image_build_failed', "The builder rebooted during phase [{$profile}:{$phase}]. Run task-vms:build-recipe --retry to start a new builder.");
        }
        $unit = 'orbit-recipe-image-'.substr(hash('sha256', $recipe->identity.':'.$profile.':'.$phase.':'.$execution), 0, 32).'.service';
        $status = $this->status($shell, $unit);
        if ($status['LoadState'] === 'loaded' && ($state['attempt'] ?? $attempt) < $attempt
            && ! isset($state['restart_from'])) {
            if ($status['ActiveState'] === 'failed') {
                $state['restart_from'] = $status['InvocationID'];
            } else {
                $state['attempt'] = $attempt;
            }
            $save?->__invoke($state);
        }
        if ($status['LoadState'] === 'loaded' && isset($state['restart_from'])) {
            if ($status['InvocationID'] === $state['restart_from']) {
                $restart = $this->control($shell, ['systemctl', 'restart', '--', $unit], required: false);
                $status = $this->status($shell, $unit);
                if (($status['InvocationID'] ?? null) === $state['restart_from']) {
                    throw $this->unknown('The phase restart has not been observed.', $restart);
                }
            }
            unset($state['restart_from']);
            $state['attempt'] = $attempt;
            $save?->__invoke($state);
        }
        if ($status['LoadState'] === 'not-found') {
            $payload = $this->payload($shell, $recipe, $profile);
            if ($phase === 'pi' && ! isset($payload['tools']['pi'])) {
                return true;
            }
            $program = '/run/'.$unit.'.program';
            $input = null;
            if ($phase === 'pi') {
                $pin = $recipe->tools['pi']->artifacts[$payload['architecture']];
                $installed = $this->control($shell, ['sha256sum', TaskVmCloudInit::Home.'/'.TaskVmRuntime::PiPath], required: false);
                if ($installed->succeeded() && str_starts_with($installed->stdout, $pin->digest.' ')) {
                    return true;
                }
                $file = config()->string('orbit.home').'/sandbox-artifacts/'.$pin->location;
                if (! is_file($file) || hash_file($pin->algorithm, $file) !== $pin->digest) {
                    throw new TaskVmException('task_vm.image_build_failed', 'The recipe Pi artifact is missing or has the wrong digest.');
                }
                $directory = '/var/tmp/'.$unit.'.artifact';
                $this->control($shell, ['install', '-d', '-m', '0700', '--', $directory]);
                // Reuse the Pi installer's digest, disk staging, cleanup and atomic publication.
                $this->control($shell, ['env', 'HOME='.$directory, 'bash', '-ceu', TaskVmRuntime::InstallPi, '--', $pin->digest],
                    ProtectedInput::fromFile($file, '', TaskVmRuntime::ArtifactMaxBytes), timeout: self::TransferSeconds);
                $input = $directory.'/.local/bin/pi-server';
                $this->stage($shell, $program, TaskVmRuntime::InstallPi);
                $this->control($shell, ['chmod', '0644', $program]);
                $command = ['runuser', '-u', TaskVmCloudInit::User, '--', 'env', 'HOME='.TaskVmCloudInit::Home, 'bash', '-eu', $program, $pin->digest];
            } else {
                $this->stage($shell, $program, $this->script($payload));
                $command = ['python3', $program, $phase];
            }
            $log = IncusGuestShell::recipeLog($profile, $phase);
            $this->control($shell, ['install', '-d', '-m', '0700', '/var/log/orbit/recipe-images']);
            $this->control($shell, ['touch', '--', $log]);
            $this->control($shell, ['chmod', '0600', $log]);
            if (! isset($state['boot_id'])) {
                $state['observed_at'] = microtime(true);
            }
            $state['boot_id'] = $bootId;
            $state['attempt'] = $attempt;
            $save?->__invoke($state);
            $start = $this->control($shell, [
                'systemd-run', '--quiet', '--unit='.$unit,
                '--property=Type=exec', '--property=RemainAfterExit=yes', '--property=ExitType=cgroup',
                '--property=RuntimeMaxSec='.self::PhaseSeconds, '--property=TimeoutStopSec=30',
                '--property=KillMode=control-group', '--property=UMask=0022',
                '--property=StandardOutput=append:'.$log, '--property=StandardError=append:'.$log,
                ...($input === null ? [] : ['--property=StandardInput=file:'.$input]),
                'flock', '--nonblock', '--conflict-exit-code=75', '/run/orbit-recipe-image.lock', ...$command,
            ], required: false);
            // Two deliveries may race to start the same unit. Only its recorded state determines the result.
            $status = $this->status($shell, $unit);
            if (($status['LoadState'] ?? null) === 'not-found') {
                throw $this->failure($profile, $phase, $start);
            }
        }

        if (in_array($status['ActiveState'] ?? '', ['activating', 'deactivating'], true)
            || (($status['ActiveState'] ?? '') === 'active' && ($status['SubState'] ?? '') !== 'exited')) {
            return false;
        }
        if (($status['ActiveState'] ?? '') === 'active' && ($status['SubState'] ?? '') === 'exited'
            && ($status['Result'] ?? '') === 'success' && ($status['ExecMainStatus'] ?? '') === '0') {
            return true;
        }
        $exitCode = ($status['Result'] ?? '') === 'exit-code' && ($status['ExecMainCode'] ?? '') === '1'
            ? (int) $status['ExecMainStatus'] : null;
        $reason = match (true) {
            ($status['Result'] ?? '') === 'timeout' => 'timed out after '.self::PhaseSeconds.' seconds',
            $exitCode === 75 => 'could not start because another image phase holds the builder lock',
            in_array($status['ExecMainCode'] ?? '', ['2', '3'], true) => 'failed (systemd result '.$status['Result'].', signal '.$status['ExecMainStatus'].')',
            default => 'failed (systemd result '.$status['Result'].($exitCode === null ? '' : ', exit '.$exitCode).')',
        };
        $tail = $this->control($shell, ['tail', '-n', '40', '--', IncusGuestShell::recipeLog($profile, $phase)], required: false);
        $detail = mb_substr(new CommandActivityInputSanitizer()->redactText($tail->stdout), -12000);
        throw new TaskVmException('task_vm.image_build_failed', "Recipe image phase [{$profile}:{$phase}] {$reason}. Unit: {$unit}\n".mb_substr($detail, -2000),
            imageFailure: ['phase' => $profile.':'.$phase, 'exit_code' => $exitCode, 'stderr' => $detail]);
    }

    /** @return array<string, string> */
    private function status(TaskVmGuestShell $shell, string $unit): array
    {
        $result = $this->control($shell, ['systemctl', 'show', '--no-pager',
            '--property=LoadState,ActiveState,SubState,Result,ExecMainCode,ExecMainStatus,InvocationID', '--', $unit], required: false, observation: true);
        $status = [];
        foreach (explode("\n", trim($result->stdout)) as $line) {
            $parts = explode('=', $line, 2);
            if (count($parts) === 2) {
                $status[$parts[0]] = $parts[1];
            }
        }
        if (($status['LoadState'] ?? null) !== 'not-found'
            && (! $result->succeeded() || ($status['LoadState'] ?? null) !== 'loaded'
                || ! isset($status['ActiveState'], $status['SubState'], $status['Result'], $status['ExecMainCode'], $status['ExecMainStatus'], $status['InvocationID'])
                || preg_match('/\A[0-9a-f]{32}\z/D', $status['InvocationID']) !== 1
                || ! ctype_digit($status['ExecMainCode']) || ! ctype_digit($status['ExecMainStatus']))) {
            throw $this->unknown('The builder returned no readable systemd phase status.', $result);
        }

        return $status;
    }

    /** Publish complete immutable input before starting the unit, even when two deliveries race. */
    private function stage(TaskVmGuestShell $shell, string $path, string|ProtectedInput $input): void
    {
        $temporary = trim($this->control($shell, ['mktemp', '-d', '/run/orbit-recipe-image.XXXXXXXX'])->stdout);
        if (preg_match('~\A/run/orbit-recipe-image\.[A-Za-z0-9]{8}\z~D', $temporary) !== 1) {
            throw new TaskVmException('task_vm.image_build_failed', 'The builder returned an invalid staging path.');
        }
        try {
            $this->control($shell, ['install', '-m', '0600', '/dev/stdin', $temporary.'/input'], $input, timeout: self::TransferSeconds);
            $this->control($shell, ['mv', '--no-clobber', '--', $temporary.'/input', $path]);
        } finally {
            $this->control($shell, ['rm', '-rf', '--', $temporary]);
        }
    }

    private function unknown(string $reason, CommandResult $result): TaskVmException
    {
        $detail = new CommandActivityInputSanitizer()->redactText(trim($result->stderr));

        return new TaskVmException(TaskVmError::ImageChannelLost, $reason.' The guest phase outcome is unknown. Control exit '.$result->exitCode.'.'.($detail === '' ? '' : "\n".mb_substr($detail, -2000)));
    }

    /** @param list<string> $command */
    private function control(TaskVmGuestShell $shell, array $command, string|ProtectedInput|null $input = null, bool $required = true, int $timeout = self::ControlSeconds, bool $observation = false): CommandResult
    {
        $seconds = $timeout;
        try {
            $seconds = $this->deadline->cap($timeout);
            $result = $shell->asRoot($command, $input, timeout: $seconds);
        } catch (ProcessTimedOutException $error) {
            throw new TaskVmException(TaskVmError::ImageChannelLost, 'The image control command timed out after '.$seconds.' seconds. The guest phase outcome is unknown.', previous: $error);
        } catch (ResourceOperationException $error) {
            if ($error->errorCode !== 'command.deadline_exceeded') {
                throw $error;
            }
            throw new TaskVmException(TaskVmError::ImageChannelLost, 'The image control delivery exhausted its '.self::PhaseSeconds.'-second budget. The guest phase outcome is unknown.', previous: $error);
        }
        $detail = mb_substr(new CommandActivityInputSanitizer()->redactText(trim($result->stderr)), -2000);
        if ($result->exitCode === 255) {
            throw new TaskVmException(TaskVmError::ImageChannelLost, 'The image SSH channel closed (exit 255). The guest phase outcome is unknown.'.($detail === '' ? ' SSH returned no stderr.' : "\n{$detail}"));
        }
        if ($observation && $result->truncated) {
            throw $this->unknown('The status reply exceeded the capture limit.', $result);
        }
        if ($result->truncated || ($required && ! $result->succeeded())) {
            $reason = $result->truncated ? 'output exceeded the capture limit' : 'exit '.$result->exitCode;
            throw new TaskVmException('task_vm.image_build_failed', 'Image control command ['.$command[0].'] failed ('.$reason.').'.($detail === '' ? '' : "\n{$detail}"));
        }

        return $result;
    }

    public function verify(TaskVmGuestShell $shell, MachineRecipe $recipe, string $profile): void
    {
        $result = $this->control($shell, ['python3', '-', 'verify'], $this->script($this->payload($shell, $recipe, $profile)), required: false, timeout: 120);
        if (! $result->succeeded() || $result->truncated || trim($result->stdout) !== '{"ok":true}') {
            $tail = $this->control($shell, ['tail', '-n', '40', '--', IncusGuestShell::recipeLog($profile, 'verify')], required: false);
            throw $this->failure($profile, 'verify', new CommandResult($result->exitCode, $tail->stdout, $result->stderr, $result->durationMs, $result->truncated));
        }
    }

    private function failure(string $profile, string $phase, CommandResult $result): TaskVmException
    {
        $sanitizer = new CommandActivityInputSanitizer;
        $output = implode("\n", array_slice(explode("\n", trim($result->stdout."\n".$result->stderr)), -40));
        $stderr = mb_substr($sanitizer->redactText($output), -12000);

        return new TaskVmException('task_vm.image_build_failed', "Recipe image phase [{$profile}:{$phase}] failed (exit {$result->exitCode}).".($stderr === '' ? '' : "\n".mb_substr($stderr, -2000)),
            imageFailure: ['phase' => $profile.':'.$phase, 'exit_code' => $result->exitCode, 'stderr' => $stderr]);
    }

    /** @return array{identity: string, snapshot: string, artifact_mirror: string, git_packages: list<string>, architecture: string, profile: string, tools: array<string, mixed>, vp_lock: string|null, php: array<string, mixed>|null} */
    private function payload(TaskVmGuestShell $shell, MachineRecipe $recipe, string $profile): array
    {
        $arch = $this->control($shell, ['dpkg', '--print-architecture']);
        $architecture = trim($arch->stdout);
        if (! $arch->succeeded() || ! in_array($architecture, ['amd64', 'arm64'], true)) {
            throw new TaskVmException('task_vm.image_build_failed', 'The image architecture is unsupported.');
        }
        $names = array_unique([...$recipe->profile('base', $architecture), ...$recipe->profile($profile, $architecture)]);
        $data = json_decode($recipe->json, true, flags: JSON_THROW_ON_ERROR);

        if (! is_array($data) || ! is_array($data['tools'] ?? null)) {
            throw new RuntimeException('The stored recipe tools are invalid.');
        }
        $tools = [];
        foreach ($names as $name) {
            if (! array_key_exists($name, $data['tools'])) {
                throw new RuntimeException('A selected recipe tool is missing.');
            }
            $tools[$name] = $data['tools'][$name];
        }

        $versions = array_map(strval(...), array_keys($recipe->phpExtensions(array_keys($tools))));
        $catalog = new RemoteProgramCatalog;
        // The image installs its PHP build with php.install-build, the one installer (ADR 0216). Images run no PHP-FPM.
        $php = $versions === [] ? null : [
            'program' => $catalog->program(PhpBuildInstaller::Program)->source,
            'bootstrap' => $catalog->bootstrap(),
            'input' => PhpBuildInstaller::input($recipe, $versions, 'apply', in_array($profile, $recipe->phpPolicy->pcovProfiles, true), 'refuse', 'none', opentelemetry: in_array($profile, $recipe->phpPolicy->opentelemetryProfiles, true)),
        ];

        return ['identity' => $recipe->identity, 'snapshot' => $recipe->snapshot, 'architecture' => $architecture,
            'profile' => $profile, 'tools' => $tools, 'vp_lock' => isset($tools['vp']) ? VitePlusRuntimeLock::read($recipe->tools['vp']) : null, 'php' => $php, 'git_packages' => $recipe->gitPackages, 'artifact_mirror' => $recipe->artifactMirror];
    }

    /** @param array<string, mixed> $payload */
    private function script(array $payload): string
    {
        $shared = file_get_contents(resource_path('remote/lib/recipe.py'));
        $image = file_get_contents(resource_path('machine-software/build-image.py'));
        if ($shared === false || $image === false) {
            throw new RuntimeException('The recipe image program is missing.');
        }

        // Reuse the checked download, archive extraction and platform helpers without invoking the Node entry point.
        $shared = file_get_contents(resource_path('remote/lib/artifacts.py'))."\n".TaskUnitProgram::embed($shared);

        return RecipeProgramInput::source(str_replace("if __name__ == '__main__':\n    sys.exit(main())", '', $shared)."\n".str_replace("if __name__ == '__main__':\n    sys.exit(image_main())", '', $image), $payload, 'image_main');
    }
}
