<?php

declare(strict_types=1);

use App\Services\Node\AuthorizedKeysInstaller;

describe(AuthorizedKeysInstaller::class, function (): void {
    it('creates ssh directory and appends the gateway public key idempotently', function (): void {
        $home = sys_get_temp_dir().'/orbit-authorized-keys-'.bin2hex(random_bytes(4));
        $key = 'ssh-ed25519 AAAAC3NzaGatewayKey orbit-gateway';

        mkdir($home, 0777, true);

        $installer = app(AuthorizedKeysInstaller::class);

        $first = $installer->install($home, $key);
        $second = $installer->install($home, $key);

        $contents = file("{$home}/.ssh/authorized_keys", FILE_IGNORE_NEW_LINES);

        expect($first)
            ->toBeTrue()
            ->and($second)
            ->toBeFalse()
            ->and($contents)
            ->toBe([$key])
            ->and(substr(sprintf('%o', fileperms("{$home}/.ssh")), -4))
            ->toBe('0700')
            ->and(substr(sprintf('%o', fileperms("{$home}/.ssh/authorized_keys")), -4))
            ->toBe('0600');
    });

    it('fails clearly when an existing authorized keys file cannot be read', function (): void {
        $home = sys_get_temp_dir().'/orbit-authorized-keys-unreadable-'.bin2hex(random_bytes(4));
        $sshDirectory = "{$home}/.ssh";
        $authorizedKeys = "{$sshDirectory}/authorized_keys";

        mkdir($sshDirectory, 0700, true);
        file_put_contents($authorizedKeys, 'ssh-ed25519 existing-key');
        chmod($authorizedKeys, 0000);

        try {
            expect(
                fn (): bool => app(AuthorizedKeysInstaller::class)->install(
                    $home,
                    'ssh-ed25519 AAAAC3NzaGatewayKey orbit-gateway',
                ),
            )
                ->toThrow(RuntimeException::class, "Could not read {$authorizedKeys}.");
        } finally {
            chmod($authorizedKeys, 0600);
        }
    });
});
