<?php

declare(strict_types=1);

use Illuminate\Console\OutputStyle;
use Illuminate\Contracts\Console\Kernel;
use Orbit\Core\Http\JsonEnvelope;
use Orbit\Core\Security\OperationToken;
use Orbit\Core\Security\OperationTokenSigner;
use Symfony\Component\Process\Process;

describe('internal wg-easy state command', function (): void {
    beforeEach(function (): void {
        configureWgEasyStateOperationTokenGuard();
        fakeGateway(fakeSuccessEnvelope([
            'allowed' => true,
        ]));

        $this->wgEasyStateTemp = sys_get_temp_dir().'/orbit-cli-wg-easy-state-'.bin2hex(random_bytes(8));
        mkdir($this->wgEasyStateTemp, recursive: true);

        putenv("ORBIT_WG_EASY_DB_PATH={$this->wgEasyStateTemp}/wg-easy.db");
    });

    afterEach(function (): void {
        putenv('ORBIT_WG_EASY_DB_PATH');
        unset($_ENV['ORBIT_WG_EASY_DB_PATH'], $_SERVER['ORBIT_WG_EASY_DB_PATH']);

        removeWgEasyStateTempDirectory($this->wgEasyStateTemp);
    });

    it('rejects a missing operation token before resolving the database path', function (): void {
        [$exitCode, $output] = runWgEasyStateCommand($this, [
            '--action' => 'update-user',
            '--host' => 'vpn.example.test',
            '--default-dns' => '["10.6.0.1"]',
            '--default-persistent-keepalive' => '25',
            '--json' => true,
        ]);

        expect($exitCode)
            ->toBe(1)
            ->and(json_decode($output, associative: true, flags: JSON_THROW_ON_ERROR))
            ->toBe(JsonEnvelope::failure(
                'missing_token',
                'Operation token is required.',
            ));
    });

    it('rejects an invalid operation token before opening the database', function (): void {
        config()->set('orbit.gateway.url', null);
        app()->forgetInstance('App\Services\GatewayApiClient');
        app()->forgetInstance('App\Services\Executor\OperationTokenGuard');

        [$exitCode, $output] = runWgEasyStateCommand($this, [
            '--action' => 'update-user',
            '--host' => 'vpn.example.test',
            '--default-dns' => '["10.6.0.1"]',
            '--default-persistent-keepalive' => '25',
            '--operation-token' => 'not-a-token',
            '--json' => true,
        ]);

        expect($exitCode)
            ->toBe(1)
            ->and(json_decode($output, associative: true, flags: JSON_THROW_ON_ERROR))
            ->toBe(JsonEnvelope::failure(
                'invalid_token',
                'Operation token is invalid.',
            ));
    });

    it('rejects an unsupported action before opening the database', function (): void {
        [$exitCode, $output] = runWgEasyStateCommand($this, [
            '--action' => 'evil',
            '--operation-token' => wgEasyStateSignedOperationToken(),
            '--json' => true,
        ]);

        expect($exitCode)
            ->toBe(1)
            ->and(json_decode($output, associative: true, flags: JSON_THROW_ON_ERROR))
            ->toBe(JsonEnvelope::failure(
                'invalid_action',
                'wg-easy state action must be one of: update-user, update-general, ensure-writable, upsert-peer, delete-peer, update-interface, update-user-password, update-session-password.',
                [
                    'action' => 'evil',
                    'allowed' => allowedWgEasyStateActions(),
                ],
            ));
    });

    it('rejects missing operation tokens for new actions before resolving the database path', function (array $parameters): void {
        [$exitCode, $output] = runWgEasyStateCommand($this, array_merge($parameters, [
            '--json' => true,
        ]));

        expect($exitCode)
            ->toBe(1)
            ->and(json_decode($output, associative: true, flags: JSON_THROW_ON_ERROR))
            ->toBe(JsonEnvelope::failure(
                'missing_token',
                'Operation token is required.',
            ));
    })->with([
        'upsert-peer' => [validWgEasyUpsertPeerOptions()],
        'delete-peer' => [validWgEasyDeletePeerOptions()],
        'update-interface' => [validWgEasyUpdateInterfaceOptions()],
        'update-user-password' => [validWgEasyPasswordOptions('update-user-password')],
        'update-session-password' => [validWgEasyPasswordOptions('update-session-password')],
    ]);

    it('rejects invalid operation tokens for new actions before opening the database', function (array $parameters): void {
        config()->set('orbit.gateway.url', null);
        app()->forgetInstance('App\Services\GatewayApiClient');
        app()->forgetInstance('App\Services\Executor\OperationTokenGuard');

        [$exitCode, $output] = runWgEasyStateCommand($this, array_merge($parameters, [
            '--operation-token' => 'not-a-token',
            '--json' => true,
        ]));

        expect($exitCode)
            ->toBe(1)
            ->and(json_decode($output, associative: true, flags: JSON_THROW_ON_ERROR))
            ->toBe(JsonEnvelope::failure(
                'invalid_token',
                'Operation token is invalid.',
            ));
    })->with([
        'upsert-peer' => [validWgEasyUpsertPeerOptions()],
        'delete-peer' => [validWgEasyDeletePeerOptions()],
        'update-interface' => [validWgEasyUpdateInterfaceOptions()],
        'update-user-password' => [validWgEasyPasswordOptions('update-user-password')],
        'update-session-password' => [validWgEasyPasswordOptions('update-session-password')],
    ]);

    it('updates user config values with parameterized statements', function (): void {
        $databasePath = "{$this->wgEasyStateTemp}/wg-easy.db";
        createWgEasyUserConfigDatabase($databasePath);

        $host = "vpn.example.test', default_dns = 'mutated";
        $defaultDns = '["10.6.0.1","1.1.1.1"]';

        [$exitCode, $output] = runWgEasyStateCommand($this, [
            '--action' => 'update-user',
            '--host' => $host,
            '--default-dns' => $defaultDns,
            '--default-persistent-keepalive' => '25',
            '--operation-token' => wgEasyStateSignedOperationToken(),
            '--json' => true,
        ]);

        $row = readWgEasyStateRow($databasePath, 'user_configs_table');

        expect($exitCode)
            ->toBe(0)
            ->and($output)
            ->toBe(json_encode(
                JsonEnvelope::success([
                    'action' => 'update-user',
                    'updated' => true,
                ]),
                JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES,
            ))
            ->and($row['host'])
            ->toBe($host)
            ->and($row['default_dns'])
            ->toBe($defaultDns)
            ->and($row['default_persistent_keepalive'])
            ->toBe(25);
    });

    it('updates general setup step with a parameterized statement', function (): void {
        $databasePath = "{$this->wgEasyStateTemp}/wg-easy.db";
        createWgEasyGeneralDatabase($databasePath);

        [$exitCode, $output] = runWgEasyStateCommand($this, [
            '--action' => 'update-general',
            '--setup-step' => '0',
            '--operation-token' => wgEasyStateSignedOperationToken(),
            '--json' => true,
        ]);

        $row = readWgEasyStateRow($databasePath, 'general_table');

        expect($exitCode)
            ->toBe(0)
            ->and($output)
            ->toBe(json_encode(
                JsonEnvelope::success([
                    'action' => 'update-general',
                    'updated' => true,
                ]),
                JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES,
            ))
            ->and($row['setup_step'])
            ->toBe(0);
    });

    it('upserts a peer with parameterized statements', function (): void {
        $databasePath = "{$this->wgEasyStateTemp}/wg-easy.db";
        $pdo = createWgEasyPeerDatabase($databasePath);
        insertWgEasyPeer($pdo, [
            'name' => 'old-gateway',
            'ipv4_address' => '10.6.0.2',
            'ipv6_address' => 'fdcc:ad94:bacf:61a4::cafe:2',
            'private_key' => 'old-private-key',
            'public_key' => 'old-public-key',
            'pre_shared_key' => 'old-pre-shared-key',
        ]);

        $name = "gateway', enabled = 0 --";

        [$exitCode, $output] = runWgEasyStateCommand($this, [
            '--action' => 'upsert-peer',
            '--name' => $name,
            '--ipv4' => '10.6.0.2',
            '--ipv6' => 'fdcc:ad94:bacf:61a4::cafe:2',
            '--private-key' => 'gateway-private-key',
            '--public-key' => 'gateway-public-key',
            '--pre-shared-key' => 'gateway-pre-shared-key',
            '--operation-token' => wgEasyStateSignedOperationToken(),
            '--json' => true,
        ]);

        $row = readWgEasyStateRow($databasePath, 'clients_table');

        expect($exitCode)
            ->toBe(0)
            ->and($output)
            ->toBe(json_encode(
                JsonEnvelope::success([
                    'action' => 'upsert-peer',
                    'upserted' => true,
                ]),
                JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES,
            ))
            ->and(countWgEasyStateRows($databasePath, 'clients_table'))
            ->toBe(1)
            ->and($row['name'])
            ->toBe($name)
            ->and($row['ipv4_address'])
            ->toBe('10.6.0.2')
            ->and($row['ipv6_address'])
            ->toBe('fdcc:ad94:bacf:61a4::cafe:2')
            ->and($row['private_key'])
            ->toBe('gateway-private-key')
            ->and($row['public_key'])
            ->toBe('gateway-public-key')
            ->and($row['pre_shared_key'])
            ->toBe('gateway-pre-shared-key')
            ->and($row['allowed_ips'])
            ->toBe('["0.0.0.0/0", "::/0"]')
            ->and($row['server_allowed_ips'])
            ->toBe('["10.6.0.2/32"]')
            ->and($row['persistent_keepalive'])
            ->toBe(25)
            ->and($row['mtu'])
            ->toBe(1420)
            ->and($row['dns'])
            ->toBe('["10.6.0.1"]')
            ->and($row['enabled'])
            ->toBe(1);
    });

    it('deletes a peer by one whitelisted identity with a parameterized statement', function (): void {
        $databasePath = "{$this->wgEasyStateTemp}/wg-easy.db";
        $pdo = createWgEasyPeerDatabase($databasePath);
        insertWgEasyPeer($pdo, [
            'name' => 'gateway',
            'ipv4_address' => '10.6.0.2',
            'ipv6_address' => 'fdcc:ad94:bacf:61a4::cafe:2',
            'private_key' => 'gateway-private-key',
            'public_key' => "gateway-public-key' OR 1 = 1 --",
            'pre_shared_key' => 'gateway-pre-shared-key',
        ]);
        insertWgEasyPeer($pdo, [
            'name' => 'control',
            'ipv4_address' => '10.6.0.3',
            'ipv6_address' => 'fdcc:ad94:bacf:61a4::cafe:3',
            'private_key' => 'control-private-key',
            'public_key' => 'control-public-key',
            'pre_shared_key' => 'control-pre-shared-key',
        ]);

        [$exitCode, $output] = runWgEasyStateCommand($this, [
            '--action' => 'delete-peer',
            '--public-key' => "gateway-public-key' OR 1 = 1 --",
            '--operation-token' => wgEasyStateSignedOperationToken(),
            '--json' => true,
        ]);

        $remaining = readWgEasyStateRow($databasePath, 'clients_table');

        expect($exitCode)
            ->toBe(0)
            ->and($output)
            ->toBe(json_encode(
                JsonEnvelope::success([
                    'action' => 'delete-peer',
                    'deleted' => true,
                ]),
                JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES,
            ))
            ->and(countWgEasyStateRows($databasePath, 'clients_table'))
            ->toBe(1)
            ->and($remaining['name'])
            ->toBe('control');
    });

    it('updates the wg0 interface cidr with a parameterized statement', function (): void {
        $databasePath = "{$this->wgEasyStateTemp}/wg-easy.db";
        createWgEasyInterfaceDatabase($databasePath);

        [$exitCode, $output] = runWgEasyStateCommand($this, [
            '--action' => 'update-interface',
            '--interface' => 'wg0',
            '--ipv4-cidr' => '10.6.0.0/24',
            '--operation-token' => wgEasyStateSignedOperationToken(),
            '--json' => true,
        ]);

        $row = readWgEasyStateRow($databasePath, 'interfaces_table');

        expect($exitCode)
            ->toBe(0)
            ->and($output)
            ->toBe(json_encode(
                JsonEnvelope::success([
                    'action' => 'update-interface',
                    'updated' => true,
                ]),
                JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES,
            ))
            ->and($row['name'])
            ->toBe('wg0')
            ->and($row['ipv4_cidr'])
            ->toBe('10.6.0.0/24');
    });

    it('updates the wg-easy user password hash without printing the value', function (): void {
        $databasePath = "{$this->wgEasyStateTemp}/wg-easy.db";
        createWgEasyUsersDatabase($databasePath);
        $passwordHash = validWgEasyPasswordHash();

        [$exitCode, $output] = runWgEasyStateCommand($this, [
            '--action' => 'update-user-password',
            '--password-hash' => $passwordHash,
            '--operation-token' => wgEasyStateSignedOperationToken(),
            '--json' => true,
        ]);

        $row = readWgEasyStateRow($databasePath, 'users_table');

        expect($exitCode)
            ->toBe(0)
            ->and($output)
            ->toBe(json_encode(
                JsonEnvelope::success([
                    'action' => 'update-user-password',
                    'updated' => true,
                ]),
                JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES,
            ))
            ->and($output)
            ->not
            ->toContain($passwordHash)
            ->and($row['password'])
            ->toBe($passwordHash);
    });

    it('updates the wg-easy session password hash without printing the value', function (): void {
        $databasePath = "{$this->wgEasyStateTemp}/wg-easy.db";
        createWgEasySessionPasswordDatabase($databasePath);
        $passwordHash = validWgEasyPasswordHash();

        [$exitCode, $output] = runWgEasyStateCommand($this, [
            '--action' => 'update-session-password',
            '--password-hash' => $passwordHash,
            '--operation-token' => wgEasyStateSignedOperationToken(),
            '--json' => true,
        ]);

        $row = readWgEasyStateRow($databasePath, 'general_table');

        expect($exitCode)
            ->toBe(0)
            ->and($output)
            ->toBe(json_encode(
                JsonEnvelope::success([
                    'action' => 'update-session-password',
                    'updated' => true,
                ]),
                JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES,
            ))
            ->and($output)
            ->not
            ->toContain($passwordHash)
            ->and($row['session_password'])
            ->toBe($passwordHash);
    });

    it('updates the wg-easy user password hash when the value is a valid argon2id hash', function (): void {
        $databasePath = "{$this->wgEasyStateTemp}/wg-easy.db";
        createWgEasyUsersDatabase($databasePath);
        $passwordHash = validWgEasyArgon2idPasswordHash();

        [$exitCode, $output] = runWgEasyStateCommand($this, [
            '--action' => 'update-user-password',
            '--password-hash' => $passwordHash,
            '--operation-token' => wgEasyStateSignedOperationToken(),
            '--json' => true,
        ]);

        $row = readWgEasyStateRow($databasePath, 'users_table');

        expect($exitCode)
            ->toBe(0)
            ->and($output)
            ->toBe(json_encode(
                JsonEnvelope::success([
                    'action' => 'update-user-password',
                    'updated' => true,
                ]),
                JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES,
            ))
            ->and($row['password'])
            ->toBe($passwordHash);
    });

    it('updates the wg-easy session password hash when the value is a valid argon2id hash', function (): void {
        $databasePath = "{$this->wgEasyStateTemp}/wg-easy.db";
        createWgEasySessionPasswordDatabase($databasePath);
        $passwordHash = validWgEasyArgon2idPasswordHash();

        [$exitCode, $output] = runWgEasyStateCommand($this, [
            '--action' => 'update-session-password',
            '--password-hash' => $passwordHash,
            '--operation-token' => wgEasyStateSignedOperationToken(),
            '--json' => true,
        ]);

        $row = readWgEasyStateRow($databasePath, 'general_table');

        expect($exitCode)
            ->toBe(0)
            ->and($output)
            ->toBe(json_encode(
                JsonEnvelope::success([
                    'action' => 'update-session-password',
                    'updated' => true,
                ]),
                JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES,
            ))
            ->and($row['session_password'])
            ->toBe($passwordHash);
    });

    it('accepts a structurally complete low cost argon2id user password hash because validation is format only', function (): void {
        $databasePath = "{$this->wgEasyStateTemp}/wg-easy.db";
        createWgEasyUsersDatabase($databasePath);
        $passwordHash = '$argon2id$v=19$m=1,t=1,p=1$abc$def';

        [$exitCode, $output] = runWgEasyStateCommand($this, [
            '--action' => 'update-user-password',
            '--password-hash' => $passwordHash,
            '--operation-token' => wgEasyStateSignedOperationToken(),
            '--json' => true,
        ]);

        $row = readWgEasyStateRow($databasePath, 'users_table');

        expect($exitCode)
            ->toBe(0)
            ->and($output)
            ->toBe(json_encode(
                JsonEnvelope::success([
                    'action' => 'update-user-password',
                    'updated' => true,
                ]),
                JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES,
            ))
            ->and($row['password'])
            ->toBe($passwordHash);
    });

    it('accepts a structurally complete low cost argon2id session password hash because validation is format only', function (): void {
        $databasePath = "{$this->wgEasyStateTemp}/wg-easy.db";
        createWgEasySessionPasswordDatabase($databasePath);
        $passwordHash = '$argon2id$v=19$m=1,t=1,p=1$abc$def';

        [$exitCode, $output] = runWgEasyStateCommand($this, [
            '--action' => 'update-session-password',
            '--password-hash' => $passwordHash,
            '--operation-token' => wgEasyStateSignedOperationToken(),
            '--json' => true,
        ]);

        $row = readWgEasyStateRow($databasePath, 'general_table');

        expect($exitCode)
            ->toBe(0)
            ->and($output)
            ->toBe(json_encode(
                JsonEnvelope::success([
                    'action' => 'update-session-password',
                    'updated' => true,
                ]),
                JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES,
            ))
            ->and($row['session_password'])
            ->toBe($passwordHash);
    });

    it('returns success when the database file is writable', function (): void {
        createWgEasyGeneralDatabase("{$this->wgEasyStateTemp}/wg-easy.db");

        [$exitCode, $output] = runWgEasyStateCommand($this, [
            '--action' => 'ensure-writable',
            '--operation-token' => wgEasyStateSignedOperationToken(),
            '--json' => true,
        ]);

        expect($exitCode)
            ->toBe(0)
            ->and($output)
            ->toBe(json_encode(
                JsonEnvelope::success([
                    'action' => 'ensure-writable',
                    'writable' => true,
                    'ownership_changed' => false,
                ]),
                JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES,
            ));
    });

    it('returns a failure envelope when the database file is not writable and is outside the chown allowlist', function (): void {
        $databasePath = "{$this->wgEasyStateTemp}/wg-easy.db";
        createWgEasyGeneralDatabase($databasePath);
        chmod($databasePath, 0444);

        [$exitCode, $output] = runWgEasyStateCommand($this, [
            '--action' => 'ensure-writable',
            '--operation-token' => wgEasyStateSignedOperationToken(),
            '--json' => true,
        ]);

        chmod($databasePath, 0644);

        expect($exitCode)
            ->toBe(1)
            ->and(json_decode($output, associative: true, flags: JSON_THROW_ON_ERROR))
            ->toBe(JsonEnvelope::failure(
                'database_unwritable',
                'wg-easy database is not writable.',
                ['reason' => 'path_not_chown_eligible'],
            ));
    });

    it('rejects invalid update-user options before opening the database', function (
        array $options,
        string $field,
    ): void {
        [$exitCode, $output] = runWgEasyStateCommand($this, array_merge([
            '--action' => 'update-user',
            '--operation-token' => wgEasyStateSignedOperationToken(),
            '--json' => true,
        ], $options));

        expect($exitCode)
            ->toBe(1)
            ->and(json_decode($output, associative: true, flags: JSON_THROW_ON_ERROR))
            ->toBe(JsonEnvelope::failure(
                'validation_failed',
                "The --{$field} option is invalid.",
                ['field' => $field],
            ));
    })->with([
        'non-string host' => [
            [
                '--host' => true,
                '--default-dns' => '["10.6.0.1"]',
                '--default-persistent-keepalive' => '25',
            ],
            'host',
        ],
        'missing default dns' => [
            [
                '--host' => 'vpn.example.test',
                '--default-persistent-keepalive' => '25',
            ],
            'default-dns',
        ],
        'non-integer keepalive' => [
            [
                '--host' => 'vpn.example.test',
                '--default-dns' => '["10.6.0.1"]',
                '--default-persistent-keepalive' => '25.5',
            ],
            'default-persistent-keepalive',
        ],
    ]);

    it('rejects invalid update-general options before opening the database', function (): void {
        [$exitCode, $output] = runWgEasyStateCommand($this, [
            '--action' => 'update-general',
            '--setup-step' => 'later',
            '--operation-token' => wgEasyStateSignedOperationToken(),
            '--json' => true,
        ]);

        expect($exitCode)
            ->toBe(1)
            ->and(json_decode($output, associative: true, flags: JSON_THROW_ON_ERROR))
            ->toBe(JsonEnvelope::failure(
                'validation_failed',
                'The --setup-step option is invalid.',
                ['field' => 'setup-step'],
            ));
    });

    it('rejects invalid new action options before opening the database', function (
        array $parameters,
        string $message,
        array $meta,
    ): void {
        [$exitCode, $output] = runWgEasyStateCommand($this, array_merge($parameters, [
            '--operation-token' => wgEasyStateSignedOperationToken(),
            '--json' => true,
        ]));

        expect($exitCode)
            ->toBe(1)
            ->and(json_decode($output, associative: true, flags: JSON_THROW_ON_ERROR))
            ->toBe(JsonEnvelope::failure(
                'validation_failed',
                $message,
                $meta,
            ));
    })->with([
        'upsert-peer missing public key' => [
            array_diff_key(validWgEasyUpsertPeerOptions(), ['--public-key' => true]),
            'The --public-key option is invalid.',
            ['field' => 'public-key'],
        ],
        'delete-peer multiple identities' => [
            array_merge(validWgEasyDeletePeerOptions(), ['--public-key' => 'gateway-public-key']),
            'Exactly one peer identity option is required.',
            ['field' => 'peer-identity', 'allowed' => ['name', 'public-key', 'ipv4']],
        ],
        'update-interface rejects other interface names' => [
            array_merge(validWgEasyUpdateInterfaceOptions(), ['--interface' => 'wg1']),
            'The --interface option is invalid.',
            ['field' => 'interface'],
        ],
        'update-user-password rejects raw password values' => [
            [
                '--action' => 'update-user-password',
                '--password-hash' => 'raw-password',
            ],
            invalidWgEasyPasswordHashMessage(),
            ['field' => 'password-hash'],
        ],
        'update-user-password rejects bare argon2id prefixes' => [
            [
                '--action' => 'update-user-password',
                '--password-hash' => '$argon2id$',
            ],
            invalidWgEasyPasswordHashMessage(),
            ['field' => 'password-hash'],
        ],
        'update-user-password rejects injected argon2id prefixes' => [
            [
                '--action' => 'update-user-password',
                '--password-hash' => '$argon2id$...$<injection>',
            ],
            invalidWgEasyPasswordHashMessage(),
            ['field' => 'password-hash'],
        ],
        'update-user-password rejects injected argon2i prefixes' => [
            [
                '--action' => 'update-user-password',
                '--password-hash' => '$argon2i$...$<injection>',
            ],
            invalidWgEasyPasswordHashMessage(),
            ['field' => 'password-hash'],
        ],
        'update-user-password rejects truncated bcrypt bodies' => [
            [
                '--action' => 'update-user-password',
                '--password-hash' => '$2y$12$short',
            ],
            invalidWgEasyPasswordHashMessage(),
            ['field' => 'password-hash'],
        ],
        'update-session-password rejects raw password values' => [
            [
                '--action' => 'update-session-password',
                '--password-hash' => 'raw-password',
            ],
            invalidWgEasyPasswordHashMessage(),
            ['field' => 'password-hash'],
        ],
        'update-session-password rejects bare argon2id prefixes' => [
            [
                '--action' => 'update-session-password',
                '--password-hash' => '$argon2id$',
            ],
            invalidWgEasyPasswordHashMessage(),
            ['field' => 'password-hash'],
        ],
        'update-session-password rejects injected argon2id prefixes' => [
            [
                '--action' => 'update-session-password',
                '--password-hash' => '$argon2id$...$<injection>',
            ],
            invalidWgEasyPasswordHashMessage(),
            ['field' => 'password-hash'],
        ],
        'update-session-password rejects injected argon2i prefixes' => [
            [
                '--action' => 'update-session-password',
                '--password-hash' => '$argon2i$...$<injection>',
            ],
            invalidWgEasyPasswordHashMessage(),
            ['field' => 'password-hash'],
        ],
        'update-session-password rejects truncated bcrypt bodies' => [
            [
                '--action' => 'update-session-password',
                '--password-hash' => '$2y$12$short',
            ],
            invalidWgEasyPasswordHashMessage(),
            ['field' => 'password-hash'],
        ],
    ]);

    it('returns database_missing for new actions when the database file is absent', function (array $parameters): void {
        [$exitCode, $output] = runWgEasyStateCommand($this, array_merge($parameters, [
            '--operation-token' => wgEasyStateSignedOperationToken(),
            '--json' => true,
        ]));

        expect($exitCode)
            ->toBe(1)
            ->and(json_decode($output, associative: true, flags: JSON_THROW_ON_ERROR))
            ->toBe(JsonEnvelope::failure(
                'database_missing',
                'wg-easy database does not exist.',
            ));
    })->with([
        'upsert-peer' => [validWgEasyUpsertPeerOptions()],
        'delete-peer' => [validWgEasyDeletePeerOptions()],
        'update-interface' => [validWgEasyUpdateInterfaceOptions()],
        'update-user-password' => [validWgEasyPasswordOptions('update-user-password')],
        'update-session-password' => [validWgEasyPasswordOptions('update-session-password')],
    ]);

    it('returns interface_not_found when upserting a peer without the pinned wg0 interface row', function (): void {
        $databasePath = "{$this->wgEasyStateTemp}/wg-easy.db";
        createWgEasyPeerDatabase($databasePath, insertInterface: false);

        [$exitCode, $output] = runWgEasyStateCommand($this, array_merge(validWgEasyUpsertPeerOptions(), [
            '--operation-token' => wgEasyStateSignedOperationToken(),
            '--json' => true,
        ]));

        expect($exitCode)
            ->toBe(1)
            ->and(json_decode($output, associative: true, flags: JSON_THROW_ON_ERROR))
            ->toBe(JsonEnvelope::failure(
                'interface_not_found',
                'wg-easy interface was not found.',
                ['interface' => 'wg0'],
            ));
    });

    it('returns peer_not_found when deleting a missing peer identity', function (): void {
        $databasePath = "{$this->wgEasyStateTemp}/wg-easy.db";
        createWgEasyPeerDatabase($databasePath);

        [$exitCode, $output] = runWgEasyStateCommand($this, array_merge(validWgEasyDeletePeerOptions(), [
            '--operation-token' => wgEasyStateSignedOperationToken(),
            '--json' => true,
        ]));

        expect($exitCode)
            ->toBe(1)
            ->and(json_decode($output, associative: true, flags: JSON_THROW_ON_ERROR))
            ->toBe(JsonEnvelope::failure(
                'peer_not_found',
                'wg-easy peer was not found.',
            ));
    });

    it('returns interface_not_found when updating a missing wg0 interface row', function (): void {
        $databasePath = "{$this->wgEasyStateTemp}/wg-easy.db";
        createWgEasyInterfaceDatabase($databasePath, insertInterface: false);

        [$exitCode, $output] = runWgEasyStateCommand($this, array_merge(validWgEasyUpdateInterfaceOptions(), [
            '--operation-token' => wgEasyStateSignedOperationToken(),
            '--json' => true,
        ]));

        expect($exitCode)
            ->toBe(1)
            ->and(json_decode($output, associative: true, flags: JSON_THROW_ON_ERROR))
            ->toBe(JsonEnvelope::failure(
                'interface_not_found',
                'wg-easy interface was not found.',
                ['interface' => 'wg0'],
            ));
    });

    it('returns user_not_found when updating a missing user password row', function (): void {
        $databasePath = "{$this->wgEasyStateTemp}/wg-easy.db";
        createWgEasyUsersDatabase($databasePath, insertUser: false);

        [$exitCode, $output] = runWgEasyStateCommand($this, array_merge(
            validWgEasyPasswordOptions('update-user-password'),
            [
                '--operation-token' => wgEasyStateSignedOperationToken(),
                '--json' => true,
            ],
        ));

        expect($exitCode)
            ->toBe(1)
            ->and(json_decode($output, associative: true, flags: JSON_THROW_ON_ERROR))
            ->toBe(JsonEnvelope::failure(
                'user_not_found',
                'wg-easy user was not found.',
            ));
    });

    it('returns session_password_not_found when updating a missing session password row', function (): void {
        $databasePath = "{$this->wgEasyStateTemp}/wg-easy.db";
        createWgEasySessionPasswordDatabase($databasePath, insertSettings: false);

        [$exitCode, $output] = runWgEasyStateCommand($this, array_merge(
            validWgEasyPasswordOptions('update-session-password'),
            [
                '--operation-token' => wgEasyStateSignedOperationToken(),
                '--json' => true,
            ],
        ));

        expect($exitCode)
            ->toBe(1)
            ->and(json_decode($output, associative: true, flags: JSON_THROW_ON_ERROR))
            ->toBe(JsonEnvelope::failure(
                'session_password_not_found',
                'wg-easy session password row was not found.',
            ));
    });

    it('does not leak user password hash values to stdout, stderr, json, or logs', function (): void {
        $databasePath = "{$this->wgEasyStateTemp}/wg-easy.db";
        createWgEasyUsersDatabase($databasePath);
        $passwordHash = validWgEasyPasswordHash();

        $process = runWgEasyStateCommandProcess([
            '--action' => 'update-user-password',
            '--password-hash' => $passwordHash,
            '--operation-token' => wgEasyStateSignedOperationToken(),
            '--json' => true,
        ], [
            'ORBIT_WG_EASY_DB_PATH' => $databasePath,
        ]);

        expect($process->getExitCode())
            ->toBe(0)
            ->and($process->getOutput())
            ->not->toContain($passwordHash)->and($process->getErrorOutput())
            ->not->toContain($passwordHash)->and(json_decode(
                trim($process->getOutput()),
                associative: true,
                flags: JSON_THROW_ON_ERROR,
            ))
            ->not->toContain($passwordHash)->and(wgEasyStateLogContents())
            ->not->toContain($passwordHash);
    });

    it('does not leak session password hash values to stdout, stderr, json, or logs', function (): void {
        $databasePath = "{$this->wgEasyStateTemp}/wg-easy.db";
        createWgEasySessionPasswordDatabase($databasePath);
        $passwordHash = validWgEasyPasswordHash();

        $process = runWgEasyStateCommandProcess([
            '--action' => 'update-session-password',
            '--password-hash' => $passwordHash,
            '--operation-token' => wgEasyStateSignedOperationToken(),
            '--json' => true,
        ], [
            'ORBIT_WG_EASY_DB_PATH' => $databasePath,
        ]);

        expect($process->getExitCode())
            ->toBe(0)
            ->and($process->getOutput())
            ->not->toContain($passwordHash)->and($process->getErrorOutput())
            ->not->toContain($passwordHash)->and(json_decode(
                trim($process->getOutput()),
                associative: true,
                flags: JSON_THROW_ON_ERROR,
            ))
            ->not->toContain($passwordHash)->and(wgEasyStateLogContents())
            ->not->toContain($passwordHash);
    });

    it('rejects host values with null bytes before writing to the database', function (): void {
        $databasePath = "{$this->wgEasyStateTemp}/wg-easy.db";
        createWgEasyUserConfigDatabase($databasePath);

        [$exitCode, $output] = runWgEasyStateCommand($this, [
            '--action' => 'update-user',
            '--host' => "vpn\0evil",
            '--default-dns' => '["10.6.0.1"]',
            '--default-persistent-keepalive' => '25',
            '--operation-token' => wgEasyStateSignedOperationToken(),
            '--json' => true,
        ]);

        $row = readWgEasyStateRow($databasePath, 'user_configs_table');

        expect($exitCode)
            ->toBe(1)
            ->and(json_decode($output, associative: true, flags: JSON_THROW_ON_ERROR))
            ->toBe(JsonEnvelope::failure(
                'validation_failed',
                'The --host option is invalid.',
                ['field' => 'host'],
            ))
            ->and($row['host'])
            ->toBe('old.example.test');
    });

    it('rejects default DNS values with null bytes before writing to the database', function (): void {
        $databasePath = "{$this->wgEasyStateTemp}/wg-easy.db";
        createWgEasyUserConfigDatabase($databasePath);

        [$exitCode, $output] = runWgEasyStateCommand($this, [
            '--action' => 'update-user',
            '--host' => 'vpn.example.test',
            '--default-dns' => "1.1.1.1\0evil",
            '--default-persistent-keepalive' => '25',
            '--operation-token' => wgEasyStateSignedOperationToken(),
            '--json' => true,
        ]);

        $row = readWgEasyStateRow($databasePath, 'user_configs_table');

        expect($exitCode)
            ->toBe(1)
            ->and(json_decode($output, associative: true, flags: JSON_THROW_ON_ERROR))
            ->toBe(JsonEnvelope::failure(
                'validation_failed',
                'The --default-dns option is invalid.',
                ['field' => 'default-dns'],
            ))
            ->and($row['default_dns'])
            ->toBe('["8.8.8.8"]');
    });

    it('rejects database path overrides with null bytes before file operations', function (): void {
        putenv('ORBIT_WG_EASY_DB_PATH');
        $_SERVER['ORBIT_WG_EASY_DB_PATH'] = "{$this->wgEasyStateTemp}/wg-easy.db\0evil";

        [$exitCode, $output] = runWgEasyStateCommand($this, [
            '--action' => 'ensure-writable',
            '--operation-token' => wgEasyStateSignedOperationToken(),
            '--json' => true,
        ]);

        expect($exitCode)
            ->toBe(1)
            ->and(json_decode($output, associative: true, flags: JSON_THROW_ON_ERROR))
            ->toBe(JsonEnvelope::failure(
                'validation_failed',
                'The ORBIT_WG_EASY_DB_PATH environment value is invalid.',
                ['field' => 'ORBIT_WG_EASY_DB_PATH'],
            ))
            ->and($output)
            ->not->toContain('ValueError')->and($output)
            ->not->toContain('null byte');
    });

    it('returns a database_missing failure envelope without raw PDO details', function (): void {
        [$exitCode, $output] = runWgEasyStateCommand($this, [
            '--action' => 'update-general',
            '--setup-step' => '0',
            '--operation-token' => wgEasyStateSignedOperationToken(),
            '--json' => true,
        ]);

        expect($exitCode)
            ->toBe(1)
            ->and(json_decode($output, associative: true, flags: JSON_THROW_ON_ERROR))
            ->toBe(JsonEnvelope::failure(
                'database_missing',
                'wg-easy database does not exist.',
            ))
            ->and($output)
            ->not->toContain('SQLSTATE')->and($output)
            ->not->toContain('PDOException');
    });

    it('hides the internal wg-easy state command from php orbit list', function (): void {
        $process = new Process([PHP_BINARY, 'orbit', 'list'], base_path());
        $process->run();

        expect($process->getExitCode())
            ->toBe(0)
            ->and($process->getOutput())
            ->not->toContain('internal:wg-easy:state');
    });
});

