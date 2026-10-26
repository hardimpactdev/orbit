<?php

declare(strict_types=1);

use Aws\S3\S3Client;
use Orbit\Extensions\Documents\Infrastructure\DocumentsFilesystem;
use Orbit\Extensions\Documents\Models\ProjectDocumentStorage;
use Symfony\Component\Process\Process;
use Tests\Support\SubprocessFixture;

/** @return array{Process, S3Client, ProjectDocumentStorage} */
function probe_transport_fixture(string $mode): array
{
    $server = new Process([PHP_BINARY, base_path('tests/Fixtures/ProjectDocuments/probe_http_server.php'), $mode, config()->string('orbit.home').'/http-state.json'], timeout: 60);
    $server->start();
    $port = SubprocessFixture::line($server);
    if (! ctype_digit($port)) {
        $server->stop();
        throw new RuntimeException('Loopback S3 transport fixture did not start.');
    }
    $storage = new ProjectDocumentStorage([
        'endpoint' => ($mode === 'tls-stall' ? 'https' : 'http').'://127.0.0.1:'.$port,
        'region' => 'europe-2', 'bucket' => 'fixture-bucket',
        'access_key_id' => 'fixture-access', 'secret_access_key' => 'fixture-secret',
    ]);

    return [$server, app(DocumentsFilesystem::class)->forConfiguration($storage)->getClient(), $storage];
}

function probe_transport_state(): array
{
    return json_decode(file_get_contents(config()->string('orbit.home').'/http-state.json'), true, flags: JSON_THROW_ON_ERROR);
}
