<?php

declare(strict_types=1);

namespace App\Domain\TaskVms;

use App\Models\TaskVm;

/**
 * The fixed network identity of a task VM slot (docs/cli/tasks/vm-slot-enrol.mmd): its WireGuard key, its SSH host key and its agent
 * secret. The Gateway makes them once, at the slot's first enrol, and keeps them encrypted on the slot's row; every enrol writes the same
 * ones onto the slot's root disk, so a rebuild from a new image keeps the identity. The Pi token and the model key are no part of it:
 * a claim mints them fresh (docs/cli/tasks/provision.mmd).
 */
final readonly class TaskVmSlotIdentity
{
    /** Makes the slot's identity once; an identity that exists stays as it is. */
    public function ensure(TaskVm $vm): TaskVm
    {
        $fill = [];
        if ($vm->wireguard_private_key === null) {
            $fill['wireguard_private_key'] = base64_encode(random_bytes(SODIUM_CRYPTO_SCALARMULT_SCALARBYTES));
        }
        if ($vm->ssh_host_private_key === null) {
            $fill['ssh_host_private_key'] = self::openSshPrivateKey(sodium_crypto_sign_keypair(), $vm->name);
        }
        if ($vm->agent_secret === null) {
            $fill['agent_secret'] = bin2hex(random_bytes(32));
        }
        if ($fill !== []) {
            $vm->forceFill($fill)->save();
        }

        return $vm;
    }

    /** The WireGuard public key of a base64 private key, as `wg pubkey` prints it: X25519 of the clamped scalar. */
    public static function wireguardPublicKey(string $privateKey): string
    {
        $scalar = base64_decode($privateKey, true);
        if (! is_string($scalar) || strlen($scalar) !== SODIUM_CRYPTO_SCALARMULT_SCALARBYTES) {
            throw new \InvalidArgumentException('A WireGuard private key is 32 bytes.');
        }
        $scalar[0] = chr(ord($scalar[0]) & 248);
        $scalar[31] = chr((ord($scalar[31]) & 127) | 64);

        return base64_encode(sodium_crypto_scalarmult_base($scalar));
    }

    /** The `ssh-ed25519 AAAA…` public line of an OpenSSH private key this class made. */
    public static function sshPublicKey(string $privateKey): string
    {
        return 'ssh-ed25519 '.base64_encode(self::publicBlob(self::sshPublicBytes($privateKey)));
    }

    /** The `SHA256:…` fingerprint of that public key, as `ssh-keygen -l` prints it. */
    public static function sshFingerprint(string $privateKey): string
    {
        return 'SHA256:'.rtrim(base64_encode(hash('sha256', self::publicBlob(self::sshPublicBytes($privateKey)), true)), '=');
    }

    /**
     * An unencrypted OpenSSH `openssh-key-v1` file for one Ed25519 key pair.
     *
     * @param  non-empty-string  $keypair
     */
    private static function openSshPrivateKey(string $keypair, string $comment): string
    {
        $secret = sodium_crypto_sign_secretkey($keypair);
        $public = sodium_crypto_sign_publickey($keypair);
        $check = random_bytes(4);
        $private = $check.$check.self::string('ssh-ed25519').self::string($public).self::string($secret).self::string($comment);
        // The padding counts 1, 2, 3 … up to the cipher block of 8.
        $private .= substr("\x01\x02\x03\x04\x05\x06\x07", 0, (8 - strlen($private) % 8) % 8);
        $body = "openssh-key-v1\0".self::string('none').self::string('none').self::string('').pack('N', 1)
            .self::string(self::publicBlob($public)).self::string($private);

        return "-----BEGIN OPENSSH PRIVATE KEY-----\n".chunk_split(base64_encode($body), 70, "\n")."-----END OPENSSH PRIVATE KEY-----\n";
    }

    private static function sshPublicBytes(string $privateKey): string
    {
        $body = base64_decode(preg_replace('/-----[A-Z ]+-----|\s/', '', $privateKey) ?? '', true);
        $prefix = "openssh-key-v1\0".self::string('none').self::string('none').self::string('').pack('N', 1);
        if (! is_string($body) || ! str_starts_with($body, $prefix)) {
            throw new \InvalidArgumentException('Not an unencrypted OpenSSH private key.');
        }
        $length = unpack('N', substr($body, strlen($prefix), 4));
        if (! is_array($length) || ! is_int($length[1] ?? null)) {
            throw new \InvalidArgumentException('Not an unencrypted OpenSSH private key.');
        }
        $blob = substr($body, strlen($prefix) + 4, $length[1]);

        return substr($blob, 4 + strlen('ssh-ed25519') + 4, SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES);
    }

    private static function publicBlob(string $public): string
    {
        return self::string('ssh-ed25519').self::string($public);
    }

    private static function string(string $value): string
    {
        return pack('N', strlen($value)).$value;
    }
}
