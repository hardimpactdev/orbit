<?php

declare(strict_types=1);

use App\Enums\Nodes\NodeRoleStatus;
use App\Models\Node;
use App\Models\NodeRoleAssignment;
use App\Services\RemoteShell\Exceptions\LocalExecutorCommandBuilderException;
use App\Services\RemoteShell\LocalExecutorCommandBuilder;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

uses(TestCase::class);
uses(RefreshDatabase::class);

describe(LocalExecutorCommandBuilder::class, function (): void {
    it('builds the verify command with an operation token and json output', function (): void {
        $operationToken = local_executor_test_operation_token();

        $command = localExecutorCommandBuilder()->build(
            targetNode: localExecutorTargetNode(['gateway']),
            commandName: 'internal:executor:verify',
            arguments: [],
            options: [],
            operationToken: $operationToken,
        );

        expect($command)->toBe(
            "/usr/local/bin/orbit internal:executor:verify --operation-token='{$operationToken}' --json",
        );
    });

    it('builds argv for agent-push without binary or shell escaping', function (): void {
        $operationToken = local_executor_test_operation_token();

        $argv = localExecutorCommandBuilder()->buildArgv(
            targetNode: localExecutorTargetNode(['app-dev']),
            commandName: 'internal:workspace-adapter:lookup',
            arguments: ['lookup', 'two words', "quote'arg", 7, 1.5, true, false],
            options: [
                'state-path' => "/home/orbit/.polyscope/state's.db",
                'enabled' => true,
                'locked' => false,
            ],
            operationToken: $operationToken,
        );

        expect($argv)->toBe([
            'internal:workspace-adapter:lookup',
            'lookup',
            'two words',
            "quote'arg",
            '7',
            '1.5',
            '1',
            '0',
            "--state-path=/home/orbit/.polyscope/state's.db",
            '--enabled=1',
            '--locked=0',
            "--operation-token={$operationToken}",
            '--json',
        ]);
    });

    it('uses the same role allow list for argv building', function (): void {
        expect(fn (): array => localExecutorCommandBuilder()->buildArgv(
            targetNode: localExecutorTargetNode(['vpn']),
            commandName: 'internal:workspace-adapter:lookup',
            arguments: [],
            options: [],
            operationToken: local_executor_test_operation_token(),
        ))
            ->toThrow(LocalExecutorCommandBuilderException::class, 'not allowed');
    });

    it('allows runtime backend probes for managed process runtime nodes', function (string $role): void {
        $operationToken = local_executor_test_operation_token();

        $argv = localExecutorCommandBuilder()->buildArgv(
            targetNode: localExecutorTargetNode([$role]),
            commandName: 'internal:runtime-backend:probe',
            arguments: ['systemd'],
            options: [],
            operationToken: $operationToken,
        );

        expect($argv)->toBe([
            'internal:runtime-backend:probe',
            'systemd',
            "--operation-token={$operationToken}",
            '--json',
        ]);
    })->with([
        'metrics',
        'analytics',
    ]);

    it('allows internal commands for pending role convergence', function (): void {
        $node = Node::factory()->create(['name' => 'target']);

        NodeRoleAssignment::factory()->for($node)->create([
            'role' => 'metrics',
            'status' => NodeRoleStatus::Pending,
        ]);

        expect(localExecutorCommandBuilder()->buildArgv(
            targetNode: $node,
            commandName: 'internal:managed-file',
            arguments: ['probe'],
            options: [],
            operationToken: local_executor_test_operation_token(),
        ))->toContain('internal:managed-file');
    });

    it('uses the configured local executor binary path only for gateway nodes', function (): void {
        $operationToken = local_executor_test_operation_token();

        config()->set('orbit.local_executor_binary', '/usr/local/bin/orbit-cli');

        $gatewayCommand = localExecutorCommandBuilder()->build(
            targetNode: localExecutorTargetNode(['gateway']),
            commandName: 'internal:gateway-runtime-backend:probe',
            arguments: [],
            options: [],
            operationToken: $operationToken,
        );
        $workloadCommand = localExecutorCommandBuilder()->build(
            targetNode: localExecutorTargetNode(['vpn']),
            commandName: 'internal:wg-easy:state',
            arguments: ['state:list-users'],
            options: [],
            operationToken: $operationToken,
        );

        expect($gatewayCommand)
            ->toBe(
                escapeshellarg('/usr/local/bin/orbit-cli')
                    ." internal:gateway-runtime-backend:probe --operation-token='{$operationToken}' --json",
            )
            ->and($workloadCommand)
            ->toBe(
                '/usr/local/bin/orbit'
                ." internal:wg-easy:state 'state:list-users' --operation-token='{$operationToken}' --json",
            );
    });

    it('appends escaped positional arguments after the command name', function (): void {
        $operationToken = local_executor_test_operation_token();

        $command = localExecutorCommandBuilder()->build(
            targetNode: localExecutorTargetNode(['app-dev']),
            commandName: 'internal:workspace-adapter:lookup',
            arguments: ['two words', "quote'arg", 7, 1.5, true, false],
            options: [],
            operationToken: $operationToken,
        );

        expect($command)->toBe(implode(' ', [
            '/usr/local/bin/orbit',
            'internal:workspace-adapter:lookup',
            escapeshellarg('two words'),
            escapeshellarg("quote'arg"),
            escapeshellarg('7'),
            escapeshellarg('1.5'),
            escapeshellarg('1'),
            escapeshellarg('0'),
            "--operation-token='{$operationToken}'",
            '--json',
        ]));
    });

    it('appends escaped option values after positional arguments', function (): void {
        $operationToken = local_executor_test_operation_token();

        $command = localExecutorCommandBuilder()->build(
            targetNode: localExecutorTargetNode(['vpn']),
            commandName: 'internal:wg-easy:state',
            arguments: ['state:update-user'],
            options: [
                'user-id' => 42,
                'state-path' => "/srv/wg easy/db's.sqlite",
                'enabled' => true,
                'locked' => false,
            ],
            operationToken: $operationToken,
        );

        expect($command)->toBe(implode(' ', [
            '/usr/local/bin/orbit',
            'internal:wg-easy:state',
            escapeshellarg('state:update-user'),
            '--user-id='.escapeshellarg('42'),
            '--state-path='.escapeshellarg("/srv/wg easy/db's.sqlite"),
            '--enabled='.escapeshellarg('1'),
            '--locked='.escapeshellarg('0'),
            "--operation-token='{$operationToken}'",
            '--json',
        ]));
    });

    it('escapes operation tokens before appending json output', function (): void {
        $token = "token with ' quote";

        $command = localExecutorCommandBuilder()->build(
            targetNode: localExecutorTargetNode(['gateway']),
            commandName: 'internal:executor:verify',
            arguments: [],
            options: [],
            operationToken: $token,
        );

        expect($command)
            ->toBe('/usr/local/bin/orbit internal:executor:verify --operation-token='.escapeshellarg($token).' --json')
            ->and($command)
            ->toEndWith(' --json');
    });

    it('builds an audit line with the operation token redacted', function (): void {
        $auditLine = localExecutorCommandBuilder()->buildAuditLine(
            targetNode: localExecutorTargetNode(['app-dev']),
            commandName: 'internal:workspace-adapter:lookup',
            arguments: [],
            options: ['state-path' => '/home/orbit/.polyscope/polyscope.db'],
            operationToken: local_executor_test_operation_token(),
        );

        expect($auditLine)
            ->toBe(implode(' ', [
                '/usr/local/bin/orbit',
                'internal:workspace-adapter:lookup',
                '--state-path='.escapeshellarg('/home/orbit/.polyscope/polyscope.db'),
                '--operation-token=<redacted>',
                '--json',
            ]))
            ->not->toContain('token-abc');
    });

    it('rejects bad command names', function (string $commandName): void {
        expect(fn (): string => localExecutorCommandBuilder()->build(
            targetNode: localExecutorTargetNode(['gateway']),
            commandName: $commandName,
            arguments: [],
            options: [],
            operationToken: local_executor_test_operation_token(),
        ))
            ->toThrow(LocalExecutorCommandBuilderException::class);
    })->with([
        'empty' => '',
        'blank' => '   ',
        'missing internal namespace' => 'executor:verify',
        'missing command tail' => 'internal:',
        'uppercase' => 'internal:Executor:verify',
        'whitespace' => 'internal:executor verify',
        'path separator' => 'internal:executor/verify',
        'shell metacharacters' => 'evil; rm -rf /',
    ]);

    it('rejects command names outside the closed internal executor allow list', function (): void {
        expect(fn (): string => localExecutorCommandBuilder()->build(
            targetNode: localExecutorTargetNode(['gateway']),
            commandName: 'internal:not-registered',
            arguments: [],
            options: [],
            operationToken: local_executor_test_operation_token(),
        ))
            ->toThrow(LocalExecutorCommandBuilderException::class, 'not allowed');
    });

    it('exposes the complete closed role-scoped internal command allow list', function (): void {
        expect(LocalExecutorCommandBuilder::allowedCommandRoles())->toBe([
            'internal:executor:verify' => [
                'gateway',
                'vpn',
                'router',
                'app-dev',
                'app-prod',
                'database',
                'agent',
                'ingress',
            ],
            'internal:agent-acl:ensure' => ['agent'],
            'internal:agent-runtime:probe' => ['agent'],
            'internal:agent-user:ensure' => ['agent'],
            'internal:app-cache:clear' => ['app-dev', 'app-prod'],
            'internal:app-introspect:probe' => ['app-dev', 'app-prod'],
            'internal:app-runtime-configs:probe' => ['app-dev', 'app-prod'],
            'internal:app-runtime-container' => ['app-dev', 'app-prod'],
            'internal:app-runtime-containers:probe' => ['app-dev', 'app-prod'],
            'internal:app-runtime-extensions:probe' => ['app-dev', 'app-prod'],
            'internal:app-source:create' => ['app-dev', 'app-prod'],
            'internal:app-source-path:probe' => ['app-dev', 'app-prod'],
            'internal:app-security:repair' => ['app-dev', 'app-prod'],
            'internal:app-worker-readiness:probe' => ['app-dev', 'app-prod'],
            'internal:caddy-config' => ['gateway', 'router', 'app-dev', 'app-prod', 'agent', 'ingress'],
            'internal:codex-app-config' => ['agent'],
            'internal:doctor-self' => [
                'vpn',
                'router',
                'app-dev',
                'app-prod',
                'database',
                'agent',
                'ingress',
                'websocket',
                's3',
                'metrics',
                'analytics',
            ],
            'internal:env-file' => ['app-dev', 'app-prod', 'database'],
            'internal:firewall-rule' => [
                'gateway',
                'vpn',
                'router',
                'app-dev',
                'app-prod',
                'database',
                'agent',
                'ingress',
                'websocket',
                's3',
                'metrics',
                'analytics',
            ],
            'internal:firewall-rule:probe' => [
                'gateway',
                'vpn',
                'router',
                'app-dev',
                'app-prod',
                'database',
                'agent',
                'ingress',
                'websocket',
                's3',
                'metrics',
                'analytics',
            ],
            'internal:fleet-update:verify' => [
                'vpn',
                'router',
                'app-dev',
                'app-prod',
                'database',
                'agent',
                'ingress',
                'websocket',
                's3',
                'metrics',
                'analytics',
            ],
            'internal:fleet-update:install-cli' => [
                'vpn',
                'router',
                'app-dev',
                'app-prod',
                'database',
                'agent',
                'ingress',
                'websocket',
                's3',
                'metrics',
                'analytics',
            ],
            'internal:gateway-runtime-backend:probe' => ['gateway'],
            'internal:managed-file' => [
                'vpn',
                'router',
                'app-dev',
                'app-prod',
                'database',
                'agent',
                'ingress',
                'websocket',
                's3',
                'metrics',
                'analytics',
            ],
            'internal:node-security-posture:probe' => [
                'gateway',
                'vpn',
                'router',
                'app-dev',
                'app-prod',
                'database',
                'agent',
                'ingress',
                'websocket',
                's3',
                'metrics',
                'analytics',
            ],
            'internal:wg-easy:state' => ['vpn'],
            'internal:database-add-user' => ['app-dev', 'app-prod', 'database'],
            'internal:database-query-local' => ['app-dev', 'app-prod', 'database'],
            'internal:process-docker-container' => ['app-dev', 'app-prod', 'database'],
            'internal:process-docker-swarm-service' => ['app-dev', 'app-prod', 'database', 'metrics'],
            'internal:process-logs' => ['app-dev', 'app-prod', 'database', 'agent'],
            'internal:process-systemd-service' => [
                'gateway',
                'vpn',
                'router',
                'app-dev',
                'app-prod',
                'database',
                'agent',
                'ingress',
                'websocket',
                's3',
                'metrics',
                'analytics',
            ],
            'internal:runtime-backend:probe' => ['app-dev', 'app-prod', 'database', 'agent', 'metrics', 'analytics'],
            'internal:s3-runtime:probe' => ['s3'],
            'internal:secret-file' => [
                'vpn',
                'router',
                'app-dev',
                'app-prod',
                'database',
                'agent',
                'ingress',
                'websocket',
                's3',
                'metrics',
                'analytics',
            ],
            'internal:site-certificate:install' => ['app-dev', 'app-prod', 'websocket'],
            'internal:solo-upstream-request' => [
                'gateway',
                'vpn',
                'router',
                'app-dev',
                'app-prod',
                'database',
                'agent',
                'ingress',
                'websocket',
                's3',
                'metrics',
                'analytics',
            ],
            'internal:websocket-runtime' => ['websocket'],
            'internal:unattended-upgrades:apply' => [
                'vpn',
                'router',
                'app-dev',
                'app-prod',
                'database',
                'agent',
                'ingress',
                'websocket',
                's3',
                'metrics',
                'analytics',
            ],
            'internal:unattended-upgrades:probe' => [
                'vpn',
                'router',
                'app-dev',
                'app-prod',
                'database',
                'agent',
                'ingress',
                'websocket',
                's3',
                'metrics',
                'analytics',
            ],
            'internal:wireguard-endpoint:rotate' => [
                'vpn',
                'router',
                'app-dev',
                'app-prod',
                'database',
                'agent',
                'ingress',
                'websocket',
                's3',
                'metrics',
                'analytics',
            ],
            'internal:wireguard-interface-public-key:read' => [
                'gateway',
                'vpn',
                'router',
                'app-dev',
                'app-prod',
                'database',
                'agent',
                'ingress',
                'websocket',
                's3',
                'metrics',
                'analytics',
            ],
            'internal:wireguard-self-route' => [
                'gateway',
                'vpn',
                'router',
                'app-dev',
                'app-prod',
                'database',
                'agent',
                'ingress',
                'websocket',
                's3',
                'metrics',
                'analytics',
            ],
            'internal:workspace-adapter:lookup' => ['app-dev'],
            'internal:workspace-adapter:update' => ['app-dev'],
            'internal:workspace-source:create' => ['app-dev'],
        ]);
    });

    it('enforces role-scoped internal command allow-list entries :dataset', function (
        string $commandName,
        array $allowedRoles,
        array $rejectedRoles,
    ): void {
        expect(localExecutorCommandBuilder()->build(
            targetNode: localExecutorTargetNode($allowedRoles),
            commandName: $commandName,
            arguments: [],
            options: [],
            operationToken: local_executor_test_operation_token(),
        ))->toContain($commandName);

        expect(fn (): string => localExecutorCommandBuilder()->build(
            targetNode: localExecutorTargetNode($rejectedRoles),
            commandName: $commandName,
            arguments: [],
            options: [],
            operationToken: local_executor_test_operation_token(),
        ))
            ->toThrow(LocalExecutorCommandBuilderException::class, 'not allowed');
    })->with([
        'executor verify' => ['internal:executor:verify', ['gateway'], []],
        'agent acl ensure' => ['internal:agent-acl:ensure', ['agent'], ['app-dev']],
        'agent runtime probe' => ['internal:agent-runtime:probe', ['agent'], ['app-dev']],
        'agent user ensure' => ['internal:agent-user:ensure', ['agent'], ['app-dev']],
        'app cache clear' => ['internal:app-cache:clear', ['app-dev'], ['database']],
        'app introspect probe' => ['internal:app-introspect:probe', ['app-dev'], ['database']],
        'app runtime configs probe' => ['internal:app-runtime-configs:probe', ['app-dev'], ['database']],
        'app runtime containers probe' => ['internal:app-runtime-containers:probe', ['app-dev'], ['database']],
        'app runtime extensions probe' => ['internal:app-runtime-extensions:probe', ['app-dev'], ['database']],
        'app source create' => ['internal:app-source:create', ['app-dev'], ['database']],
        'app source path probe' => ['internal:app-source-path:probe', ['app-dev'], ['database']],
        'app security repair' => ['internal:app-security:repair', ['app-dev'], ['database']],
        'app worker readiness probe' => ['internal:app-worker-readiness:probe', ['app-dev'], ['database']],
        'caddy config' => ['internal:caddy-config', ['app-dev'], ['database']],
        'codex app config' => ['internal:codex-app-config', ['agent'], ['app-dev']],
        'doctor self' => ['internal:doctor-self', ['app-dev'], ['gateway']],
        'env file' => ['internal:env-file', ['app-dev'], ['vpn']],
        'firewall rule' => ['internal:firewall-rule', ['app-dev'], []],
        'firewall rule probe' => ['internal:firewall-rule:probe', ['app-dev'], []],
        'fleet update verify' => ['internal:fleet-update:verify', ['app-dev'], ['gateway']],
        'gateway runtime backend probe' => ['internal:gateway-runtime-backend:probe', ['gateway'], ['app-dev']],
        'managed file' => ['internal:managed-file', ['app-dev'], ['gateway']],
        'node security posture probe' => ['internal:node-security-posture:probe', ['app-dev'], []],
        'wg-easy state' => ['internal:wg-easy:state', ['vpn'], ['app-dev']],
        'database add user' => ['internal:database-add-user', ['database'], ['vpn']],
        'database query local' => ['internal:database-query-local', ['database'], ['vpn']],
        'process docker container' => ['internal:process-docker-container', ['app-dev'], ['vpn']],
        'process docker swarm service' => ['internal:process-docker-swarm-service', ['database'], ['vpn']],
        'process logs' => ['internal:process-logs', ['app-dev'], ['vpn']],
        'process systemd service' => ['internal:process-systemd-service', ['app-dev'], []],
        'runtime backend probe' => ['internal:runtime-backend:probe', ['app-dev'], ['vpn']],
        's3 runtime probe' => ['internal:s3-runtime:probe', ['s3'], ['app-dev']],
        'secret file' => ['internal:secret-file', ['app-dev'], ['gateway']],
        'site certificate install' => ['internal:site-certificate:install', ['app-dev'], ['vpn']],
        'solo upstream request' => ['internal:solo-upstream-request', ['agent'], []],
        'websocket runtime' => ['internal:websocket-runtime', ['websocket'], ['app-dev']],
        'unattended upgrades apply' => ['internal:unattended-upgrades:apply', ['app-dev'], ['gateway']],
        'unattended upgrades probe' => ['internal:unattended-upgrades:probe', ['app-dev'], ['gateway']],
        'wireguard endpoint rotate' => ['internal:wireguard-endpoint:rotate', ['app-dev'], ['gateway']],
        'wireguard interface public key read' => ['internal:wireguard-interface-public-key:read', ['app-dev'], []],
        'wireguard self route' => ['internal:wireguard-self-route', ['app-dev'], []],
        'workspace adapter lookup' => ['internal:workspace-adapter:lookup', ['app-dev'], ['vpn']],
        'workspace adapter update' => ['internal:workspace-adapter:update', ['app-dev'], ['gateway']],
        'workspace source create' => ['internal:workspace-source:create', ['app-dev'], ['database']],
    ]);

    it('rejects non-scalar arguments', function (Closure $argumentFactory): void {
        $argument = $argumentFactory();

        try {
            expect(fn (): string => localExecutorCommandBuilder()->build(
                targetNode: localExecutorTargetNode(['gateway']),
                commandName: 'internal:executor:verify',
                arguments: [$argument],
                options: [],
                operationToken: local_executor_test_operation_token(),
            ))
                ->toThrow(LocalExecutorCommandBuilderException::class);
        } finally {
            if (is_resource($argument)) {
                fclose($argument);
            }
        }
    })->with([
        'array' => [fn (): array => ['nested']],
        'object' => [fn (): stdClass => new stdClass],
        'null' => [fn (): null => null],
        'resource' => [fn () => fopen('php://temp', 'rb')],
    ]);

    it('rejects bad option keys', function (array $options): void {
        expect(fn (): string => localExecutorCommandBuilder()->build(
            targetNode: localExecutorTargetNode(['gateway']),
            commandName: 'internal:executor:verify',
            arguments: [],
            options: $options,
            operationToken: local_executor_test_operation_token(),
        ))
            ->toThrow(LocalExecutorCommandBuilderException::class);
    })->with([
        'empty' => [['' => 'value']],
        'numeric' => [[0 => 'value']],
        'uppercase' => [['Bad' => 'value']],
        'underscore' => [['bad_key' => 'value']],
        'colon' => [['bad:key' => 'value']],
        'equals' => [['bad=value' => 'value']],
        'shell metacharacters' => [['bad;rm' => 'value']],
    ]);

    it('rejects non-scalar option values', function (Closure $valueFactory): void {
        $value = $valueFactory();

        try {
            expect(fn (): string => localExecutorCommandBuilder()->build(
                targetNode: localExecutorTargetNode(['gateway']),
                commandName: 'internal:executor:verify',
                arguments: [],
                options: ['state-path' => $value],
                operationToken: local_executor_test_operation_token(),
            ))
                ->toThrow(LocalExecutorCommandBuilderException::class);
        } finally {
            if (is_resource($value)) {
                fclose($value);
            }
        }
    })->with([
        'array' => [fn (): array => ['nested']],
        'object' => [fn (): stdClass => new stdClass],
        'null' => [fn (): null => null],
        'resource' => [fn () => fopen('php://temp', 'rb')],
    ]);

    it('rejects null bytes in any input', function (Closure $build): void {
        expect(fn (): string => $build(localExecutorCommandBuilder()))
            ->toThrow(LocalExecutorCommandBuilderException::class);
    })->with([
        'command name' => [
            fn (LocalExecutorCommandBuilder $builder): string => $builder->build(
                targetNode: localExecutorTargetNode(['gateway']),
                commandName: "internal:executor\0verify",
                arguments: [],
                options: [],
                operationToken: local_executor_test_operation_token(),
            ),
        ],
        'argument' => [
            fn (LocalExecutorCommandBuilder $builder): string => $builder->build(
                targetNode: localExecutorTargetNode(['gateway']),
                commandName: 'internal:executor:verify',
                arguments: ["safe\0unsafe"],
                options: [],
                operationToken: local_executor_test_operation_token(),
            ),
        ],
        'option key' => [
            fn (LocalExecutorCommandBuilder $builder): string => $builder->build(
                targetNode: localExecutorTargetNode(['gateway']),
                commandName: 'internal:executor:verify',
                arguments: [],
                options: ["bad\0key" => 'value'],
                operationToken: local_executor_test_operation_token(),
            ),
        ],
        'option value' => [
            fn (LocalExecutorCommandBuilder $builder): string => $builder->build(
                targetNode: localExecutorTargetNode(['gateway']),
                commandName: 'internal:executor:verify',
                arguments: [],
                options: ['state-path' => "safe\0unsafe"],
                operationToken: local_executor_test_operation_token(),
            ),
        ],
        'operation token' => [
            fn (LocalExecutorCommandBuilder $builder): string => $builder->build(
                targetNode: localExecutorTargetNode(['gateway']),
                commandName: 'internal:executor:verify',
                arguments: [],
                options: [],
                operationToken: local_executor_test_operation_token_with_null_byte(),
            ),
        ],
        'configured orbit binary' => [function (LocalExecutorCommandBuilder $builder): string {
            config()->set('orbit.local_executor_binary', "/usr/local/bin/orbit\0cli");

            return $builder->build(
                targetNode: localExecutorTargetNode(['gateway']),
                commandName: 'internal:executor:verify',
                arguments: [],
                options: [],
                operationToken: local_executor_test_operation_token(),
            );
        }],
    ]);
});

function localExecutorCommandBuilder(): LocalExecutorCommandBuilder
{
    return new LocalExecutorCommandBuilder;
}

function local_executor_test_operation_token(): string
{
    return implode('-', ['token', 'abc']);
}

function local_executor_test_operation_token_with_null_byte(): string
{
    return "token\0abc";
}

function localExecutorTargetNode(array $roles = ['app-dev']): Node
{
    $node = new Node(['name' => 'target']);

    $assignments = array_map(
        fn (string $role): NodeRoleAssignment => new NodeRoleAssignment([
            'role' => $role,
            'status' => 'active',
        ]),
        $roles,
    );

    $node->setRelation('roleAssignments', new EloquentCollection($assignments));

    return $node;
}
