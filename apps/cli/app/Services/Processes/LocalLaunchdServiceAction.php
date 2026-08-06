<?php

declare(strict_types=1);

namespace App\Services\Processes;

use Closure;
use Symfony\Component\Process\Process;

/**
 * @mago-expect lint:cyclomatic-complexity
 * @mago-expect lint:kan-defect
 * @mago-expect lint:too-many-methods
 */
final readonly class LocalLaunchdServiceAction
{
    private const array ACTIONS = ['apply', 'is-active', 'probe', 'remove', 'restart', 'start', 'stop'];

    private const string LABEL_PATTERN = '#^dev\.hardimpact\.orbit\.[A-Za-z0-9_.-]+$#';

    /**
     * Covers launchd's default 10-second ThrottleInterval: a unit whose spawn
     * is throttled becomes observable as running within this window.
     */
    private const float START_READINESS_TIMEOUT_SECONDS = 15.0;

    private const int START_READINESS_POLL_MICROSECONDS = 500_000;

    public function __construct(
        private ?string $osFamily = null,
        private ?float $startReadinessTimeoutSeconds = null,
        private ?int $startReadinessPollMicroseconds = null,
    ) {}

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function run(string $action, string $label, ?string $plistPath, array $payload = []): array
    {
        $action = $this->action($action);
        $label = $this->label($label);

        $this->assertMacos($action, $label);

        if ($action === 'apply') {
            return $this->apply(
                label: $label,
                content: $this->content($payload['content'] ?? null),
                enabled: $this->enabled($payload['enabled'] ?? null),
            );
        }

        if ($action === 'probe') {
            return [
                'action' => 'probe',
                'label' => $label,
                ...$this->probe($label),
            ];
        }

        if ($action === 'remove') {
            return $this->remove($label, $this->plistPath($plistPath, $label));
        }

        return $this->lifecycle($action, $label);
    }

    /**
     * @return array<string, mixed>
     */
    private function apply(string $label, string $content, bool $enabled): array
    {
        $probe = $this->probe($label);
        $expectedHash = hash('sha256', $content);
        $contentMatches =
            $probe['exists'] === true && hash_equals($expectedHash, is_string($probe['hash']) ? $probe['hash'] : '');

        if (! $contentMatches) {
            $this->write($label, $content);
        }

        match ($enabled) {
            true => $this->enable($label, 'apply'),
            false => $this->disable($label, 'apply'),
        };

        if ($contentMatches) {
            return [
                'action' => 'apply',
                'label' => $label,
                'status' => 'ok',
                'changed' => false,
                'summary' => "Launchd plist for {$label} already matches gateway intent.",
                'details' => $this->details($label, $expectedHash, [
                    'observed_hash' => $probe['hash'],
                    'observed_loaded' => $probe['loaded'],
                    'enabled' => $enabled,
                ]),
            ];
        }

        return [
            'action' => 'apply',
            'label' => $label,
            'status' => 'changed',
            'changed' => true,
            'summary' => "Applied launchd plist for {$label}.",
            'details' => $this->details($label, $expectedHash, ['enabled' => $enabled]),
        ];
    }

    /**
     * @return array{exists: bool, loaded: bool, hash: string|null}
     */
    private function probe(string $label): array
    {
        $plistPath = $this->plistPathForLabel($label);

        $exists = is_file($plistPath);

        $uid = $this->currentUid();
        $target = 'gui/'.$uid.'/'.$label;
        $print = $this->runProcess(['launchctl', 'print', $target]);

        $loaded = $print->isSuccessful();

        $hash = $exists ? $this->hash($plistPath) : null;

        return [
            'exists' => $exists,
            'loaded' => $loaded,
            'hash' => $hash,
        ];
    }

    private function hash(string $plistPath): ?string
    {
        foreach ([
            ['shasum', '-a', '256', $plistPath],
            ['sha256sum', $plistPath],
        ] as $command) {
            $result = $this->runProcess($command);

            if (! $result->isSuccessful()) {
                continue;
            }

            $parts = preg_split('/\s+/', trim($result->getOutput()));
            $hash = is_array($parts) ? $parts[0] ?? null : null;

            if (is_string($hash) && preg_match('/^[a-f0-9]{64}$/', $hash) === 1) {
                return $hash;
            }
        }

        return null;
    }

    private function write(string $label, string $content): void
    {
        $plistPath = $this->plistPathForLabel($label);
        $directory = dirname($plistPath);
        $logDir = $this->logDir();
        $temporaryPath = $this->temporaryPath($label);

        try {
            if (! is_dir($directory)) {
                if (
                    ! $this->filesystem(static fn (): bool => mkdir($directory, permissions: 0o755, recursive: true))
                    && ! is_dir($directory)
                ) {
                    throw new LocalLaunchdServiceFailure(
                        errorCode: 'launchd_service.apply_failed',
                        message: "Launchd apply failed for '{$label}'.",
                        meta: [
                            'action' => 'apply',
                            'label' => $label,
                            'stderr' => 'Unable to create LaunchAgents directory.',
                        ],
                    );
                }
            }

            if (file_put_contents($temporaryPath, $content) === false) {
                throw new LocalLaunchdServiceFailure(
                    errorCode: 'launchd_service.apply_failed',
                    message: "Launchd apply failed for '{$label}'.",
                    meta: ['action' => 'apply', 'label' => $label, 'stderr' => 'Unable to stage plist content.'],
                );
            }

            if (! $this->filesystem(static fn (): bool => rename($temporaryPath, $plistPath))) {
                if (! $this->filesystem(static fn (): bool => copy($temporaryPath, $plistPath))) {
                    throw new LocalLaunchdServiceFailure(
                        errorCode: 'launchd_service.apply_failed',
                        message: "Launchd apply failed for '{$label}'.",
                        meta: ['action' => 'apply', 'label' => $label, 'stderr' => 'Unable to install plist.'],
                    );
                }
                $this->filesystem(static fn (): bool => unlink($temporaryPath));
            }

            $this->filesystem(static fn (): bool => chmod($plistPath, permissions: 0o644));

            if (! is_dir($logDir)) {
                $this->filesystem(static fn (): bool => mkdir($logDir, permissions: 0o755, recursive: true));
            }
        } finally {
            $this->removeTemporaryFile($temporaryPath);
        }
    }

    /**
     * @mago-expect lint:halstead
     *
     * @return array<string, mixed>
     */
    private function lifecycle(string $action, string $label): array
    {
        $uid = $this->currentUid();
        $gui = 'gui/'.$uid;
        $target = $gui.'/'.$label;
        $plist = $this->plistPathForLabel($label);

        if ($action === 'start') {
            return $this->start($label, $gui, $target, $plist);
        }

        if ($action === 'stop') {
            $this->disable($label, 'stop');

            $bootout = $this->runProcess(['launchctl', 'bootout', $target]);
            if (! $bootout->isSuccessful()) {
                $combined = $bootout->getErrorOutput().' '.$bootout->getOutput();
                if (! $this->isIgnorableStop($combined)) {
                    throw $this->failure('stop', $label, $bootout);
                }
            }

            return ['action' => 'stop', 'label' => $label, 'changed' => true];
        }

        if ($action === 'restart') {
            // stop tolerate, then start
            $this->lifecycle('stop', $label);

            return $this->lifecycle('start', $label);
        }

        if ($action === 'is-active') {
            $print = $this->runProcess(['launchctl', 'print', $target]);

            if ($print->isSuccessful() && $this->isLaunchdRunning($print->getOutput())) {
                return ['action' => $action, 'label' => $label, 'changed' => false];
            }

            throw $this->failure($action, $label, $print);
        }

        // fallback for other actions
        $result = $this->runProcess(['launchctl', $action, $target]);
        if ($result->isSuccessful()) {
            return ['action' => $action, 'label' => $label, 'changed' => true];
        }

        throw $this->failure($action, $label, $result);
    }

    /**
     * Outcome-based, convergent start. An already-running unit is confirmed
     * without being touched (start never kills). Otherwise the unit is booted
     * out and re-bootstrapped from the on-disk plist — resetting launchd's
     * accumulated spawn penalty and loading the current definition — then
     * kickstarted, because development units carry RunAtLoad=false and never
     * spawn from bootstrap alone. Bootstrap or kickstart refusals inside
     * launchd's throttle window are not failures: success is decided by the
     * readiness poll that covers that window.
     *
     * @return array<string, mixed>
     */
    private function start(string $label, string $gui, string $target, string $plist): array
    {
        $enable = $this->runProcess(['launchctl', 'enable', $target]);
        if (! $enable->isSuccessful()) {
            throw $this->failure('start', $label, $enable);
        }

        $print = $this->runProcess(['launchctl', 'print', $target]);
        if ($print->isSuccessful() && $this->isLaunchdRunning($print->getOutput())) {
            return ['action' => 'start', 'label' => $label, 'changed' => false];
        }

        $this->runProcess(['launchctl', 'bootout', $target]);
        $bootstrap = $this->runProcess(['launchctl', 'bootstrap', $gui, $plist]);
        $kick = $this->runProcess(['launchctl', 'kickstart', $target]);

        if ($this->waitUntilRunning($target)) {
            return ['action' => 'start', 'label' => $label, 'changed' => true];
        }

        throw $this->failure('start', $label, $bootstrap->isSuccessful() ? $kick : $bootstrap);
    }

    private function waitUntilRunning(string $target): bool
    {
        $timeout = $this->startReadinessTimeoutSeconds ?? self::START_READINESS_TIMEOUT_SECONDS;
        $poll = $this->startReadinessPollMicroseconds ?? self::START_READINESS_POLL_MICROSECONDS;
        $deadline = microtime(true) + max(0.1, $timeout);

        while (true) {
            $print = $this->runProcess(['launchctl', 'print', $target]);

            if ($print->isSuccessful() && $this->isLaunchdRunning($print->getOutput())) {
                return true;
            }

            if (microtime(true) >= $deadline) {
                return false;
            }

            usleep(max(1000, $poll));
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function remove(string $label, string $plistPath): array
    {
        $uid = $this->currentUid();
        $target = 'gui/'.$uid.'/'.$label;

        // stop tolerate missing
        $bootout = $this->runProcess(['launchctl', 'bootout', $target]);
        if (! $bootout->isSuccessful()) {
            $combined = $bootout->getErrorOutput().' '.$bootout->getOutput();
            if (! $this->isIgnorableStop($combined)) {
                throw $this->failure('remove', $label, $bootout);
            }
        }

        if (is_file($plistPath)) {
            if (! $this->filesystem(static fn (): bool => unlink($plistPath))) {
                throw new LocalLaunchdServiceFailure(
                    errorCode: 'launchd_service.remove_failed',
                    message: "Launchd remove failed for '{$label}'.",
                    meta: ['action' => 'remove', 'label' => $label, 'stderr' => 'Unable to remove plist.'],
                );
            }
        }

        return [
            'action' => 'remove',
            'label' => $label,
            'plist_path' => $plistPath,
            'changed' => true,
        ];
    }

    private function isLaunchdRunning(string $printOutput): bool
    {
        if (preg_match('/^\s*state\s*=\s*running\s*$/mi', $printOutput) !== 1) {
            return false;
        }

        if (preg_match('/^\s*pid\s*=\s*(\d+)\s*$/mi', $printOutput, $matches) !== 1) {
            return false;
        }

        return (int) $matches[1] > 0;
    }

    private function action(string $value): string
    {
        if (in_array($value, self::ACTIONS, strict: true)) {
            return $value;
        }

        throw new LocalLaunchdServiceFailure(
            errorCode: 'validation_failed',
            message: 'Launchd service action is invalid.',
            meta: ['field' => 'action'],
        );
    }

    private function label(string $value): string
    {
        if (preg_match(self::LABEL_PATTERN, $value) === 1) {
            return $value;
        }

        throw new LocalLaunchdServiceFailure(
            errorCode: 'validation_failed',
            message: 'Launchd label is invalid.',
            meta: ['field' => 'label'],
        );
    }

    private function plistPath(?string $value, string $label): string
    {
        $expected = $this->plistPathForLabel($label);

        if (is_string($value) && $value === $expected) {
            return $value;
        }

        throw new LocalLaunchdServiceFailure(
            errorCode: 'validation_failed',
            message: 'Launchd plist path is invalid.',
            meta: ['field' => 'plist-path'],
        );
    }

    private function plistPathForLabel(string $label): string
    {
        $home = $this->currentHome();

        return $home.'/Library/LaunchAgents/'.$label.'.plist';
    }

    private function logDir(): string
    {
        $home = $this->currentHome();

        return $home.'/Library/Logs/Orbit/processes';
    }

    private function currentHome(): string
    {
        $home = getenv('HOME');
        if (is_string($home) && $home !== '') {
            return rtrim($home, characters: '/');
        }
        $uid = $this->currentUid();

        return '/Users/'.get_current_user(); // best effort
    }

    private function currentUid(): int
    {
        if (function_exists('posix_getuid')) {
            return posix_getuid();
        }

        $uid = getmyuid();

        return is_int($uid) ? $uid : 0;
    }

    private function content(mixed $value): string
    {
        if (is_string($value) && trim($value) !== '') {
            return $value;
        }

        throw new LocalLaunchdServiceFailure(
            errorCode: 'validation_failed',
            message: 'Launchd plist content is invalid.',
            meta: ['field' => 'content'],
        );
    }

    private function enabled(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        throw new LocalLaunchdServiceFailure(
            errorCode: 'validation_failed',
            message: 'Launchd enabled state is invalid.',
            meta: ['field' => 'enabled'],
        );
    }

    private function enable(string $label, string $action): void
    {
        $target = 'gui/'.$this->currentUid().'/'.$label;
        $result = $this->runProcess(['launchctl', 'enable', $target]);

        if (! $result->isSuccessful()) {
            throw $this->failure($action, $label, $result);
        }
    }

    private function disable(string $label, string $action): void
    {
        $target = 'gui/'.$this->currentUid().'/'.$label;
        $result = $this->runProcess(['launchctl', 'disable', $target]);

        if (! $result->isSuccessful()) {
            throw $this->failure($action, $label, $result);
        }
    }

    private function assertMacos(string $action, string $label): void
    {
        $osFamily = $this->osFamily ?? PHP_OS_FAMILY;

        if ($osFamily === 'Darwin') {
            return;
        }

        throw new LocalLaunchdServiceFailure(
            errorCode: 'launchd_service.unsupported_platform',
            message: 'Launchd process service actions require macOS.',
            meta: [
                'action' => $action,
                'label' => $label,
                'platform' => $osFamily,
            ],
        );
    }

    private function temporaryPath(string $label): string
    {
        $path = tempnam(sys_get_temp_dir(), "orbit-launchd-{$label}-");

        if ($path === false) {
            throw new LocalLaunchdServiceFailure(
                errorCode: 'launchd_service.apply_failed',
                message: "Launchd apply failed for '{$label}'.",
                meta: ['action' => 'apply', 'label' => $label, 'stderr' => 'Unable to create temporary plist.'],
            );
        }

        return $path;
    }

    private function removeTemporaryFile(string $path): void
    {
        if (is_file($path)) {
            $this->filesystem(static fn (): bool => unlink($path));
        }
    }

    private function filesystem(Closure $operation): bool
    {
        set_error_handler(static fn (): bool => true);

        try {
            return $operation() === true;
        } finally {
            restore_error_handler();
        }
    }

    /**
     * @param  list<string>  $command
     */
    private function runProcess(array $command): Process
    {
        $process = new Process($command);
        $process->setTimeout(60);
        $process->run();

        return $process;
    }

    private function isIgnorableStop(string $output): bool
    {
        $o = strtolower($output);

        return (
            str_contains($o, 'not found')
            || str_contains($o, 'no such process')
            || str_contains($o, 'unknown service')
        );
    }

    private function failure(string $action, string $label, Process $result): LocalLaunchdServiceFailure
    {
        return new LocalLaunchdServiceFailure(
            errorCode: "launchd_service.{$action}_failed",
            message: "Launchd service {$action} failed for '{$label}'.",
            meta: [
                'action' => $action,
                'label' => $label,
                'exit_code' => $result->getExitCode(),
                'stderr' => trim($result->getErrorOutput()),
            ],
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function details(string $label, string $expectedHash, array $extra = []): array
    {
        return [
            'label' => $label,
            'path' => $this->plistPathForLabel($label),
            'expected_hash' => $expectedHash,
            ...$extra,
        ];
    }
}
