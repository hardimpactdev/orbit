<?php

declare(strict_types=1);

use App\Domain\TaskVms\TaskVmException;
use App\Infrastructure\TaskVms\TaskVmCloudInit;

const CLOUD_INIT_KEY = 'ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAIFakeGatewayPublicKeyMaterial0123456789abcd orbit@gateway';

it('renders only the orbit user and the Gateway key for a task VM, whose base image already put orbit in incus-admin', function (): void {
    expect(new TaskVmCloudInit()->render(CLOUD_INIT_KEY))->toBe(<<<'YAML'
        #cloud-config
        {
            "users": [
                {
                    "name": "orbit",
                    "lock_passwd": true,
                    "shell": "/bin/bash",
                    "sudo": "ALL=(ALL) NOPASSWD:ALL",
                    "ssh_authorized_keys": [
                        "ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAIFakeGatewayPublicKeyMaterial0123456789abcd orbit@gateway"
                    ]
                }
            ]
        }

        YAML);
});

it('declares the builder account for native UID and GID verification without running a script', function (): void {
    expect(new TaskVmCloudInit()->renderImageBuilder(CLOUD_INIT_KEY))->toBe(<<<'YAML'
        #cloud-config
        {
            "users": [
                {
                    "name": "orbit",
                    "lock_passwd": true,
                    "shell": "/bin/bash",
                    "sudo": "ALL=(ALL) NOPASSWD:ALL",
                    "ssh_authorized_keys": [
                        "ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAIFakeGatewayPublicKeyMaterial0123456789abcd orbit@gateway"
                    ],
                    "uid": 1000,
                    "no_user_group": false
                }
            ]
        }

        YAML);
});

it('refuses a Gateway key that is not one public key line', function (string $key): void {
    expect(fn (): string => new TaskVmCloudInit()->render($key))
        ->toThrow(TaskVmException::class, 'The Gateway SSH public key is not a single OpenSSH public key line.')
        ->and(fn (): string => new TaskVmCloudInit()->renderImageBuilder($key))
        ->toThrow(TaskVmException::class, 'The Gateway SSH public key is not a single OpenSSH public key line.');
})->with([
    'empty' => [''],
    'private key' => ['-----BEGIN OPENSSH PRIVATE KEY-----'],
    'two lines' => ["ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAIFake orbit@gateway\nssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAIOther"],
    'trailing newline' => ["ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAIFake orbit@gateway\n"],
    'options prefix' => ['command="sh" ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAIFake'],
]);
