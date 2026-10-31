<?php

declare(strict_types=1);

namespace App\Domain\TaskVms;

/** Existing provider bootstrap credential, generated as intent data without a process or file. */
final readonly class TaskVmProviderHostKey
{
    /** @return array{private:string,public:string} */
    public function generate(string $name): array
    {
        if (preg_match('/\Atvm-(?:[1-9][0-9]*|image-[0-9]{14}-(?:builder|smoke))\z/D', $name) !== 1) {
            throw new TaskVmException('task_vm.host_key_failed', 'Provider host keys require an owned VM name.');
        }
        $pair = sodium_crypto_sign_keypair();
        $public = sodium_crypto_sign_publickey($pair);
        $secret = sodium_crypto_sign_secretkey($pair);
        $string = static fn (string $value): string => pack('N', strlen($value)).$value;
        $publicBlob = $string('ssh-ed25519').$string($public);
        $check = random_bytes(4);
        $privateBlob = $check.$check.$string('ssh-ed25519').$string($public).$string($secret).$string($name);
        $padding = 8 - strlen($privateBlob) % 8;
        for ($index = 1; $index <= $padding; $index++) {
            $privateBlob .= chr($index);
        }
        $blob = "openssh-key-v1\0".$string('none').$string('none').$string('').pack('N', 1).$string($publicBlob).$string($privateBlob);
        sodium_memzero($secret);
        sodium_memzero($pair);

        return ['private' => "-----BEGIN OPENSSH PRIVATE KEY-----\n".chunk_split(base64_encode($blob), 70, "\n")."-----END OPENSSH PRIVATE KEY-----\n",
            'public' => 'ssh-ed25519 '.base64_encode($publicBlob).' '.$name];
    }
}