function configureWgEasyStateOperationTokenGuard(): void
{
    app()->forgetInstance('App\Services\Executor\OperationTokenGuard');
}

function wgEasyStateSignedOperationToken(
    string $id = 'wg-easy-state',
    string $node = 'app-dev',
    string $command = 'internal:wg-easy:state',
    ?int $issuedAt = null,
    ?int $expiresAt = null,
): string {
    $issuedAt ??= time() - 10;
    $expiresAt ??= time() + 120;

    return new OperationTokenSigner()
        ->sign(
            secret: 'gateway-secret',
            id: $id,
            node: $node,
            command: $command,
            issuedAt: $issuedAt,
            expiresAt: $expiresAt,
        )
        ->toString();
}

/**
 * @param  array<string, mixed>  $parameters
 * @return array{int, string}
 */
function runWgEasyStateCommand(object $test, array $parameters = []): array
{
    $test->mockConsoleOutput = false;
    app()->offsetUnset(OutputStyle::class);

    $exitCode = $test->artisan('internal:wg-easy:state', $parameters);

    return [$exitCode, trim(app(Kernel::class)->output())];
}

function createWgEasyUserConfigDatabase(string $path): void
{
    $pdo = createWgEasyStateWritableSqliteDatabase($path);
    $pdo->exec(
        'create table user_configs_table (host text not null, default_dns text not null, default_persistent_keepalive integer not null)',
    );
    $pdo->exec(
        "insert into user_configs_table (host, default_dns, default_persistent_keepalive) values ('old.example.test', '[\"8.8.8.8\"]', 0)",
    );
}

