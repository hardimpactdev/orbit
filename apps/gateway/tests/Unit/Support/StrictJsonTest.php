<?php

declare(strict_types=1);

use App\Support\StrictJson;

it('preserves object and list identity and complete unescaped UTF-8 values', function (): void {
    $value = StrictJson::decode('{"empty":{},"list":[],"text":"é\\u2028"}'."\n", 1024);
    expect($value)->toBeInstanceOf(stdClass::class)
        ->and($value->empty)->toBeInstanceOf(stdClass::class)
        ->and($value->list)->toBe([])->and($value->text)->toBe("é\u{2028}");
});

it('refuses duplicate escaped keys at every depth and incomplete or invalid JSON', function (string $bytes): void {
    expect(fn () => StrictJson::decode($bytes, 1024))->toThrow(InvalidArgumentException::class);
})->with([
    '{"a":1,"a":2}', '{"a":{"b":1,"\\u0062":2}}', '{"a":[{"é":1,"\\u00e9":2}]}',
    '{}{}', '{"a":1}garbage', '{"a":', '{"a":"unterminated}', '[1,]', '{"a":01}',
    '{"a":"\\ud800"}', "{\"a\":\"\xff\"}", '',
]);

it('refuses byte and complete-document depth overflow without returning a prefix', function (): void {
    expect(fn () => StrictJson::decode('{} ', 2))->toThrow(InvalidArgumentException::class)
        ->and(fn () => StrictJson::decode(str_repeat('[', 25).'0'.str_repeat(']', 25), 1024))->toThrow(InvalidArgumentException::class);
});
