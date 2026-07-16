<?php

declare(strict_types=1);

use App\Data\Nodes\RoleSettings\S3RoleSettings;

it('defaults the data path when none is provided', function (): void {
    expect(S3RoleSettings::fromArray([])->toArray())
        ->toBe(['data_path' => '/srv/orbit/s3/data']);
});

it('accepts a custom absolute data path', function (): void {
    expect(S3RoleSettings::fromArray(['data_path' => '/mnt/disk/s3'])->toArray())
        ->toBe(['data_path' => '/mnt/disk/s3']);
});

it('rejects a non-absolute or empty data path', function (mixed $dataPath): void {
    expect(fn () => S3RoleSettings::fromArray(['data_path' => $dataPath]))
        ->toThrow(InvalidArgumentException::class, 'The s3 role requires a safe canonical data_path setting.');
})->with([
    'relative' => 'srv/orbit/s3/data',
    'empty' => '',
    'non-string' => 123,
]);

it('rejects root, traversal, aliases, and sensitive host paths', function (string $dataPath): void {
    expect(fn () => S3RoleSettings::fromArray(['data_path' => $dataPath]))
        ->toThrow(InvalidArgumentException::class, 'The s3 role requires a safe canonical data_path setting.');
})->with([
    'host root' => '/',
    'traversal' => '/srv/orbit/../etc',
    'repeated separator' => '/srv//orbit/s3',
    'trailing separator' => '/srv/orbit/s3/',
    'control character' => "/srv/orbit/s3\nother",
    'system config' => '/etc/orbit/s3',
    'generic var data' => '/var/lib/s3',
    'temporary data' => '/tmp/s3',
]);

it('rejects unknown settings', function (): void {
    expect(fn () => S3RoleSettings::fromArray(['data_path' => '/srv/orbit/s3/data', 'bucket' => 'orbit']))
        ->toThrow(InvalidArgumentException::class, 'The s3 role does not accept unknown settings.');
});