function createWgEasyGeneralDatabase(string $path): void
{
    $pdo = createWgEasyStateWritableSqliteDatabase($path);
    $pdo->exec('create table general_table (setup_step integer not null)');
    $pdo->exec('insert into general_table (setup_step) values (1)');
}

/**
 * @return list<string>
 */
function allowedWgEasyStateActions(): array
{
    return [
        'update-user',
        'update-general',
        'ensure-writable',
        'upsert-peer',
        'delete-peer',
        'update-interface',
        'update-user-password',
        'update-session-password',
    ];
}

/**
 * @return array<string, string>
 */
function validWgEasyUpsertPeerOptions(): array
{
    return [
        '--action' => 'upsert-peer',
        '--name' => 'gateway',
        '--ipv4' => '10.6.0.2',
        '--ipv6' => 'fdcc:ad94:bacf:61a4::cafe:2',
        '--private-key' => 'gateway-private-key',
        '--public-key' => 'gateway-public-key',
        '--pre-shared-key' => 'gateway-pre-shared-key',
    ];
}

/**
 * @return array<string, string>
 */
function validWgEasyDeletePeerOptions(): array
{
    return [
        '--action' => 'delete-peer',
        '--name' => 'gateway',
    ];
}

/**
 * @return array<string, string>
 */
