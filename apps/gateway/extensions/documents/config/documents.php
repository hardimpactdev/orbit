<?php

declare(strict_types=1);

return [
    // The private cleanup permit and execution lock. It lives outside database backups (docs/reference/project-documents.md).
    'cleanup_runtime' => env('APP_ENV') === 'testing'
        ? env('ORBIT_DOCUMENT_CLEANUP_RUNTIME', '/run/orbit/project-documents')
        : '/run/orbit/project-documents',

    // The `documents` filesystem disk. DocumentsServiceProvider copies it to filesystems.disks.documents.
    'disk' => [
        'driver' => 'documents',
        'version' => 'latest',
        'signature_version' => 'v4',
        'use_path_style_endpoint' => true,
        'retries' => 0,
        'http' => ['connect_timeout' => 2, 'timeout' => 5, 'read_timeout' => 5, 'allow_redirects' => false],
        'request_checksum_calculation' => 'when_required',
        'response_checksum_validation' => 'when_required',
        'throw' => true,
        'report' => false,
    ],
];
