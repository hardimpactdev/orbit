<?php

declare(strict_types=1);

use App\Domain\TaskVms\TaskVmException;
use App\Infrastructure\TaskVms\TaskVmCloudInit;

const CLOUD_INIT_KEY = 'ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAIFakeGatewayPublicKeyMaterial0123456789abcd orbit@gateway';

it('renders only the orbit user in incus-admin and the Gateway key for a task VM, which boots from the base image', function (): void {
    expect(new TaskVmCloudInit()->render(CLOUD_INIT_KEY))->toBe(<<<'YAML'
        #cloud-config
        {
            "bootcmd": [
                [
                    "sh",
                    "-c",
                    "getent group incus-admin >/dev/null || groupadd --system incus-admin; if id orbit >/dev/null 2>&1; then usermod -aG incus-admin orbit; fi"
                ]
            ],
            "users": [
                {
                    "name": "orbit",
                    "lock_passwd": true,
                    "shell": "/bin/bash",
                    "sudo": "ALL=(ALL) NOPASSWD:ALL",
                    "ssh_authorized_keys": [
                        "ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAIFakeGatewayPublicKeyMaterial0123456789abcd orbit@gateway"
                    ],
                    "groups": "incus-admin"
                }
            ]
        }

        YAML);
});

it('pins orbit to uid and gid 1000 without installing packages in builder cloud-init', function (): void {
    expect(new TaskVmCloudInit()->renderImageBuilder(CLOUD_INIT_KEY))->toBe(<<<'YAML'
        #cloud-config
        {
            "bootcmd": [
                [
                    "sh",
                    "-c",
                    "getent group orbit >/dev/null || groupadd --gid 1000 orbit"
                ]
            ],
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
                    "primary_group": "orbit",
                    "no_user_group": true
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