function validWgEasyUpdateInterfaceOptions(): array
{
    return [
        '--action' => 'update-interface',
        '--ipv4-cidr' => '10.6.0.0/24',
    ];
}

/**
 * @return array<string, string>
 */
function validWgEasyPasswordOptions(string $action): array
{
    return [
        '--action' => $action,
        '--password-hash' => validWgEasyPasswordHash(),
    ];
}

function validWgEasyPasswordHash(): string
{
    return '$2y$12$BoGFG2BtfhOdMhzU1PUt7OScj2ZJVvimGfq0thVV4m9hiZdLqI3Q6';
}

function validWgEasyArgon2idPasswordHash(): string
{
    return '$argon2id$v=19$m=65536,t=4,p=1$abcdefghijklmnop$abcdefghijklmnopqrstuvwxyzABCDEFGHIJ';
}

function invalidWgEasyPasswordHashMessage(): string
{
    return 'password hash format is not a recognized bcrypt/argon2i/argon2id hash';
}

function createWgEasyPeerDatabase(string $path, bool $insertInterface = true): PDO
{
    $pdo = createWgEasyStateWritableSqliteDatabase($path);
    $pdo->exec('create table interfaces_table (name text primary key, ipv4_cidr text not null)');
    $pdo->exec(<<<'SQL'
        create table clients_table (
            user_id integer not null,
            interface_id text not null,
            name text not null,
            ipv4_address text not null,
            ipv6_address text not null,
            private_key text not null,
            public_key text not null,
            pre_shared_key text not null,
            allowed_ips text not null,
            server_allowed_ips text not null,
            persistent_keepalive integer not null,
            mtu integer not null,
            dns text not null,
            enabled integer not null
        )
        SQL);

    if ($insertInterface) {
        $pdo->exec("insert into interfaces_table (name, ipv4_cidr) values ('wg0', '10.0.0.0/24')");
    }

    return $pdo;
}

