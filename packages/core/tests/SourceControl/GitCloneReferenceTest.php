<?php

declare(strict_types=1);

use Orbit\Core\SourceControl\GitCloneReference;

it('accepts clone references', function (string $repository): void {
    expect(GitCloneReference::isValid($repository))->toBeTrue();
})->with([
    'GitHub shorthand' => ['owner/repository'],
    'HTTPS URL' => ['https://github.com/owner/repository.git'],
    'SSH URL' => ['ssh://git@github.com/owner/repository.git'],
    'scp-like SSH URL' => ['git@github.com:owner/repository.git'],
]);

it('rejects unsafe or unsupported clone references', function (string $repository): void {
    expect(GitCloneReference::isValid($repository))->toBeFalse();
})->with([
    'embedded HTTPS credentials' => ['https://token@github.com/owner/repository.git'],
    'embedded SSH password' => ['ssh://git:secret@github.com/owner/repository.git'],
    'query string' => ['https://github.com/owner/repository.git?token=secret'],
    'fragment' => ['https://github.com/owner/repository.git#main'],
    'scp-like query string' => ['git@github.com:owner/repository.git?token=secret'],
    'scp-like fragment' => ['git@github.com:owner/repository.git#main'],
    'control character' => ["https://github.com/owner/repository.git\n--upload-pack=payload"],
    'Unicode whitespace in URL' => ["https://github.com/owner/repository\u{00a0}evil.git"],
    'Unicode whitespace in scp-like URL' => ["git@github.com:owner/repository\u{00a0}evil.git"],
    'unsupported scheme' => ['file:///tmp/repository'],
    'plain path' => ['/tmp/repository'],
]);
