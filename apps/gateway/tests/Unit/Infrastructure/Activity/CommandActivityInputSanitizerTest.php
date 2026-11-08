<?php

declare(strict_types=1);

use App\Infrastructure\Activity\CommandActivityInputSanitizer;

it('treats session identifiers as secret fields, as the Swamp redactor does', function (): void {
    $sanitized = (new CommandActivityInputSanitizer)->sanitizeProperties([
        'session' => 'abc123def',
        'sessionid' => 's-42',
        'PHPSESSID' => 'f00ba5',
        'admin_session' => 'opaque-session-value',
        'name' => 'orbit',
    ]);

    expect($sanitized)->toBe([
        'session' => '[REDACTED]',
        'sessionid' => '[REDACTED]',
        'PHPSESSID' => '[REDACTED]',
        'admin_session' => '[REDACTED]',
        'name' => 'orbit',
    ])
        ->and(CommandActivityInputSanitizer::hasSecretWord('sessionId'))->toBeTrue();
});