function createWgEasyInterfaceDatabase(string $path, bool $insertInterface = true): void
{
    $pdo = createWgEasyStateWritableSqliteDatabase($path);
    $pdo->exec('create table interfaces_table (name text primary key, ipv4_cidr text not null)');

    if ($insertInterface) {
        $pdo->exec("insert into interfaces_table (name, ipv4_cidr) values ('wg0', '10.0.0.0/24')");
    }
}

function createWgEasyUsersDatabase(string $path, bool $insertUser = true): void
{
    $pdo = createWgEasyStateWritableSqliteDatabase($path);
    $pdo->exec('create table users_table (password text not null)');

    if ($insertUser) {
        $pdo->exec("insert into users_table (password) values ('old-password-hash')");
    }
}

function createWgEasySessionPasswordDatabase(string $path, bool $insertSettings = true): void
{
    $pdo = createWgEasyStateWritableSqliteDatabase($path);
    $pdo->exec('create table general_table (session_password text not null)');

    if ($insertSettings) {
        $pdo->exec("insert into general_table (session_password) values ('old-session-password-hash')");
    }
}

/**
 * @param  array{
 *     name: string,
 *     ipv4_address: string,
 *     ipv6_address: string,
 *     private_key: string,
 *     public_key: string,
 *     pre_shared_key: string,
 * }  $peer
 */
