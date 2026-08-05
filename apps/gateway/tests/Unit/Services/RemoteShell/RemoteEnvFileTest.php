<?php

declare(strict_types=1);

use App\Data\RemoteShell\RemoteShellResult;
use App\Models\Node;
use App\Services\RemoteShell\RemoteEnvFile;
use App\Services\RemoteShell\RunsInternalCommands;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;

uses(TestCase::class);
uses(RefreshDatabase::class);

it('returns string contents from a well-formed successful read', function (): void {
    $executor = new RemoteEnvFileRecordingExecutor([
        remote_env_file_success_result(['path' => '/home/orbit/apps/demo/.env', 'contents' => "APP_ENV=local\n"]),
    ]);
    $node = Node::factory()->create();

    $contents = new RemoteEnvFile($executor)->read($node, '/home/orbit/apps/demo/.env');

    expect($contents)
        ->toBe("APP_ENV=local\n")
        ->and($executor->actions)
        ->toBe(['read']);
});

it('returns null only for explicit env_file.not_found', function (): void {
    $executor = new RemoteEnvFileRecordingExecutor([
        remote_env_file_error_result('env_file.not_found', 'Env file was not found.'),
    ]);
    $node = Node::factory()->create();

    $contents = new RemoteEnvFile($executor)->read($node, '/home/orbit/apps/demo/.env');

    expect($contents)->toBeNull();
});

it('throws when a successful read envelope lacks string contents', function (string $stdout): void {
    $executor = new RemoteEnvFileRecordingExecutor([
        new RemoteShellResult(exitCode: 0, stdout: $stdout, stderr: '', durationMs: 1),
    ]);
    $node = Node::factory()->create();
    $path = '/home/orbit/apps/demo/.env';

    expect(fn () => new RemoteEnvFile($executor)->read($node, $path))
        ->toThrow(RuntimeException::class, "Env file read response for {$path} is missing string contents.");
})->with([
    'missing contents key' =>
        json_encode([
            'success' => ['data' => ['path' => '/home/orbit/apps/demo/.env']],
        ], JSON_THROW_ON_ERROR)."\n",
    'null contents' =>
        json_encode([
            'success' => ['data' => ['path' => '/home/orbit/apps/demo/.env', 'contents' => null]],
        ], JSON_THROW_ON_ERROR)."\n",
    'non-string contents' =>
        json_encode([
            'success' => ['data' => ['path' => '/home/orbit/apps/demo/.env', 'contents' => 123]],
        ], JSON_THROW_ON_ERROR)."\n",
    'malformed success envelope' => "{\"not_success\":true}\n",
    'empty stdout' => '',
]);

it('throws on non-not-found read failures', function (): void {
    $executor = new RemoteEnvFileRecordingExecutor([
        remote_env_file_error_result('env_file.read_failed', 'Env file could not be read.'),
    ]);
    $node = Node::factory()->create();

    expect(fn () => new RemoteEnvFile($executor)->read($node, '/home/orbit/apps/demo/.env'))
        ->toThrow(RuntimeException::class, 'Env file could not be read.');
});

it('never writes after a malformed successful read', function (): void {
    $path = '/home/orbit/apps/demo/.env';
    $executor = new RemoteEnvFileRecordingExecutor([
        new RemoteShellResult(
            exitCode: 0,
            stdout: json_encode([
                'success' => ['data' => ['path' => $path]],
            ], JSON_THROW_ON_ERROR)
                ."\n",
            stderr: '',
            durationMs: 1,
        ),
        remote_env_file_success_result(['path' => $path, 'bytes' => 12]),
    ]);
    $envFile = new RemoteEnvFile($executor);
    $node = Node::factory()->create();

    $caught = null;

    try {
        $contents = $envFile->read($node, $path);
        // Callers only write after a successful read; this path must not be reached.
        $envFile->write($node, $path, $contents ?? '');
    } catch (RuntimeException $exception) {
        $caught = $exception;
    }

    expect($caught)
        ->toBeInstanceOf(RuntimeException::class)
        ->and($caught?->getMessage())
        ->toContain('missing string contents')
        ->and($executor->actions)
        ->toBe(['read'])
        ->not->toContain('write');
});

/**
 * @param  array<string, mixed>  $data
 */
function remote_env_file_success_result(array $data): RemoteShellResult
{
    return new RemoteShellResult(
        exitCode: 0,
        stdout: json_encode(['success' => ['data' => $data]], JSON_THROW_ON_ERROR)."\n",
        stderr: '',
        durationMs: 1,
    );
}

function remote_env_file_error_result(string $code, string $message): RemoteShellResult
{
    return new RemoteShellResult(
        exitCode: 1,
        stdout: json_encode([
            'error' => [
                'code' => $code,
                'message' => $message,
            ],
        ], JSON_THROW_ON_ERROR)
            ."\n",
        stderr: '',
        durationMs: 1,
    );
}

/**
 * @mago-expect lint:file-name
 */
final class RemoteEnvFileRecordingExecutor implements RunsInternalCommands
{
    /**
     * @var list<string>
     */
    public array $actions = [];

    /**
     * @param  list<RemoteShellResult>  $responses
     */
    public function __construct(
        private array $responses,
    ) {}

    public function runInternal(
        Node $node,
        string $commandName,
        array $arguments = [],
        array $commandOptions = [],
        array $transportOptions = [],
    ): RemoteShellResult {
        $input = $transportOptions['input'] ?? null;
        $payload = is_string($input)
            ? json_decode($input, associative: true, flags: JSON_THROW_ON_ERROR)
            : [];
        $action = is_array($payload) && is_string($payload['action'] ?? null)
            ? $payload['action']
            : 'unknown';
        $this->actions[] = $action;

        return (
            array_shift($this->responses) ?? new RemoteShellResult(
                exitCode: 1,
                stdout: '',
                stderr: 'no response queued',
                durationMs: 1,
            )
        );
    }
}
