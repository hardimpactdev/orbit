<?php

declare(strict_types=1);

namespace App\Domain\Tools;

/**
 * The primary key fingerprints in a binary OpenPGP keyring, as `gpg --show-keys` names them: version 4 keys by SHA-1,
 * version 6 keys by SHA-256 (RFC 9580 section 5.5.4). A keyring Orbit can't read has none.
 */
final readonly class OpenPgpPrimaryKeys
{
    /** @return list<string> uppercase hex fingerprints */
    public static function fingerprints(string $keyring): array
    {
        $fingerprints = [];
        $offset = 0;
        $length = strlen($keyring);
        while ($offset < $length) {
            $header = ord($keyring[$offset]);
            if (($header & 0x80) === 0) {
                return [];
            }
            if (($header & 0x40) !== 0) {
                $tag = $header & 0x3F;
                [$size, $offset] = self::newLength($keyring, $offset + 1);
            } else {
                $tag = ($header >> 2) & 0x0F;
                $bytes = [1, 2, 4][$header & 0x03] ?? null;
                if ($bytes === null || $offset + 1 + $bytes > $length) {
                    return [];
                }
                $size = (int) hexdec(bin2hex(substr($keyring, $offset + 1, $bytes)));
                $offset += 1 + $bytes;
            }
            if ($size === null || $offset + $size > $length) {
                return [];
            }
            $body = substr($keyring, $offset, $size);
            $offset += $size;
            if ($tag !== 6) {
                continue;
            }
            $fingerprint = match (ord(($body[0] ?? "\0")[0])) {
                4 => sha1("\x99".pack('n', $size).$body),
                6 => hash('sha256', "\x9B".pack('N', $size).$body),
                default => null,
            };
            if ($fingerprint === null) {
                return [];
            }
            $fingerprints[] = strtoupper($fingerprint);
        }

        return $fingerprints;
    }

    /** @return array{?int, int} the body length, or null for a partial length, and the offset after it */
    private static function newLength(string $keyring, int $offset): array
    {
        $first = ord(($keyring[$offset] ?? "\xFF")[0]);

        return match (true) {
            $first < 192 => [$first, $offset + 1],
            $first < 224 => [(($first - 192) << 8) + ord(($keyring[$offset + 1] ?? "\0")[0]) + 192, $offset + 2],
            $first === 255 && strlen($keyring) >= $offset + 5 => [(int) hexdec(bin2hex(substr($keyring, $offset + 1, 4))), $offset + 5],
            default => [null, $offset],
        };
    }
}
