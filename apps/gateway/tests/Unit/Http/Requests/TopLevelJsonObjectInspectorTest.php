<?php

declare(strict_types=1);

use App\Http\Requests\TopLevelJsonObjectInspector;

it('returns the exact decoded top-level object', function (): void {
    $payload = new TopLevelJsonObjectInspector()->inspect(
        '{"role":"app-dev","converge_existing":false}',
        ['role', 'converge_existing'],
    );

    expect($payload)->toBe([
        'role' => 'app-dev',
        'converge_existing' => false,
    ]);
});

it('preserves escaped strings and nested values while finding only top-level keys', function (array $payload): void {
    $json = json_encode($payload, JSON_THROW_ON_ERROR);

    expect(new TopLevelJsonObjectInspector()->inspect($json, array_keys($payload)))->toBe($payload);
})->with([
    'escaped quote' => [['role' => 'left"right', 'after' => true]],
    'trailing backslash' => [['role' => 'left\\', 'after' => true]],
    'backslash before quote' => [['role' => 'left\\"right', 'after' => true]],
    'JSON punctuation in a string' => [['role' => '","unsupported":[{"role":"other"}]', 'after' => true]],
    'nested objects and lists' => [['role' => ['role' => ['[', '}', '\\', '"']], 'after' => true]],
    'unicode' => [['role' => 'héllo 世界', 'after' => true]],
]);

it('recognizes an object after every JSON whitespace character', function (): void {
    expect(new TopLevelJsonObjectInspector()->inspect(" \t\r\n{\"role\":\"app-dev\"}", ['role']))
        ->toBe(['role' => 'app-dev']);
    expect(new TopLevelJsonObjectInspector()->inspect(' {} ', ['role']))->toBeEmpty();
});

it('treats an empty body as an empty object', function (): void {
    expect(new TopLevelJsonObjectInspector()->inspect('', ['force', 'purge_data']))
        ->toBeEmpty();
});

it('rejects malformed JSON and non-object top-level values', function (string $json): void {
    expect(fn (): array => new TopLevelJsonObjectInspector()->inspect($json, ['role']))
        ->toThrow(UnexpectedValueException::class, 'The request body must be a valid JSON object.');
})->with([
    'malformed object' => ['{"role":"app-dev"'],
    'list' => ['["app-dev"]'],
    'empty list' => ['[]'],
    'list after whitespace' => [" \t\r\n[]"],
    'scalar' => ['"app-dev"'],
    'null' => ['null'],
]);

it('rejects literal and escaped duplicate top-level keys without echoing either value', function (
    string $json,
): void {
    try {
        new TopLevelJsonObjectInspector()->inspect($json, ['role']);
    } catch (UnexpectedValueException $exception) {
        expect($exception->getMessage())
            ->toBe('The request body contains duplicate top-level keys.')
            ->not->toContain('first-sentinel', 'second-sentinel');

        return;
    }

    test()->fail('Expected duplicate keys to fail validation.');
})->with([
    'literal duplicate' => ['{"role":"first-sentinel","role":"second-sentinel"}'],
    'escaped duplicate' => ['{"role":"first-sentinel","r\\u006fle":"second-sentinel"}'],
]);

it('rejects unknown top-level keys without echoing their names or values', function (): void {
    try {
        new TopLevelJsonObjectInspector()->inspect(
            '{"role":"app-dev","raw-sentinel":"secret-sentinel"}',
            ['role'],
        );
    } catch (UnexpectedValueException $exception) {
        expect($exception->getMessage())
            ->toBe('The request body contains unsupported top-level keys.')
            ->not->toContain('raw-sentinel', 'secret-sentinel');

        return;
    }

    test()->fail('Expected unknown keys to fail validation.');
});
