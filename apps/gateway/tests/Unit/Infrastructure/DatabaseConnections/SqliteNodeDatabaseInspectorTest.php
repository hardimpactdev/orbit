<?php

declare(strict_types=1);

use App\Domain\Shared\ResourceOperationException;
use App\Infrastructure\Activity\CommandActivityInputSanitizer;
use App\Infrastructure\AgentCommands\AgentContract;
use App\Infrastructure\AgentCommands\AgentRunnerFailure;
use App\Infrastructure\DatabaseConnections\DatabaseResultRedactor;
use App\Infrastructure\DatabaseConnections\SqliteNodeDatabaseInspector;
use App\Models\DatabaseConnection;
use App\Models\Instance;
use App\Models\Node;
use App\Models\NodeRole;
use Illuminate\Database\Eloquent\Collection;
use Tests\Support\FakeAgentCommandRunner;

beforeEach(function (): void {
    $this->node = new Node(['id' => 7, 'user' => 'orbit', 'status' => 'active', 'wireguard_ip' => '10.44.0.7']);
    $this->node->id = 7;
    $this->node->setRelation('roles', new Collection([new NodeRole(['role' => 'app-dev'])]));
    $this->connection = new DatabaseConnection(['slug' => 'local', 'driver' => 'sqlite', 'node_id' => 7, 'path' => '/home/app/shared/database.sqlite']);
    $this->connection->setRelation('node', $this->node);
    $this->agents = new FakeAgentCommandRunner(new AgentContract(dirname(__DIR__, 5).'/agent/contract/commands.json'));
    $this->inspector = new SqliteNodeDatabaseInspector($this->agents, new DatabaseResultRedactor(new CommandActivityInputSanitizer));
});

it('sends the production owner and query intent to the native command', function (): void {
    $this->node->setRelation('roles', new Collection([new NodeRole(['role' => 'app-prod'])]));
    $owner = new Instance(['node_id' => 7, 'production_user' => 'orbit-app-7', 'production_home' => '/home/orbit-app-7']);
    $this->connection->setRelation('ownerInstance', $owner);
    $this->agents->answer('database.query-sqlite', ['columns' => ['n'], 'rows' => [['n' => 1]], 'row_count' => 1, 'truncated' => false]);

    expect($this->inspector->query($this->connection, 'SELECT 1', false)->rows)->toBe([['n' => 1]]);
    expect($this->agents->calls[0])->toMatchArray([
        'command' => 'database.query-sqlite',
        'input' => ['owner' => 'orbit-app-7', 'path' => $this->connection->path, 'sql' => 'SELECT 1'],
    ]);
});

it('keeps development reads as the Node user', function (): void {
    $this->agents->answer('database.query-sqlite', ['columns' => [], 'rows' => [], 'row_count' => 0, 'truncated' => false]);

    expect($this->inspector->query($this->connection, 'SELECT 1', false)->rows)->toBe([]);
    expect($this->agents->calls[0])->toMatchArray([
        'command' => 'database.query-sqlite',
        'input' => ['owner' => 'orbit', 'path' => $this->connection->path, 'sql' => 'SELECT 1'],
    ]);
});

it('refuses direct development writes before invoking the agent', function (): void {
    expect(fn () => $this->inspector->query($this->connection, 'UPDATE settings SET value = 1', true))
        ->toThrow(ResourceOperationException::class, 'SQL writes run as database.write Operations.');
    expect($this->agents->calls)->toBe([]);
});

it('maps native refusals without returning private details', function (string $refusal, string $expected): void {
    $this->agents->refuse('database.query-sqlite', $refusal, ['private' => 'private-remote-value']);

    try {
        $this->inspector->query($this->connection, 'SELECT 1', false);
        test()->fail('Inspection unexpectedly succeeded.');
    } catch (ResourceOperationException $exception) {
        expect($exception->errorCode)->toBe($expected)
            ->and($exception->getMessage())->not->toContain('private-remote-value');
    }
})->with([
    ['database.sqlite_unreadable', 'database.sqlite_unreadable'],
    ['database.query_failed', 'database.query_failed'],
    ['agent.identity_mismatch', 'database.query_failed'],
]);

it('maps a runner failure to a safe query error', function (): void {
    $this->agents->answer('database.query-sqlite', static fn (): array => throw new AgentRunnerFailure(
        'database.query-sqlite', AgentRunnerFailure::Transport, 'private-remote-value',
    ));

    try {
        $this->inspector->query($this->connection, 'SELECT 1', false);
        test()->fail('Inspection unexpectedly succeeded.');
    } catch (ResourceOperationException $exception) {
        expect($exception->errorCode)->toBe('database.query_failed')
            ->and($exception->getMessage())->not->toContain('private-remote-value');
    }
});
