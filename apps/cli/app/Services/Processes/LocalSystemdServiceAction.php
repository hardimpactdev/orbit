<?php

declare(strict_types=1);

namespace App\Services\Processes;

use Symfony\Component\Process\Process;

/**
 * @mago-expect lint:cyclomatic-complexity
 * @mago-expect lint:kan-defect
 * @mago-expect lint:too-many-methods
 */
final readonly class LocalSystemdServiceAction
{
    private const array ACTIONS = ['apply', 'is-active', 'probe', 'remove', 'restart', 'start', 'stop'];

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function run(string $action, string $service, ?string $unitPath, array $payload = []): array
    {
        $action = $this->action($action);
        $service = $this->service($service);

        if ($action === 'apply') {
            return $this->apply(
                service: $service,
                content: $this->content($payload['content'] ?? null),
                enabledState: $this->enabledState($payload['enabled'] ?? true),
            );
        }

        if ($action === 'probe') {
            return [
                'action' => 'probe',
                'service' => $service,
                ...$this->probe($service),
            ];
        }

        if ($action === 'remove') {
            return $this->remove($service, $this->unitPath($unitPath));
        }

        return $this->lifecycle($action, $service);
    }

    /**
     * @return array<string, mixed>
     */
    private function apply(string $service, string $content, string $enabledState): array
    {
        $probe = $this->probe($service);
        $expectedHash = hash('sha256', $content);
        $enabled = $this->isEnabledState($enabledState);

        if (
            $probe['exists'] === true
            && hash_equals($expectedHash, is_string($probe['hash']) ? $probe['hash'] : '')
            && $probe['enabled'] === $enabled
        ) {
            return [
                'action' => 'apply',
                'service' => $service,
                'status' => 'ok',
                'changed' => false,
                'summary' => "Systemd service {$service} already matches gateway intent.",
                'details' => $this->details($service, $enabled, $expectedHash, [
                    'observed_hash' => $probe['hash'],
                    'observed_enabled' => $probe['enabled'],
                ]),
            ];
        }

        $this->write($service, $content, $enabledState);

        return [
            'action' => 'apply',
            'service' => $service,
            'status' => 'changed',
            'changed' => true,
            'summary' => "Applied systemd service {$service}.",
            'details' => $this->details($service, $enabled, $expectedHash),
        ];
    }

    /**
     * @return array{exists: bool, enabled: bool, hash: string|null}
     */
    private function probe(string $service): array
    {
        $enabledResult = $this->runProcess(['sudo', 'systemctl', 'is-enabled', $service]);
        $enabled = trim($enabledResult->getOutput()) === 'enabled';
        $unitPath = $this->unitPathForService($service);

        if (! $this->runProcess(['sudo', 'test', '-f', $unitPath])->isSuccessful()) {
            return [
                'exists' => false,
                'enabled' => $enabled,
                'hash' => null,
            ];
        }

        return [
            'exists' => true,
            'enabled' => $enabled,
            'hash' => $this->hash($unitPath),
        ];
    }

    private function hash(string $unitPath): ?string
    {
        foreach ([
            ['sudo', 'sha256sum', $unitPath],
            ['sudo', 'shasum', '-a', '256', $unitPath],
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

    private function write(string $service, string $content, string $enabledState): void
    {
        $directory = '/etc/systemd/system';
        $unitPath = $this->unitPathForService($service);
        $temporaryPath = $this->temporaryPath($service);
        $enabled = $this->isEnabledState($enabledState);

        try {
            if (file_put_contents($temporaryPath, $content) === false) {
                throw new LocalSystemdServiceFailure(
                    errorCode: 'systemd_service.apply_failed',
                    message: "Systemd service apply failed for '{$service}'.",
                    meta: [
                        'action' => 'apply',
                        'service' => $service,
                        'stderr' => 'Unable to stage unit content.',
                    ],
                );
            }

            foreach ([
                ['sudo', 'install', '-d', '-m', '0755', $directory],
                ['sudo', 'install', '-m', '0644', $temporaryPath, $unitPath],
                ['sudo', 'systemctl', 'daemon-reload'],
                $enabled
                    ? ['sudo', 'systemctl', 'enable', $service]
                    : ['sudo', 'systemctl', 'disable', $service],
            ] as $command) {
                $result = $this->runProcess($command);

                if (! $result->isSuccessful() && ($enabled || $command[2] !== 'disable')) {
                    throw $this->failure('apply', $service, $result);
                }
            }
        } finally {
            $this->removeTemporaryFile($temporaryPath);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function lifecycle(string $action, string $service): array
    {
        $result = $this->runProcess(['sudo', 'systemctl', $action, $service]);

        if ($result->isSuccessful()) {
            return [
                'action' => $action,
                'service' => $service,
                'changed' => $action !== 'is-active',
            ];
        }

        throw $this->failure($action, $service, $result);
    }

    /**
     * @return array<string, mixed>
     */
    private function remove(string $service, string $unitPath): array
    {
        foreach ([
            ['sudo', 'systemctl', 'stop',          $service],
            ['sudo', 'systemctl', 'disable',       $service],
            ['sudo', 'rm',        '-f',            $unitPath],
            ['sudo', 'systemctl', 'daemon-reload'],
            ['sudo', 'systemctl', 'reset-failed',  $service],
        ] as $command) {
            $result = $this->runProcess($command);

            if (! $result->isSuccessful() && ! $this->isIgnorableRemoveFailure($command, $result)) {
                throw $this->failure('remove', $service, $result);
            }
        }

        return [
            'action' => 'remove',
            'service' => $service,
            'unit_path' => $unitPath,
            'changed' => true,
        ];
    }

    private function action(string $value): string
    {
        if (in_array($value, self::ACTIONS, strict: true)) {
            return $value;
        }

        throw new LocalSystemdServiceFailure(
            errorCode: 'validation_failed',
            message: 'Systemd service action is invalid.',
            meta: ['field' => 'action'],
        );
    }

    private function service(string $value): string
    {
        if (preg_match('/^[a-zA-Z0-9][a-zA-Z0-9_.@-]*\\.service$/', $value) === 1) {
            return $value;
        }

        throw new LocalSystemdServiceFailure(
            errorCode: 'validation_failed',
            message: 'Systemd service name is invalid.',
            meta: ['field' => 'service'],
        );
    }

    private function unitPath(?string $value): string
    {
        if (
            is_string($value)
            && preg_match('#^/etc/systemd/system/[a-zA-Z0-9][a-zA-Z0-9_.@-]*\\.service$#', $value) === 1
        ) {
            return $value;
        }

        throw new LocalSystemdServiceFailure(
            errorCode: 'validation_failed',
            message: 'Systemd unit path is invalid.',
            meta: ['field' => 'unit-path'],
        );
    }

    private function unitPathForService(string $service): string
    {
        return "/etc/systemd/system/{$service}";
    }

    /**
     * @return array<string, mixed>
     */
    private function details(string $service, bool $enabled, string $expectedHash, array $extra = []): array
    {
        return [
            'service' => $service,
            'path' => $this->unitPathForService($service),
            'enabled' => $enabled,
            'expected_hash' => $expectedHash,
            ...$extra,
        ];
    }

    private function content(mixed $value): string
    {
        if (is_string($value) && trim($value) !== '') {
            return $value;
        }

        throw new LocalSystemdServiceFailure(
            errorCode: 'validation_failed',
            message: 'Systemd service content is invalid.',
            meta: ['field' => 'content'],
        );
    }

    private function enabledState(mixed $value): string
    {
        if (is_bool($value)) {
            return $value ? 'enabled' : 'disabled';
        }

        throw new LocalSystemdServiceFailure(
            errorCode: 'validation_failed',
            message: 'Systemd service enabled flag is invalid.',
            meta: ['field' => 'enabled'],
        );
    }

    private function isEnabledState(string $value): bool
    {
        return $value === 'enabled';
    }

    private function temporaryPath(string $service): string
    {
        $path = tempnam(sys_get_temp_dir(), "orbit-{$service}-");

        if ($path === false) {
            throw new LocalSystemdServiceFailure(
                errorCode: 'systemd_service.apply_failed',
                message: "Systemd service apply failed for '{$service}'.",
                meta: [
                    'action' => 'apply',
                    'service' => $service,
                    'stderr' => 'Unable to create temporary unit file.',
                ],
            );
        }

        return $path;
    }

    private function removeTemporaryFile(string $path): void
    {
        if (is_file($path) && ! unlink($path)) {
            throw new LocalSystemdServiceFailure(
                errorCode: 'systemd_service.apply_failed',
                message: 'Systemd service apply failed.',
                meta: [
                    'action' => 'apply',
                    'stderr' => 'Unable to remove temporary unit file.',
                ],
            );
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

    /**
     * @param  list<string>  $command
     */
    private function isIgnorableRemoveFailure(array $command, Process $result): bool
    {
        if ($command[2] === 'daemon-reload') {
            return false;
        }

        return (
            str_contains($result->getErrorOutput().' '.$result->getOutput(), 'not loaded')
            || str_contains($result->getErrorOutput().' '.$result->getOutput(), 'does not exist')
            || str_contains($result->getErrorOutput().' '.$result->getOutput(), 'not-found')
        );
    }

    private function failure(string $action, string $service, Process $result): LocalSystemdServiceFailure
    {
        return new LocalSystemdServiceFailure(
            errorCode: "systemd_service.{$action}_failed",
            message: "Systemd service {$action} failed for '{$service}'.",
            meta: [
                'action' => $action,
                'service' => $service,
                'exit_code' => $result->getExitCode(),
                'stderr' => trim($result->getErrorOutput()),
            ],
        );
    }
}
