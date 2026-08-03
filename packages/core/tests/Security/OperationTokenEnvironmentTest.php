<?php

declare(strict_types=1);

use Orbit\Core\Security\OperationTokenCommandContext;
use Orbit\Core\Security\OperationTokenEnvironment;
use Orbit\Core\Security\OperationTokenSigner;
use Orbit\Core\Security\OperationTokenVerifier;

describe(OperationTokenEnvironment::class, function (): void {
    it('keeps only allowlisted non-empty string keys and sorts them', function (): void {
        $normalized = OperationTokenEnvironment::allowlisted([
            'ORBIT_BIN_PATH' => '/home/orbit/.local/bin/orbit',
            'NOT_BOUND' => 'drop-me',
            'HOME' => '/home/orbit',
            'APP_KEY' => operation_token_environment_test_secret(),
            'ORBIT_CONFIG_PATH' => '',
        ]);

        expect($normalized)
            ->toBe([
                'APP_KEY' => operation_token_environment_test_secret(),
                'HOME' => '/home/orbit',
                'ORBIT_BIN_PATH' => '/home/orbit/.local/bin/orbit',
            ])
            ->and(array_keys($normalized))
            ->toBe(['APP_KEY', 'HOME', 'ORBIT_BIN_PATH']);
    });

    it('reads the same allowlisted keys from the process environment', function (): void {
        $previous = [];

        foreach (['APP_KEY', 'HOME', 'ORBIT_BIN_PATH', 'NOT_BOUND', 'ORBIT_CONFIG_PATH'] as $key) {
            $previous[$key] = getenv($key);
        }

        putenv('APP_KEY=process-secret');
        putenv('HOME=/home/orbit');
        putenv('ORBIT_BIN_PATH=/home/orbit/.local/bin/orbit');
        putenv('NOT_BOUND=ignore');
        putenv('ORBIT_CONFIG_PATH');

        try {
            expect(OperationTokenEnvironment::fromProcess())
                ->toBe(OperationTokenEnvironment::allowlisted([
                    'APP_KEY' => 'process-secret',
                    'HOME' => '/home/orbit',
                    'ORBIT_BIN_PATH' => '/home/orbit/.local/bin/orbit',
                ]));
        } finally {
            foreach ($previous as $key => $value) {
                if ($value === false) {
                    putenv($key);
                } else {
                    putenv("{$key}={$value}");
                }
            }
        }
    });

    it('lists force_remote_host unset keys as every allowlisted key except HOME', function (): void {
        expect(OperationTokenEnvironment::forceRemoteHostUnsetKeys())
            ->toBe([
                'APP_KEY',
                'ORBIT_BIN_PATH',
                'ORBIT_CONFIG_PATH',
                'ORBIT_INSTALL_METADATA_PATH',
                'ORBIT_WG_EASY_DB_PATH',
            ])
            ->and(OperationTokenEnvironment::forceRemoteHostUnsetKeys())
            ->not->toContain('HOME');
    });
});

describe('operation token transport command-context contract', function (): void {
    /**
     * @return list<array{
     *     label: string,
     *     argv: list<string>,
     *     cwd: ?string,
     *     environment: array<string, string>,
     *     input: ?string,
     * }>
     */
    dataset('transport_lanes', function (): array {
        return [
            'agent_push' => [[
                'label' => 'agent_push',
                'argv' => [
                    'internal:workspace-adapter:lookup',
                    'lookup',
                    'polyscope',
                    '--operation-token='.OperationTokenCommandContext::OPERATION_TOKEN_SENTINEL,
                    '--json',
                ],
                'cwd' => null,
                'environment' => [
                    'APP_KEY' => operation_token_environment_test_secret(),
                    'HOME' => '/home/orbit',
                    'ORBIT_BIN_PATH' => '/home/orbit/.local/bin/orbit',
                    'ORBIT_CONFIG_PATH' => '/home/orbit/.config/orbit/config.json',
                    'EXTRA_PROCESS_ONLY' => 'must-not-bind',
                ],
                'input' => null,
            ]],
            'force_remote_host' => [[
                'label' => 'force_remote_host',
                'argv' => [
                    'internal:caddy-config',
                    'read-global',
                    '--operation-token='.OperationTokenCommandContext::OPERATION_TOKEN_SENTINEL,
                    '--json',
                ],
                'cwd' => '/home/orbit',
                'environment' => [
                    'HOME' => '/home/orbit',
                    'APP_KEY' => 'must-not-bind-on-host',
                    'ORBIT_CONFIG_PATH' => '/tmp/stale.json',
                ],
                'input' => '{"container":"orbit-caddy"}',
            ]],
        ];
    });

    it('proves a gateway-minted token context hash equals the CLI verification reconstruction', function (array $lane): void {
        // force_remote_host first collapses process env to HOME, then both lanes
        // filter through the shared allowlist before mint/verify.
        $mintEnvironment = $lane['label'] === 'force_remote_host'
            ? OperationTokenEnvironment::allowlisted([
                'HOME' => $lane['environment']['HOME'],
            ])
            : OperationTokenEnvironment::allowlisted($lane['environment']);

        $mintContext = OperationTokenCommandContext::fromTrustedDispatch(
            argv: $lane['argv'],
            cwd: $lane['cwd'],
            environment: $mintEnvironment,
            input: $lane['input'],
        );

        $token = new OperationTokenSigner()->sign(
            secret: operation_token_environment_test_secret(),
            keyId: 'current',
            id: '018fded1-f2f4-72c4-9c33-62978d6f26f5',
            node: 'app-dev',
            command: $lane['argv'][0],
            commandContextHash: $mintContext->hash(),
            issuedAt: 1_798_105_200,
            expiresAt: 1_798_105_320,
        );

        // CLI / Agent verification reconstructs argv with the real compact token
        // and only the allowlisted process environment that survives the lane.
        $compactToken = $token->toString();
        $verifyArgv = array_map(
            static fn (string $argument): string => str_replace(
                OperationTokenCommandContext::OPERATION_TOKEN_SENTINEL,
                $compactToken,
                $argument,
            ),
            $lane['argv'],
        );
        $verifyContext = OperationTokenCommandContext::fromAgentVerification(
            argv: $verifyArgv,
            cwd: $lane['cwd'],
            environment: OperationTokenEnvironment::allowlisted($mintEnvironment),
            input: $lane['input'],
            operationToken: $compactToken,
        );

        expect($verifyContext->hash())
            ->toBe($token->commandContextHash)
            ->and($verifyContext->hash())
            ->toBe($mintContext->hash())
            ->and(new OperationTokenVerifier(new OperationTokenSigner)->verify(
                secretsByKeyId: ['current' => operation_token_environment_test_secret()],
                token: $token,
                expectedNode: 'app-dev',
                expectedCommand: $lane['argv'][0],
                expectedCommandContextHash: $verifyContext->hash(),
                now: 1_798_105_200,
            ))
            ->toBeTrue()
            ->and($mintEnvironment)
            ->not->toHaveKey('EXTRA_PROCESS_ONLY');

        if ($lane['label'] === 'force_remote_host') {
            expect($mintEnvironment)
                ->toBe(['HOME' => '/home/orbit'])
                ->and($mintEnvironment)
                ->not->toHaveKey('APP_KEY');
        }
    })->with('transport_lanes');
});

function operation_token_environment_test_secret(): string
{
    return implode('-', ['gateway', 'secret']);
}
