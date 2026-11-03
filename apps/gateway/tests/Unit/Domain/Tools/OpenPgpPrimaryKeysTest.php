<?php

declare(strict_types=1);

use App\Domain\Tools\OpenPgpPrimaryKeys;

it('names the primary key of a binary keyring as gpg does, and none of its subkeys', function (): void {
    $keyring = (string) file_get_contents(dirname(__DIR__, 3).'/Fixtures/Tools/apt-signing-key.gpg');

    expect(OpenPgpPrimaryKeys::fingerprints($keyring))->toBe(['B9450981404CF6FEC99E5181D8861E7192E7461F']);
});

it('names a version 6 primary key by SHA-256 over its body', function (): void {
    // A version 6 public key packet: version, creation time, Ed25519 (27), a 32-byte key material length, the key.
    $body = "\x06\x66\x00\x00\x00\x1B\x00\x00\x00\x20".str_repeat("\x01", 32);
    $packet = "\xC6".chr(strlen($body)).$body;

    expect(OpenPgpPrimaryKeys::fingerprints($packet))->toBe([strtoupper(hash('sha256', "\x9B".pack('N', strlen($body)).$body))]);
});

it('reads no fingerprint from a keyring it cannot parse', function (string $keyring): void {
    expect(OpenPgpPrimaryKeys::fingerprints($keyring))->toBe([]);
})->with([
    'ASCII armour' => ["-----BEGIN PGP PUBLIC KEY BLOCK-----\n"],
    'a truncated packet' => ["\xC6\x40\x04"],
    'an unknown key version' => ["\xC6\x02\x03\x00"],
    'a partial length' => ["\xC6\xE1\x04"],
]);