function insertWgEasyPeer(PDO $pdo, array $peer): void
{
    $statement = $pdo->prepare(<<<'SQL'
        insert into clients_table (
            user_id,
            interface_id,
            name,
            ipv4_address,
            ipv6_address,
            private_key,
            public_key,
            pre_shared_key,
            allowed_ips,
            server_allowed_ips,
            persistent_keepalive,
            mtu,
            dns,
            enabled
        ) values (
            :user_id,
            :interface_id,
            :name,
            :ipv4_address,
            :ipv6_address,
            :private_key,
            :public_key,
            :pre_shared_key,
            :allowed_ips,
            :server_allowed_ips,
            :persistent_keepalive,
            :mtu,
            :dns,
            :enabled
        )
        SQL);

    $statement->execute([
        'user_id' => 1,
        'interface_id' => 'wg0',
        'name' => $peer['name'],
        'ipv4_address' => $peer['ipv4_address'],
        'ipv6_address' => $peer['ipv6_address'],
        'private_key' => $peer['private_key'],
        'public_key' => $peer['public_key'],
        'pre_shared_key' => $peer['pre_shared_key'],
        'allowed_ips' => '["0.0.0.0/0", "::/0"]',
        'server_allowed_ips' => '["'.$peer['ipv4_address'].'/32"]',
        'persistent_keepalive' => 25,
        'mtu' => 1420,
        'dns' => '["10.6.0.1"]',
        'enabled' => 1,
    ]);
}

