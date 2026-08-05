<?php

declare(strict_types=1);

use Orbit\Core\Security\SecretSummaryRedactor;

it('redacts mixed-case secret env assignments without touching ordinary prose', function (): void {
    $redactor = new SecretSummaryRedactor;
    $material = 'base64:VerySecretApplicationKeyValue==';
    $marker = SecretSummaryRedactor::REDACTED;

    expect($redactor->redactString("export APP_KEY={$material}"))
        ->toBe("export APP_KEY={$marker}")
        ->and($redactor->redactString("app_key='{$material}'"))
        ->toBe("app_key={$marker}")
        ->and($redactor->redactString("PASSWORD=\"{$material}\""))
        ->toBe("PASSWORD={$marker}")
        ->and($redactor->redactString("password={$material}"))
        ->toBe("password={$marker}")
        ->and($redactor->redactString("SECRET={$material}"))
        ->toBe("SECRET={$marker}")
        ->and($redactor->redactString("api_token={$material}"))
        ->toBe("api_token={$marker}")
        ->and($redactor->redactString("API_KEY={$material}"))
        ->toBe("API_KEY={$marker}")
        ->and($redactor->redactString("TOKEN={$material}"))
        ->toBe("TOKEN={$marker}")
        ->and($redactor->redactString("access-token={$material}"))
        ->toBe("access-token={$marker}")
        ->and($redactor->redactString("user_password={$material}"))
        ->toBe("user_password={$marker}")
        ->and($redactor->redactString('The secretary shared status=ok with Version 0.1.190'))
        ->toBe('The secretary shared status=ok with Version 0.1.190')
        ->and($redactor->redactString("APP_KEY={$material}"))
        ->not->toContain($material);
});

it('redacts JSON key forms for password secret token and api-key siblings', function (): void {
    $redactor = new SecretSummaryRedactor;
    $material = 'base64:NestedSecretKeyMaterial==';
    $marker = SecretSummaryRedactor::REDACTED;

    $stdout = json_encode([
        'success' => [
            'data' => [
                'app_key' => $material,
                'password' => $material,
                'api_key' => $material,
                'api-token' => $material,
                'status' => 'present',
            ],
        ],
    ], JSON_THROW_ON_ERROR);

    $redacted = $redactor->redactString($stdout);

    expect($redacted)
        ->not
        ->toContain($material)
        ->toContain('"app_key":"'.$marker.'"')
        ->toContain('"password":"'.$marker.'"')
        ->toContain('"api_key":"'.$marker.'"')
        ->toContain('"api-token":"'.$marker.'"')
        ->toContain('"status":"present"');
});

it('redacts human key-value lines for secret siblings', function (): void {
    $redactor = new SecretSummaryRedactor;
    $material = 'plain-fixture-value';
    $marker = SecretSummaryRedactor::REDACTED;

    expect($redactor->redactString("app_key: {$material}\nstatus: ok"))
        ->toBe("app_key: {$marker}\nstatus: ok")
        ->and($redactor->redactString("Password: {$material}"))
        ->toBe("Password: {$marker}")
        ->and($redactor->redactString("secret: {$material}"))
        ->toBe("secret: {$marker}")
        ->and($redactor->redactString("token: {$material}"))
        ->toBe("token: {$marker}")
        ->and($redactor->redactString("api-key: {$material}"))
        ->toBe("api-key: {$marker}")
        ->and($redactor->redactString('Version       0.1.190'))
        ->toBe('Version       0.1.190')
        ->and($redactor->redactString('message: password policy requires rotation'))
        ->toBe('message: password policy requires rotation');
});

it('redacts nested arrays by forbidden keys and string values while preserving ordinary fields', function (): void {
    $redactor = new SecretSummaryRedactor;
    $material = 'base64:NestedSecretKeyMaterial==';
    $marker = SecretSummaryRedactor::REDACTED;

    $input = [
        'stdout' => "APP_KEY={$material} PASSWORD={$material}",
        'stderr' => "token={$material}",
        'command_line' => "env API_KEY={$material} orbit doctor",
        'nested' => [
            'app_key' => $material,
            'password' => $material,
            'secret' => $material,
            'api_key' => $material,
            'user_password' => $material,
            'ok' => true,
            'status' => 'present',
            'deeper' => [
                'access_token' => $material,
                'count' => 2,
            ],
        ],
        'APP_KEY' => $material,
        'message' => 'ready',
    ];

    $expectedNested = [
        'app_key' => $marker,
        'password' => $marker,
        'secret' => $marker,
        'api_key' => $marker,
        'user_password' => $marker,
        'ok' => true,
        'status' => 'present',
        'deeper' => [
            'access_token' => $marker,
            'count' => 2,
        ],
    ];

    expect($redactor->redactArray($input))->toMatchArray([
        'stdout' => "APP_KEY={$marker} PASSWORD={$marker}",
        'stderr' => "token={$marker}",
        'command_line' => "env API_KEY={$marker} orbit doctor",
        'nested' => $expectedNested,
        'APP_KEY' => $marker,
        'message' => 'ready',
    ]);
});

