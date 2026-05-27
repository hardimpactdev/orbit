<?php

declare(strict_types=1);

use App\Services\RemoteShell\Exceptions\LocalExecutorCommandBuilderException;
use App\Services\RemoteShell\LocalExecutorCommandBuilder;
use Tests\TestCase;

uses(TestCase::class);

describe(LocalExecutorCommandBuilder::class, function (): void {
    it('builds the verify command with an operation token and json output', function (): void {
        $command = localExecutorCommandBuilder()->build(
            commandName: 'internal:executor:verify',
            arguments: [],
            options: [],
            operationToken: 'token-abc',
        );

        expect($command)->toBe("/usr/local/bin/orbit internal:executor:verify --operation-token='token-abc' --json");
    });

    it('appends escaped positional arguments after the command name', function (): void {
        $command = localExecutorCommandBuilder()->build(
            commandName: 'internal:workspace-adapter',
            arguments: ['polyscope', 'two words', "quote'arg", 7, 1.5, true, false],
            options: [],
            operationToken: 'token-abc',
        );

        expect($command)->toBe(implode(' ', [
            '/usr/local/bin/orbit',
            'internal:workspace-adapter',
            escapeshellarg('polyscope'),
            escapeshellarg('two words'),
            escapeshellarg("quote'arg"),
            escapeshellarg('7'),
            escapeshellarg('1.5'),
            escapeshellarg('1'),
            escapeshellarg('0'),
            "--operation-token='token-abc'",
            '--json',
        ]));
    });

    it('appends escaped option values after positional arguments', function (): void {
        $command = localExecutorCommandBuilder()->build(
            commandName: 'internal:wg-easy',
            arguments: ['state:update-user'],
            options: [
                'user-id' => 42,
                'state-path' => "/srv/wg easy/db's.sqlite",
                'enabled' => true,
                'locked' => false,
            ],
            operationToken: 'token-abc',
        );

        expect($command)->toBe(implode(' ', [
            '/usr/local/bin/orbit',
            'internal:wg-easy',
            escapeshellarg('state:update-user'),
            '--user-id='.escapeshellarg('42'),
            '--state-path='.escapeshellarg("/srv/wg easy/db's.sqlite"),
            '--enabled='.escapeshellarg('1'),
            '--locked='.escapeshellarg('0'),
            "--operation-token='token-abc'",
            '--json',
        ]));
    });

    it('escapes operation tokens before appending json output', function (): void {
        $token = "token with ' quote";

        $command = localExecutorCommandBuilder()->build(
            commandName: 'internal:executor:verify',
            arguments: [],
            options: [],
            operationToken: $token,
        );

        expect($command)->toBe('/usr/local/bin/orbit internal:executor:verify --operation-token='.escapeshellarg($token).' --json')
            ->and($command)->toEndWith(' --json');
    });

    it('builds an audit line with the operation token redacted', function (): void {
        $auditLine = localExecutorCommandBuilder()->buildAuditLine(
            commandName: 'internal:workspace-adapter',
            arguments: ['polyscope'],
            options: ['state-path' => '/home/orbit/.polyscope/polyscope.db'],
            operationToken: 'token-abc',
        );

        expect($auditLine)->toBe(implode(' ', [
            '/usr/local/bin/orbit',
            'internal:workspace-adapter',
            escapeshellarg('polyscope'),
            '--state-path='.escapeshellarg('/home/orbit/.polyscope/polyscope.db'),
            '--operation-token=<redacted>',
            '--json',
        ]))->not->toContain('token-abc');
    });

    it('rejects bad command names', function (string $commandName): void {
        expect(fn (): string => localExecutorCommandBuilder()->build(
            commandName: $commandName,
            arguments: [],
            options: [],
            operationToken: 'token-abc',
        ))->toThrow(LocalExecutorCommandBuilderException::class);
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

    it('rejects non-scalar arguments', function (Closure $argumentFactory): void {
        $argument = $argumentFactory();

        try {
            expect(fn (): string => localExecutorCommandBuilder()->build(
                commandName: 'internal:executor:verify',
                arguments: [$argument],
                options: [],
                operationToken: 'token-abc',
            ))->toThrow(LocalExecutorCommandBuilderException::class);
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
            commandName: 'internal:executor:verify',
            arguments: [],
            options: $options,
            operationToken: 'token-abc',
        ))->toThrow(LocalExecutorCommandBuilderException::class);
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
                commandName: 'internal:executor:verify',
                arguments: [],
                options: ['state-path' => $value],
                operationToken: 'token-abc',
            ))->toThrow(LocalExecutorCommandBuilderException::class);
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
        'command name' => [fn (LocalExecutorCommandBuilder $builder): string => $builder->build(
            commandName: "internal:executor\0verify",
            arguments: [],
            options: [],
            operationToken: 'token-abc',
        )],
        'argument' => [fn (LocalExecutorCommandBuilder $builder): string => $builder->build(
            commandName: 'internal:executor:verify',
            arguments: ["safe\0unsafe"],
            options: [],
            operationToken: 'token-abc',
        )],
        'option key' => [fn (LocalExecutorCommandBuilder $builder): string => $builder->build(
            commandName: 'internal:executor:verify',
            arguments: [],
            options: ["bad\0key" => 'value'],
            operationToken: 'token-abc',
        )],
        'option value' => [fn (LocalExecutorCommandBuilder $builder): string => $builder->build(
            commandName: 'internal:executor:verify',
            arguments: [],
            options: ['state-path' => "safe\0unsafe"],
            operationToken: 'token-abc',
        )],
        'operation token' => [fn (LocalExecutorCommandBuilder $builder): string => $builder->build(
            commandName: 'internal:executor:verify',
            arguments: [],
            options: [],
            operationToken: "token\0abc",
        )],
    ]);
});

function localExecutorCommandBuilder(): LocalExecutorCommandBuilder
{
    return new LocalExecutorCommandBuilder;
}
