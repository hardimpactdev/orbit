<?php

declare(strict_types=1);

use Orbit\Core\Security\SecretSummaryRedactor;

it('redacts mixed-case secret env assignments without touching ordinary prose', function (): void {
    $redactor = new SecretSummaryRedactor;
    $secret = 'base64:VerySecretApplicationKeyValue==';

    expect($redactor->redactString("export APP_KEY={$secret}"))
        ->toBe('export APP_KEY=<redacted>')
        ->and($redactor->redactString("app_key='{$secret}'"))
        ->toBe('app_key=<redacted>')
        ->and($redactor->redactString("PASSWORD=\"{$secret}\""))
        ->toBe('PASSWORD=<redacted>')
        ->and($redactor->redactString("password={$secret}"))
        ->toBe('password=<redacted>')
        ->and($redactor->redactString("SECRET={$secret}"))
        ->toBe('SECRET=<redacted>')
        ->and($redactor->redactString("api_token={$secret}"))
        ->toBe('api_token=<redacted>')
        ->and($redactor->redactString("API_KEY={$secret}"))
        ->toBe('API_KEY=<redacted>')
        ->and($redactor->redactString("TOKEN={$secret}"))
        ->toBe('TOKEN=<redacted>')
        ->and($redactor->redactString("access-token={$secret}"))
        ->toBe('access-token=<redacted>')
        ->and($redactor->redactString("user_password={$secret}"))
        ->toBe('user_password=<redacted>')
        ->and($redactor->redactString('The secretary shared status=ok with Version 0.1.190'))
        ->toBe('The secretary shared status=ok with Version 0.1.190')
        ->and($redactor->redactString("APP_KEY={$secret}"))
        ->not->toContain($secret);
});

it('redacts JSON key forms for password secret token and api-key siblings', function (): void {
    $redactor = new SecretSummaryRedactor;
    $secret = 'base64:NestedSecretKeyMaterial==';

    $stdout = json_encode([
        'success' => [
            'data' => [
                'app_key' => $secret,
                'password' => $secret,
                'api_key' => $secret,
                'api-token' => $secret,
                'status' => 'present',
            ],
        ],
    ], JSON_THROW_ON_ERROR);

    $redacted = $redactor->redactString($stdout);

    expect($redacted)
        ->not->toContain($secret)
        ->toContain('"app_key":"<redacted>"')
        ->toContain('"password":"<redacted>"')
        ->toContain('"api_key":"<redacted>"')
        ->toContain('"api-token":"<redacted>"')
        ->toContain('"status":"present"');
});

it('redacts human key-value lines for secret siblings', function (): void {
    $redactor = new SecretSummaryRedactor;
    $secret = 'plain-secret-value';

    expect($redactor->redactString("app_key: {$secret}\nstatus: ok"))
        ->toBe("app_key: <redacted>\nstatus: ok")
        ->and($redactor->redactString("Password: {$secret}"))
        ->toBe('Password: <redacted>')
        ->and($redactor->redactString("secret: {$secret}"))
        ->toBe('secret: <redacted>')
        ->and($redactor->redactString("token: {$secret}"))
        ->toBe('token: <redacted>')
        ->and($redactor->redactString("api-key: {$secret}"))
        ->toBe('api-key: <redacted>')
        ->and($redactor->redactString('Version       0.1.190'))
        ->toBe('Version       0.1.190')
        ->and($redactor->redactString('message: password policy requires rotation'))
        ->toBe('message: password policy requires rotation');
});

it('redacts nested arrays by forbidden keys and string values while preserving ordinary fields', function (): void {
    $redactor = new SecretSummaryRedactor;
    $secret = 'base64:NestedSecretKeyMaterial==';

    expect($redactor->redactArray([
        'stdout' => "APP_KEY={$secret} PASSWORD={$secret}",
        'stderr' => "token={$secret}",
        'command_line' => "env API_KEY={$secret} orbit doctor",
        'nested' => [
            'app_key' => $secret,
            'password' => $secret,
            'secret' => $secret,
            'api_key' => $secret,
            'user_password' => $secret,
            'ok' => true,
            'status' => 'present',
            'deeper' => [
                'access_token' => $secret,
                'count' => 2,
            ],
        ],
        'APP_KEY' => $secret,
        'message' => 'ready',
    ]))->toMatchArray([
        'stdout' => 'APP_KEY=<redacted> PASSWORD=<redacted>',
        'stderr' => 'token=<redacted>',
        'command_line' => 'env API_KEY=<redacted> orbit doctor',
        'nested' => [
            'app_key' => '<redacted>',
            'password' => '<redacted>',
            'secret' => '<redacted>',
            'api_key' => '<redacted>',
            'user_password' => '<redacted>',
            'ok' => true,
            'status' => 'present',
            'deeper' => [
                'access_token' => '<redacted>',
                'count' => 2,
            ],
        ],
        'APP_KEY' => '<redacted>',
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