it('does not treat ordinary words containing secret substrings as keys', function (): void {
    $redactor = new SecretSummaryRedactor;

    expect($redactor->isForbiddenKey('status'))
        ->toBeFalse()
        ->and($redactor->isForbiddenKey('message'))
        ->toBeFalse()
        ->and($redactor->isForbiddenKey('secretary'))
        ->toBeFalse()
        ->and($redactor->isForbiddenKey('token_count'))
        ->toBeFalse()
        ->and($redactor->isForbiddenKey('password'))
        ->toBeTrue()
        ->and($redactor->isForbiddenKey('user_password'))
        ->toBeTrue()
        ->and($redactor->isForbiddenKey('api-key'))
        ->toBeTrue()
        ->and($redactor->redactString('secretary=active token_count=3 status=ok'))
        ->toBe('secretary=active token_count=3 status=ok');
});

it('treats hyphenated app-key as a forbidden key for structured payloads', function (): void {
    $redactor = new SecretSummaryRedactor;
    $material = 'base64:HyphenatedAppKeyMaterial==';
    $marker = SecretSummaryRedactor::REDACTED;

    expect($redactor->isForbiddenKey('app-key'))
        ->toBeTrue()
        ->and($redactor->isForbiddenKey('APP-KEY'))
        ->toBeTrue()
        ->and($redactor->isForbiddenKey('app_key'))
        ->toBeTrue()
        ->and($redactor->isForbiddenKey('application-key'))
        ->toBeTrue()
        ->and($redactor->isForbiddenKey('password-hash'))
        ->toBeTrue()
        ->and($redactor->redactArray([
            'app-key' => $material,
            'public-key' => 'peer-public',
            'status' => 'ok',
        ]))
        ->toMatchArray([
            'app-key' => $marker,
            'public-key' => 'peer-public',
            'status' => 'ok',
        ]);
});

it('redacts password-hash string forms with hyphen or underscore', function (): void {
    $redactor = new SecretSummaryRedactor;
    $hash = '$argon2id$v=19$m=65536,t=3,p=4$hash$hash';
    $marker = SecretSummaryRedactor::REDACTED;

    expect($redactor->redactString("PASSWORD_HASH={$hash}"))
        ->toBe("PASSWORD_HASH={$marker}")
        ->and($redactor->redactString("password-hash={$hash}"))
        ->toBe("password-hash={$marker}")
        ->and($redactor->redactString("password_hash: {$hash}"))
        ->toBe("password_hash: {$marker}")
        ->and($redactor->redactString("password-hash: {$hash}"))
        ->toBe("password-hash: {$marker}")
        ->and($redactor->redactString('{"password-hash":"'.$hash.'"}'))
        ->toBe('{"password-hash":"'.$marker.'"}')
        ->not->toContain($hash);
});

it('redacts complete PEM blocks without requiring a secret-shaped key', function (): void {
    $redactor = new SecretSummaryRedactor;
    $marker = SecretSummaryRedactor::REDACTED;
    // Assemble PEM boundaries at runtime so the repository does not store a
    // contiguous private-key header that trips worktree secret scanning.
    $privateKeyLabel = 'PRIVATE KEY';
    $pemBody = 'MIIEvQIBADANBgkqhkiG9w0BAQEFAASCBKcwggSjAgEAAoIBAQC7';
    $pem = '-----BEGIN '.$privateKeyLabel."-----\n{$pemBody}\n-----END {$privateKeyLabel}-----";
    $certificate = <<<'PEM'
        -----BEGIN CERTIFICATE-----
        MIIDXTCCAkWgAwIBAgIJAJC1HiIAZAiIMA0GCSqGSIb3DQEBBQUA
        -----END CERTIFICATE-----
        PEM;

    expect($redactor->redactString("peer material:\n{$pem}\nstatus=ok"))
        ->toBe("peer material:\n{$marker}\nstatus=ok")
        ->and($redactor->redactString($certificate))
        ->toBe($marker)
        ->and($redactor->redactString("export KEY=\n{$pem}"))
        ->not
        ->toContain($pemBody)
        ->and($redactor->redactString('The BEGIN of the ceremony was delayed until END of day'))
        ->toBe('The BEGIN of the ceremony was delayed until END of day');
});

it('redacts Authorization Bearer and Proxy-Authorization without leaving credential tails', function (): void {
    $redactor = new SecretSummaryRedactor;
    $credential = 'eyJhbGciOiJIUzI1NiIsInR5cCI6IkpXVCJ9.payload.sig';
    $marker = SecretSummaryRedactor::REDACTED;

    expect($redactor->redactString("Authorization: Bearer {$credential}"))
        ->toBe("Authorization: {$marker}")
        ->and($redactor->redactString("authorization: bearer {$credential}"))
        ->toBe("authorization: {$marker}")
        ->and($redactor->redactString('Proxy-Authorization: Basic dXNlcjpwYXNz'))
        ->toBe("Proxy-Authorization: {$marker}")
        ->and($redactor->redactString("Proxy-Authorization: Bearer {$credential}"))
        ->toBe("Proxy-Authorization: {$marker}")
        ->and($redactor->redactString("curl -H 'Authorization: Bearer {$credential}' https://example.test"))
        ->toBe("curl -H 'Authorization: {$marker}' https://example.test")
        ->and($redactor->redactString("token exchange used Bearer {$credential} then continued"))
        ->toBe("token exchange used Bearer {$marker} then continued")
        ->and($redactor->redactString("Bearer {$credential}"))
        ->toBe("Bearer {$marker}")
        ->not
        ->toContain($credential)
        ->and($redactor->redactString('Bearers of good news arrived; the bearer of the torch finished'))
        ->toBe('Bearers of good news arrived; the bearer of the torch finished');
});
