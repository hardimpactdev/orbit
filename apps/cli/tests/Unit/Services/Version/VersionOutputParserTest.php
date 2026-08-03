<?php

declare(strict_types=1);

use App\Services\Version\VersionOutputParser;

it('parses the version command JSON success envelope', function (): void {
    $parser = new VersionOutputParser;
    $output = json_encode([
        'success' => [
            'data' => [
                'version' => '0.1.190',
                'latest_version' => '0.1.191',
                'update_available' => true,
                'released_at' => null,
                'installed_at' => null,
            ],
            'meta' => [],
        ],
    ], JSON_THROW_ON_ERROR);

    expect($parser->fromJsonOutput($output))->toBe('0.1.190');
});

it('ignores human Version table rows and earlier dotted progress noise', function (): void {
    $parser = new VersionOutputParser;
    $output = <<<'TXT'
        pulling ghcr.io/hardimpactdev/orbit-reverb:1.2.3@sha256:deadbeef
        Version       9.9.9 (new version available: 1.2.3)
        Released at   unknown
        Installed at  unknown
        TXT;

    expect($parser->fromJsonOutput($output))->toBeNull();
});

it('parses JSON that follows progress lines', function (): void {
    $parser = new VersionOutputParser;
    $output = <<<'TXT'
        download_retry attempt=2
        {"success":{"data":{"version":"0.1.191","latest_version":null,"update_available":false,"released_at":null,"installed_at":null},"meta":[]}}
        TXT;

    expect($parser->fromJsonOutput($output))->toBe('0.1.191');
});

it('rejects flat top-level version JSON without the success.data envelope', function (): void {
    $parser = new VersionOutputParser;
    $output = json_encode([
        'version' => '0.1.190',
        'latest_version' => '0.1.191',
        'update_available' => true,
    ], JSON_THROW_ON_ERROR);

    expect($parser->fromJsonOutput($output))->toBeNull();
});