/**
 * @return array<string, mixed>
 */
function readWgEasyStateRow(string $path, string $table): array
{
    $pdo = createWgEasyStateWritableSqliteDatabase($path);
    $row = $pdo->query("select * from {$table} limit 1")->fetch(PDO::FETCH_ASSOC);

    expect($row)->toBeArray();

    return $row;
}

function countWgEasyStateRows(string $path, string $table): int
{
    $pdo = createWgEasyStateWritableSqliteDatabase($path);

    return (int) $pdo->query("select count(*) from {$table}")->fetchColumn();
}

/**
 * @param  array<string, mixed>  $parameters
 * @param  array<string, string>  $environment
 */
function runWgEasyStateCommandProcess(array $parameters, array $environment = []): Process
{
    $arguments = [PHP_BINARY, 'orbit', 'internal:wg-easy:state'];
    $operationToken = $parameters['--operation-token'] ?? null;
    $authorizationEnvironment = [];

    if (is_string($operationToken) && $operationToken !== '') {
        $token = OperationToken::parse($operationToken);
        $authorizationEnvironment = [
            'ORBIT_AGENT_PUSH_AUTHORIZED_OPERATION_ID' => $token->id,
            'ORBIT_AGENT_PUSH_AUTHORIZED_COMMAND' => $token->command,
            'ORBIT_AGENT_PUSH_AUTHORIZED_OPERATION_TOKEN' => $operationToken,
        ];
    }

    foreach ($parameters as $key => $value) {
        if ($value === true) {
            $arguments[] = $key;

            continue;
        }

        $arguments[] = "{$key}={$value}";
    }

    $directory = sys_get_temp_dir().'/orbit-cli-wg-easy-process-'.bin2hex(random_bytes(8));
    $configPath = "{$directory}/config.json";

    mkdir($directory, recursive: true);
    file_put_contents($configPath, json_encode([
        'schema_version' => 1,
        'active_gateway' => null,
        'gateways' => [],
        'defaults' => ['node' => 'app-dev', 'profile' => null],
        'meta' => ['imported_from' => null, 'imported_at' => null],
    ], JSON_THROW_ON_ERROR));
    chmod($configPath, 0600);

    try {
        $process = new Process(
            $arguments,
            base_path(),
            array_merge(
                [
                    'APP_KEY' => 'gateway-secret',
                    'ORBIT_CONFIG_PATH' => $configPath,
                    'ORBIT_GATEWAY_URL' => '',
                ],
                $authorizationEnvironment,
                $environment,
            ),
        );
        $process->run();

        return $process;
    } finally {
        if (is_file($configPath)) {
            unlink($configPath);
        }

        if (is_dir($directory)) {
            rmdir($directory);
        }
    }
}

function wgEasyStateLogContents(): string
{
    $directory = storage_path('logs');

    if (! is_dir($directory)) {
        return '';
    }

    $contents = '';
    $files = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS),
    );

    foreach ($files as $file) {
        if (! $file->isFile()) {
            continue;
        }

        $contents .= (string) file_get_contents($file->getPathname());
    }

    return $contents;
}

function createWgEasyStateWritableSqliteDatabase(string $path): PDO
{
    $pdo = new PDO("sqlite:{$path}");
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    return $pdo;
}

function removeWgEasyStateTempDirectory(?string $path): void
{
    if (! is_string($path) || ! is_dir($path)) {
        return;
    }

    $entries = scandir($path);

    if ($entries === false) {
        return;
    }

    foreach ($entries as $entry) {
        if ($entry === '.' || $entry === '..') {
            continue;
        }

        $entryPath = "{$path}/{$entry}";

        if (is_dir($entryPath)) {
            removeWgEasyStateTempDirectory($entryPath);

            continue;
        }

        chmod($entryPath, 0644);
        unlink($entryPath);
    }

    rmdir($path);
}
