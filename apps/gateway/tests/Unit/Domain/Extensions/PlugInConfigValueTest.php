<?php

declare(strict_types=1);

use App\Domain\Extensions\ConfigValue;

it('accepts a printable config description without changing its grammar', function (string $value): void {
    expect(ConfigValue::printableLine($value))->toBeTrue();
})->with(['x', 'Close the cleanup gate', 'A description: /etc/orbit (100%)']);

it('refuses empty, padded and multiline config descriptions', function (string $value): void {
    expect(ConfigValue::printableLine($value))->toBeFalse();
})->with(['', ' padded', 'padded ', "line\nbreak", "line\rbreak", "tab\there", "nul\0byte", 'é']);

it('accepts IPv4 config addresses', function (string $value): void {
    expect(ConfigValue::ipv4($value))->toBeTrue();
})->with(['0.0.0.0', '10.44.0.1', '255.255.255.255']);

it('refuses out of range or compound config addresses', function (string $value): void {
    expect(ConfigValue::ipv4($value))->toBeFalse();
})->with(['', '256.1.1.1', '-1.2.3.4', '1.2.3', '01.2.3.4', '::1', '10.44.0.1/24', '10.44.0.1,1.2.3.4', '10.44.0.1;id', "10.44.0.1\n", ' 10.44.0.1']);
