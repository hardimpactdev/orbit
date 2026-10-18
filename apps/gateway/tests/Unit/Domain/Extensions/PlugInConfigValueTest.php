<?php

declare(strict_types=1);

use App\Domain\Extensions\ConfigValue;

it('accepts a printable config description without changing its grammar', function (string $value): void {
    expect(ConfigValue::printableLine($value))->toBeTrue();
})->with(['x', 'Close the cleanup gate', 'A description: /etc/orbit (100%)']);

it('refuses empty, padded and multiline config descriptions', function (string $value): void {
    expect(ConfigValue::printableLine($value))->toBeFalse();
})->with(['', ' padded', 'padded ', "line\nbreak", "line\rbreak", "tab\there", "nul\0byte", 'é']);

it('accepts config slugs', function (string $value): void {
    expect(ConfigValue::slug($value))->toBeTrue();
})->with(['a', 'collector', 'extension-2-start', '123']);

it('refuses config slug separators', function (string $value): void {
    expect(ConfigValue::slug($value))->toBeFalse();
})->with(['', '-x', 'x-', 'x--y', 'Upper', 'a/b', 'a,b', 'a=b', 'a;b', 'a b', 'a.b', "a\nb", "a\0b"]);

it('accepts DNS config names', function (string $value): void {
    expect(ConfigValue::dnsName($value))->toBeTrue();
})->with(['localhost', 'collector.example.test', 'Example.TEST.', 'a-1.test', str_repeat('a', 63).'.test', implode('.', [str_repeat('a', 63), str_repeat('b', 63), str_repeat('c', 63), str_repeat('d', 61)])]);

it('refuses DNS config separators and invalid label lengths', function (string $value): void {
    expect(ConfigValue::dnsName($value))->toBeFalse();
})->with(['', '.', 'a..test', 'a.test..', '-a.test', 'a-.test', '*.test', 'a_b.test', 'https://a.test', 'a.test:443', 'a/b', 'a,b', 'a=b', 'a#b', 'a b', "a\nb", "a\0b", str_repeat('a', 64).'.test', implode('.', array_fill(0, 4, str_repeat('a', 63)))]);

it('accepts IPv4 config addresses', function (string $value): void {
    expect(ConfigValue::ipv4($value))->toBeTrue();
})->with(['0.0.0.0', '10.44.0.1', '255.255.255.255']);

it('refuses out of range or compound config addresses', function (string $value): void {
    expect(ConfigValue::ipv4($value))->toBeFalse();
})->with(['', '256.1.1.1', '-1.2.3.4', '1.2.3', '01.2.3.4', '::1', '10.44.0.1/24', '10.44.0.1,1.2.3.4', '10.44.0.1;id', "10.44.0.1\n", ' 10.44.0.1']);

it('checks config port bounds', function (int $value, bool $valid): void {
    expect(ConfigValue::port($value))->toBe($valid);
})->with([[1, true], [443, true], [65535, true], [0, false], [-1, false], [65536, false], [PHP_INT_MAX, false]]);
