<?php

declare(strict_types=1);

namespace App\Services\Updates;

use JsonException;

/** @mago-expect lint:mixed-assignment */
final class PendingDesktopUpdateHandoff
{
    public const string InstallModeRestartReady = 'restart-ready';

    public const string InstallModeAutomatic = 'automatic';

    /**
     * @param  array<string, mixed>  $payload
     */
    public static function fromArray(array $payload): self
    {
        return new self($payload);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function __construct(
        private array $payload,
    ) {
        $this->assertPayload($this->payload);
    }

    public function write(string $path): void
    {
        $this->assertSafeHandoffPath($path);
        $this->assertPayload($this->payload);

        $directory = dirname($path);

        if (! is_dir($directory) && ! mkdir($directory, 0700, true) && ! is_dir($directory)) {
            throw new PendingDesktopUpdateHandoffFailure('Pending desktop update directory could not be created.');
        }

        chmod($directory, 0700);

        $encoded = json_encode($this->payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES)."\n";
        $temporaryPath = $path.'.tmp.'.bin2hex(random_bytes(8));

        try {
            if (file_put_contents($temporaryPath, $encoded, LOCK_EX) === false) {
                throw new PendingDesktopUpdateHandoffFailure('Pending desktop update handoff could not be written.');
            }

            chmod($temporaryPath, 0600);

            if (! rename($temporaryPath, $path)) {
                throw new PendingDesktopUpdateHandoffFailure('Pending desktop update handoff could not be replaced.');
            }
        } finally {
            if (is_file($temporaryPath)) {
                unlink($temporaryPath);
            }
        }
    }

    /**
     * @param  array<string, mixed>  $expected
     */
    public static function read(string $path, array $expected): self
    {
        $handoff = self::fromFile($path);
        $handoff->assertMatchesExpected($expected);

        return $handoff;
    }

    public static function fromFile(string $path): self
    {
        self::assertSafeHandoffPath($path);

        if (! is_file($path)) {
            throw new PendingDesktopUpdateHandoffFailure('Pending desktop update handoff is missing.');
        }

        $contents = file_get_contents($path);

        if (! is_string($contents) || trim($contents) === '') {
            throw new PendingDesktopUpdateHandoffFailure('Pending desktop update handoff is incomplete.');
        }

        try {
            /** @var mixed $decoded */
            $decoded = json_decode($contents, associative: true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new PendingDesktopUpdateHandoffFailure('Pending desktop update handoff is malformed.');
        }

        if (! is_array($decoded)) {
            throw new PendingDesktopUpdateHandoffFailure('Pending desktop update handoff is malformed.');
        }

        return new self(self::stringKeyedArray($decoded));
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return $this->payload;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function assertPayload(array $payload): void
    {
        $operationId = $payload['operation_id'] ?? null;
        $version = $payload['version'] ?? null;
        $installMode = $payload['install_mode'] ?? null;
        $desktop = $payload['desktop'] ?? null;
        $agent = $payload['agent'] ?? null;
        $cli = $payload['cli'] ?? null;

        if (! is_string($operationId) || trim($operationId) === '') {
            throw new PendingDesktopUpdateHandoffFailure('Pending desktop update operation id is invalid.');
        }

        if (! is_string($version) || trim($version) === '') {
            throw new PendingDesktopUpdateHandoffFailure('Pending desktop update version is invalid.');
        }

        if (
            ! is_string($installMode)
            || ! in_array($installMode, [self::InstallModeRestartReady, self::InstallModeAutomatic], true)
        ) {
            throw new PendingDesktopUpdateHandoffFailure('Pending desktop update install mode is invalid.');
        }

        $this->assertArtifactIdentity($desktop, 'desktop');
        $this->assertArtifactIdentity($agent, 'agent');
        $this->assertArtifactIdentity($cli, 'cli');

        if (! is_array($desktop)) {
            throw new PendingDesktopUpdateHandoffFailure('Pending desktop update desktop identity is incomplete.');
        }

        $signature = $desktop['signature'] ?? null;
        $stagedPath = $desktop['staged_path'] ?? null;

        if (! is_string($signature) || trim($signature) === '') {
            throw new PendingDesktopUpdateHandoffFailure('Pending desktop update signature is invalid.');
        }

        if (! is_string($stagedPath)) {
            throw new PendingDesktopUpdateHandoffFailure('Pending desktop update staged path is invalid.');
        }

        self::assertSafeStagedPath($stagedPath);
    }

    /**
     * @param  array<string, mixed>  $expected
     */
    private function assertMatchesExpected(array $expected): void
    {
        foreach (['operation_id', 'version', 'build_id'] as $field) {
            if (! array_key_exists($field, $expected)) {
                continue;
            }

            if (($this->payload[$field] ?? null) !== $expected[$field]) {
                throw new PendingDesktopUpdateHandoffFailure('Pending desktop update identity is stale.');
            }
        }
    }

    private function assertArtifactIdentity(mixed $artifact, string $label): void
    {
        if (! is_array($artifact)) {
            throw new PendingDesktopUpdateHandoffFailure("Pending desktop update {$label} identity is incomplete.");
        }

        $sha256 = $artifact['sha256'] ?? null;

        if (! is_string($sha256) || preg_match('/^[a-f0-9]{64}$/', $sha256) !== 1) {
            throw new PendingDesktopUpdateHandoffFailure("Pending desktop update {$label} hash is invalid.");
        }
    }

    private static function assertSafeHandoffPath(string $path): void
    {
        self::assertSafeAbsolutePath($path, 'handoff');

        if (basename($path) !== 'pending-desktop-update.json' || ! str_contains($path, '/.config/orbit/')) {
            throw new PendingDesktopUpdateHandoffFailure('Pending desktop update handoff path is unsafe.');
        }
    }

    private static function assertSafeStagedPath(string $path): void
    {
        self::assertSafeAbsolutePath($path, 'staged artifact');

        if (! str_contains($path, '/.local/share/orbit/updates/')) {
            throw new PendingDesktopUpdateHandoffFailure('Pending desktop update staged path is unsafe.');
        }
    }

    /**
     * @param  array<array-key, mixed>  $value
     * @return array<string, mixed>
     */
    private static function stringKeyedArray(array $value): array
    {
        $stringKeyed = [];

        foreach ($value as $key => $item) {
            if (! is_string($key)) {
                throw new PendingDesktopUpdateHandoffFailure('Pending desktop update handoff is malformed.');
            }

            $stringKeyed[$key] = $item;
        }

        return $stringKeyed;
    }

    private static function assertSafeAbsolutePath(string $path, string $label): void
    {
        if (
            $path === ''
            || ! str_starts_with($path, '/')
            || str_contains($path, "\0")
            || str_contains($path, '..')
        ) {
            throw new PendingDesktopUpdateHandoffFailure("Pending desktop update {$label} path is unsafe.");
        }
    }
}
