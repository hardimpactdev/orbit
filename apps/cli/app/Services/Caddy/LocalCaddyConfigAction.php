<?php

declare(strict_types=1);

namespace App\Services\Caddy;

use Symfony\Component\Process\Exception\ProcessStartFailedException;
use Symfony\Component\Process\Process;

/**
 * @mago-expect lint:cyclomatic-complexity
 * @mago-expect lint:too-many-methods
 */
final readonly class LocalCaddyConfigAction
{
    private const array ACTIONS = ['read-global', 'write-global', 'write-site', 'reload'];

    private const string GLOBAL_CADDYFILE = '/etc/caddy/Caddyfile';

    private const string SITES_DIRECTORY = '/etc/caddy/sites';

    private const string DEFAULT_CONTAINER = 'orbit-caddy';

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function run(mixed $action, array $payload): array
    {
        $action = $this->action($action);

        if ($action === 'read-global') {
            return $this->readGlobal();
        }

        if ($action === 'write-global') {
            return $this->writeFile(
                path: self::GLOBAL_CADDYFILE,
                content: $this->content($payload['content'] ?? null),
            );
        }

        if ($action === 'write-site') {
            return $this->writeFile(
                path: $this->sitePath(
                    domain: $payload['domain'] ?? null,
                    suffix: ($payload['backend'] ?? null) === true ? '.backend' : '',
                ),
                content: $this->content($payload['content'] ?? null),
            );
        }

        return $this->reload($this->container($payload['container'] ?? null));
    }

    /**
     * @return array<string, mixed>
     */
    private function readGlobal(): array
    {
        $exists = $this->runProcess(['sudo', 'test', '-f', self::GLOBAL_CADDYFILE]);

        if ($exists['exit_code'] !== 0) {
            return [
                'path' => self::GLOBAL_CADDYFILE,
                'content' => '',
                'exists' => false,
            ];
        }

        $read = $this->runProcess(['sudo', 'cat', self::GLOBAL_CADDYFILE]);

        if ($read['exit_code'] !== 0) {
            throw new LocalCaddyConfigFailure(
                errorCode: 'caddy_config.read_failed',
                message: 'Caddy global config could not be read.',
                meta: [
                    'path' => self::GLOBAL_CADDYFILE,
                    'exit_code' => $read['exit_code'],
                    'output' => $read['output'],
                ],
            );
        }

        return [
            'path' => self::GLOBAL_CADDYFILE,
            'content' => $read['output'],
            'exists' => true,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function writeFile(string $path, string $content): array
    {
        $directory = dirname($path);

        $this->mustRun([
            'sudo',
            'install',
            '-d',
            '-m',
            '0755',
            '/etc/caddy',
            self::SITES_DIRECTORY,
        ], 'caddy_config.directory_failed');
        $this->mustRun(['sudo', 'install', '-d', '-m', '0755', $directory], 'caddy_config.directory_failed');
        $this->mustRunWithInput(['sudo', 'tee', $path], $content, 'caddy_config.write_failed');
        $this->mustRun(['sudo', 'chmod', '0644', $path], 'caddy_config.chmod_failed');

        return [
            'path' => $path,
            'hash' => hash('sha256', $content),
            'bytes' => strlen($content),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function reload(string $container): array
    {
        $result = $this->runProcess([
            'docker',
            'exec',
            $container,
            'caddy',
            'reload',
            '--config',
            self::GLOBAL_CADDYFILE,
            '--adapter',
            'caddyfile',
            '--address',
            'localhost:2019',
        ]);

        if ($result['exit_code'] !== 0) {
            throw new LocalCaddyConfigFailure(
                errorCode: 'caddy_config.reload_failed',
                message: 'Caddy config reload failed.',
                meta: [
                    'container' => $container,
                    'exit_code' => $result['exit_code'],
                    'output' => $result['output'],
                ],
            );
        }

        return [
            'container' => $container,
            'exit_code' => $result['exit_code'],
        ];
    }

    private function action(mixed $value): string
    {
        if (is_string($value) && in_array($value, self::ACTIONS, strict: true)) {
            return $value;
        }

        throw new LocalCaddyConfigFailure(
            errorCode: 'validation_failed',
            message: 'Caddy config action is invalid.',
            meta: ['field' => 'action'],
        );
    }

    private function content(mixed $value): string
    {
        if (is_string($value)) {
            return $value;
        }

        throw new LocalCaddyConfigFailure(
            errorCode: 'validation_failed',
            message: 'Caddy config content must be a string.',
            meta: ['field' => 'content'],
        );
    }

    private function sitePath(mixed $domain, string $suffix): string
    {
        if (! is_string($domain) || preg_match('/\A(?:\*\.)?[A-Za-z0-9][A-Za-z0-9._-]*\z/', $domain) !== 1) {
            throw new LocalCaddyConfigFailure(
                errorCode: 'validation_failed',
                message: 'Caddy site domain is invalid.',
                meta: ['field' => 'domain'],
            );
        }

        return self::SITES_DIRECTORY."/{$domain}{$suffix}.caddy";
    }

    private function container(mixed $value): string
    {
        if ($value === null) {
            return self::DEFAULT_CONTAINER;
        }

        if (is_string($value) && preg_match('/\A[A-Za-z0-9][A-Za-z0-9_.-]{0,127}\z/', $value) === 1) {
            return $value;
        }

        throw new LocalCaddyConfigFailure(
            errorCode: 'validation_failed',
            message: 'Caddy container name is invalid.',
            meta: ['field' => 'container'],
        );
    }

    /**
     * @param  list<string>  $command
     */
    private function mustRun(array $command, string $errorCode): void
    {
        $result = $this->runProcess($command);

        if ($result['exit_code'] === 0) {
            return;
        }

        throw new LocalCaddyConfigFailure(
            errorCode: $errorCode,
            message: 'Caddy config command failed.',
            meta: [
                'exit_code' => $result['exit_code'],
                'output' => $result['output'],
            ],
        );
    }

    /**
     * @param  list<string>  $command
     */
    private function mustRunWithInput(array $command, string $input, string $errorCode): void
    {
        $result = $this->runProcess($command, $input);

        if ($result['exit_code'] === 0) {
            return;
        }

        throw new LocalCaddyConfigFailure(
            errorCode: $errorCode,
            message: 'Caddy config command failed.',
            meta: [
                'exit_code' => $result['exit_code'],
                'output' => $result['output'],
            ],
        );
    }

    /**
     * @param  list<string>  $command
     * @return array{exit_code: int, output: string}
     */
    private function runProcess(array $command, ?string $input = null): array
    {
        try {
            $process = new Process($command);
            $process->setTimeout(30);

            if ($input !== null) {
                $process->setInput($input);
            }

            $process->run();

            return [
                'exit_code' => $process->getExitCode() ?? 1,
                'output' => trim($process->getOutput().$process->getErrorOutput()),
            ];
        } catch (ProcessStartFailedException $exception) {
            return [
                'exit_code' => 127,
                'output' => $exception->getMessage(),
            ];
        }
    }
}
