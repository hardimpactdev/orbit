<?php

declare(strict_types=1);

namespace App\Data\Nodes\RoleSettings;

use InvalidArgumentException;

final readonly class S3RoleSettings implements NodeRoleSettings
{
    public const string DefaultDataPath = '/srv/orbit/s3/data';

    private const array ALLOWED_DATA_ROOTS = [
        '/media/',
        '/mnt/',
        '/opt/orbit/',
        '/srv/',
        '/var/lib/orbit/',
    ];

    public function __construct(
        public string $dataPath = self::DefaultDataPath,
    ) {
        if (! self::isSafeDataPath($dataPath)) {
            throw new InvalidArgumentException('The s3 role requires a safe canonical data_path setting.');
        }
    }

    /**
     * @param  array<string, mixed>  $settings
     */
    public static function fromArray(array $settings): self
    {
        $unknownKeys = array_diff(array_keys($settings), ['data_path']);

        if ($unknownKeys !== []) {
            throw new InvalidArgumentException('The s3 role does not accept unknown settings.');
        }

        if (! array_key_exists('data_path', $settings)) {
            return new self;
        }

        $dataPath = $settings['data_path'];

        if (! is_string($dataPath) || ! self::isSafeDataPath($dataPath)) {
            throw new InvalidArgumentException('The s3 role requires a safe canonical data_path setting.');
        }

        return new self($dataPath);
    }

    #[\Override]
    public function toArray(): array
    {
        return ['data_path' => $this->dataPath];
    }

    private static function isSafeDataPath(string $path): bool
    {
        if ($path === '' || trim($path) !== $path || ! str_starts_with($path, '/')) {
            return false;
        }

        if (
            preg_match('/[\x00-\x1f\x7f]/', $path) === 1
            || str_contains($path, '//')
            || str_ends_with($path, '/')
            || array_any(
                explode('/', $path),
                static fn (string $part): bool => in_array($part, ['.', '..'], strict: true),
            )
        ) {
            return false;
        }

        return array_any(
            self::ALLOWED_DATA_ROOTS,
            static fn (string $root): bool => str_starts_with($path, $root),
        );
    }
}
