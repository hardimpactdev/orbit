<?php

declare(strict_types=1);

namespace App\Infrastructure\Vault;

use App\Domain\Vault\VaultException;
use SensitiveParameter;

/**
 * The parts of Bitwarden's client-side encryption the Gateway needs: the PBKDF2 master key, type 2
 * strings (AES-256-CBC with HMAC-SHA256), and type 4 strings (RSA-2048 OAEP with SHA-1) for the
 * organization key. Keys are 64 bytes: 32 for encryption, then 32 for the MAC.
 */
final readonly class BitwardenCrypto
{
    /**
     * The stretched master key, which decrypts the account's user key.
     *
     * @param  positive-int  $iterations
     */
    public function masterKey(
        #[SensitiveParameter] string $password,
        string $email,
        int $iterations,
    ): string {
        $master = hash_pbkdf2('sha256', $password, strtolower(trim($email)), $iterations, 32, true);

        return $this->expand($master, 'enc').$this->expand($master, 'mac');
    }

    /** Decrypts a type 2 string with a 64-byte key. */
    public function decrypt(string $encrypted, #[SensitiveParameter] string $key): string
    {
        $parts = $this->parts($encrypted, '2', 3);
        [$iv, $data, $mac] = array_map($this->decode(...), $parts);

        if (! hash_equals(hash_hmac('sha256', $iv.$data, $this->macKey($key), true), $mac)) {
            throw $this->unreadable();
        }

        $plain = openssl_decrypt($data, 'aes-256-cbc', $this->encryptionKey($key), OPENSSL_RAW_DATA, $iv);

        if ($plain === false) {
            throw $this->unreadable();
        }

        return $plain;
    }

    /** Encrypts as a type 2 string with a 64-byte key. */
    public function encrypt(#[SensitiveParameter] string $plain, #[SensitiveParameter] string $key): string
    {
        $iv = random_bytes(16);
        $data = openssl_encrypt($plain, 'aes-256-cbc', $this->encryptionKey($key), OPENSSL_RAW_DATA, $iv);

        if ($data === false) {
            throw new VaultException('vault.unreachable', 'The Gateway could not encrypt a Vaultwarden item.', 502);
        }

        $mac = hash_hmac('sha256', $iv.$data, $this->macKey($key), true);

        return '2.'.base64_encode($iv).'|'.base64_encode($data).'|'.base64_encode($mac);
    }

    /** Decrypts a type 4 string with a PKCS#8 DER private key. */
    public function decryptWithPrivateKey(string $encrypted, #[SensitiveParameter] string $privateKeyDer): string
    {
        $data = $this->decode($this->parts($encrypted, '4', 1)[0]);
        $pem = "-----BEGIN PRIVATE KEY-----\n".chunk_split(base64_encode($privateKeyDer), 64, "\n")."-----END PRIVATE KEY-----\n";
        $key = openssl_pkey_get_private($pem);

        if ($key === false || ! openssl_private_decrypt($data, $plain, $key, OPENSSL_PKCS1_OAEP_PADDING) || ! is_string($plain)) {
            throw $this->unreadable();
        }

        return $plain;
    }

    /** HKDF-Expand for one 32-byte block, as Bitwarden stretches the master key. */
    private function expand(#[SensitiveParameter] string $key, string $info): string
    {
        return hash_hmac('sha256', $info."\x01", $key, true);
    }

    /** @return list<string> */
    private function parts(string $encrypted, string $type, int $count): array
    {
        $dot = strpos($encrypted, '.');

        if ($dot === false || substr($encrypted, 0, $dot) !== $type) {
            throw $this->unreadable();
        }

        $parts = explode('|', substr($encrypted, $dot + 1));

        if (count($parts) !== $count) {
            throw $this->unreadable();
        }

        return $parts;
    }

    private function decode(string $value): string
    {
        $decoded = base64_decode($value, true);

        if ($decoded === false) {
            throw $this->unreadable();
        }

        return $decoded;
    }

    private function encryptionKey(#[SensitiveParameter] string $key): string
    {
        $this->assertKey($key);

        return substr($key, 0, 32);
    }

    private function macKey(#[SensitiveParameter] string $key): string
    {
        $this->assertKey($key);

        return substr($key, 32, 32);
    }

    private function assertKey(#[SensitiveParameter] string $key): void
    {
        if (strlen($key) !== 64) {
            throw $this->unreadable();
        }
    }

    private function unreadable(): VaultException
    {
        return new VaultException(
            'vault.login_failed',
            'The master password does not unlock the Vaultwarden account, or a key is unreadable.',
            422,
        );
    }
}
