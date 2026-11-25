<?php

declare(strict_types=1);

use App\Infrastructure\MachineSoftware\ConnServerReleases;
use Symfony\Component\Yaml\Yaml;

it('selects the exact host release independently of the container recipe', function (): void {
    $releases = new ConnServerReleases;
    $catalog = Yaml::parseFile(resource_path('machine-software/conn-server-releases.yaml'));

    expect($releases->find(null)?->version)->toBe($catalog['default'])
        ->and($releases->find('0.5.2')?->mac->artifact->algorithm)->toBe('sha256')
        ->and($releases->find('0.5.1')?->linux->version)->toBe('0.5.1')
        ->and($releases->find('0.5.0'))->toBeNull();
});

it('refuses host artifacts without a matching asset and checksum or signing identity', function (string $platform, string $field, mixed $value): void {
    $document = Yaml::parseFile(resource_path('machine-software/conn-server-releases.yaml'));
    $document['releases']['0.5.2'][$platform][$field] = $value;

    expect(fn () => new ConnServerReleases()->select($document, '0.5.2'))->toThrow(InvalidArgumentException::class);
})->with([
    ['linux_x64', 'sha256', ''],
    ['macos_arm64', 'sha256', null],
    ['macos_arm64', 'url', 'https://github.com/nckrtl/conn/releases/download/conn-server-v0.5.1/conn-server-0.5.1-macos-arm64.zip'],
    ['macos_arm64', 'url', 'https://github.com/nckrtl/conn/releases/download/conn-server-v0.5.2/conn-server-0.5.2-linux-x64.tar.gz'],
    ['macos_arm64', 'url', 'https://example.com/conn-server-0.5.2-macos-arm64.zip'],
    ['macos_arm64', 'bundle_id', 'com.example.other'],
    ['macos_arm64', 'team_id', 'invalid'],
]);

it('accepts a boolean canary gate only on the selected host release', function (): void {
    $document = Yaml::parseFile(resource_path('machine-software/conn-server-releases.yaml'));
    $document['releases']['0.5.2']['release_canary'] = true;
    $releases = new ConnServerReleases;
    expect($releases->select($document, '0.5.2')?->releaseCanary)->toBeTrue()
        ->and($releases->select($document, '0.5.1')?->releaseCanary)->toBeFalse();
    $document['releases']['0.5.2']['release_canary'] = 'true';
    expect(fn () => $releases->select($document, '0.5.2'))->toThrow(InvalidArgumentException::class);
});
