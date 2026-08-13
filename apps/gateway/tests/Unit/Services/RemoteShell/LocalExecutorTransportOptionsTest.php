<?php

declare(strict_types=1);

use App\Services\RemoteShell\LocalExecutorTransportOptions;
use Illuminate\Support\Str;

it('provides the local executor defaults', function (): void {
    $options = LocalExecutorTransportOptions::fromArray([]);

    expect($options->timeoutSeconds())
        ->toBe(30)
        ->and($options->streamTimeoutSeconds())
        ->toBe(0)
        ->and($options->input())
        ->toBeNull()
        ->and($options->boundInput())
        ->toBeNull()
        ->and($options->cwd())
        ->toBeNull()
        ->and($options->environment())
        ->toBeEmpty()
        ->and($options->shouldBindApplicationKey())
        ->toBeTrue()
        ->and($options->forceRemoteHost())
        ->toBeFalse()
        ->and($options->redactStdout())
        ->toBeFalse()
        ->and($options->redactStderr())
        ->toBeFalse()
        ->and($options->redactedCommandOptionNames())
        ->toBeEmpty()
        ->and(Str::isUuid($options->operationId()))
        ->toBeTrue();
});

it('resolves the supported local executor values', function (): void {
    $options = LocalExecutorTransportOptions::fromArray([
        'cwd' => '/srv/orbit',
        'timeout' => 45,
        'input' => 'payload',
        'environment' => ['HOME' => '/home/orbit'],
        'metadata' => ['ORBIT_OPERATION_ID' => 'operation-402'],
        'redact_stdout' => true,
        'redact_stderr' => true,
        'redact_command_options' => ['private-key', 'private-key', 'password-hash'],
        'bind_application_key' => false,
        'bind_input' => true,
        'force_remote_host' => true,
    ]);

    expect($options->operationId())
        ->toBe('operation-402')
        ->and($options->timeoutSeconds())
        ->toBe(45)
        ->and($options->streamTimeoutSeconds())
        ->toBe(45)
        ->and($options->input())
        ->toBe('payload')
        ->and($options->boundInput())
        ->toBe('payload')
        ->and($options->cwd())
        ->toBe('/srv/orbit')
        ->and($options->environment())
        ->toBe(['HOME' => '/home/orbit'])
        ->and($options->shouldBindApplicationKey())
        ->toBeFalse()
        ->and($options->forceRemoteHost())
        ->toBeTrue()
        ->and($options->redactStdout())
        ->toBeTrue()
        ->and($options->redactStderr())
        ->toBeTrue()
        ->and($options->redactedCommandOptionNames())
        ->toBe(['private-key', 'password-hash']);
});

it('omits executor-only values from dispatch options', function (): void {
    $options = LocalExecutorTransportOptions::fromArray([
        'cwd' => '/srv/orbit',
        'timeout' => 45,
        'redact_stdout' => true,
        'redact_stderr' => true,
        'redact_command_options' => ['private-key'],
        'bind_application_key' => false,
        'bind_input' => false,
    ]);

    expect($options->dispatchOptions(['HOME' => '/home/orbit']))->toBe([
        'cwd' => '/srv/orbit',
        'timeout' => 45,
        'environment' => ['HOME' => '/home/orbit'],
    ]);
});

it('can exclude input from the operation token context', function (): void {
    $options = LocalExecutorTransportOptions::fromArray([
        'input' => 'secret input',
        'bind_input' => false,
    ]);

    expect($options->input())->toBe('secret input')->and($options->boundInput())->toBeNull();
});

it('rejects invalid local executor option values', function (array $values, string $method, string $message): void {
    $options = LocalExecutorTransportOptions::fromArray($values);

    expect(fn (): mixed => $options->{$method}())->toThrow(RuntimeException::class, $message);
})->with([
    'run timeout' => [['timeout' => 0], 'timeoutSeconds', 'timeout must be a positive integer.'],
    'stream timeout' => [['timeout' => -1], 'streamTimeoutSeconds', 'stream timeout must be a non-negative integer.'],
    'input' => [['input' => 42], 'input', 'input must be a string.'],
    'cwd' => [['cwd' => 42], 'cwd', 'cwd must be a string.'],
    'environment container' => [
        ['environment' => 'HOME=/tmp'],
        'environment',
        'environment must be an array of string values.',
    ],
    'environment value' => [
        ['environment' => ['HOME' => 42]],
        'environment',
        'environment must be an array of string values.',
    ],
    'application key binding' => [
        ['bind_application_key' => 'yes'],
        'shouldBindApplicationKey',
        'bind_application_key must be a boolean.',
    ],
    'input binding' => [['bind_input' => 'yes'], 'boundInput', 'bind_input must be a boolean.'],
    'redacted option container' => [
        ['redact_command_options' => 'private-key'],
        'redactedCommandOptionNames',
        'redact_command_options must be a list of command option names.',
    ],
    'redacted option name' => [
        ['redact_command_options' => ['private_key']],
        'redactedCommandOptionNames',
        'redact_command_options must be a list of command option names.',
    ],
]);
