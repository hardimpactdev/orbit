<?php

declare(strict_types=1);

use App\Domain\Instances\Sqlite\SqliteSeedPlacement;
use App\Domain\Instances\Sqlite\SqliteSeedResult;
use App\Domain\Instances\Sqlite\SqliteSnapshotTransfer;
use App\Domain\Shared\ResourceOperationException;
use App\Infrastructure\AgentCommands\AgentCommandRefused;
use App\Infrastructure\Instances\RemoteInstanceSqliteSeeder;
use App\Models\Node;
use Tests\Support\FakeAgentCommandRunner;

const SEED_SOURCE = '/srv/apps/acme/database/database.sqlite';

function seed_placement(int $instance, string $environment, string $base, string $user, int $node): SqliteSeedPlacement
{
    $model = new Node;
    $model->forceFill(['id' => $node, 'wireguard_ip' => "10.44.0.{$node}", 'user' => 'orbit']);

    return new SqliteSeedPlacement(instanceId: $instance, environment: $environment, basePath: $base, executionUser: $user, node: $model);
}

function seed_source(): SqliteSeedPlacement
{
    return seed_placement(11, 'development', '/srv/apps/acme', 'orbit', 1);
}

function seed_target(): SqliteSeedPlacement
{
    return seed_placement(22, 'production', '/home/orbit-app-22', 'orbit-app-22', 2);
}

/** A seeder over `$agents` whose transfer records each move. */
function seed_seeder(FakeAgentCommandRunner $agents, ?Closure $transfer = null): array
{
    $moves = new ArrayObject;
    $seeder = new RemoteInstanceSqliteSeeder($agents, new class($moves, $transfer) implements SqliteSnapshotTransfer
    {
        public function __construct(private ArrayObject $moves, private ?Closure $failure) {}

        public function transfer(SqliteSeedPlacement $source, string $sourcePath, SqliteSeedPlacement $target, string $targetPath, int $expectedBytes, string $expectedDigest): void
        {
            $this->moves[] = [$sourcePath, $targetPath, $expectedBytes, $expectedDigest];
            ($this->failure)?->__invoke();
        }
    });

    return [$seeder, $moves];
}

/** Agents whose source half answers a snapshot and whose target half answers `$target` for each mode. */
function seed_agents(array $target, array $refusals = []): FakeAgentCommandRunner
{
    $digest = str_repeat('d', 64);

    return new FakeAgentCommandRunner()
        ->answer('instance.prepare-sqlite-seed', static fn (array $input): array => $input['mode'] === 'prepare'
            ? ['bytes' => 4096, 'sha256' => $digest, 'snapshot' => $input['snapshot_path']]
            : [])
        ->answer('instance.install-sqlite-seed', static function (array $input) use ($target, $refusals): array {
            if (isset($refusals[$input['mode']])) {
                throw new AgentCommandRefused('instance.install-sqlite-seed', $refusals[$input['mode']], 'Refused.');
            }

            return $target[$input['mode']];
        });
}

it('takes a snapshot, moves it to the incoming file, installs it, and cleans up the source', function (): void {
    $agents = seed_agents(['prepare' => ['state' => 'ready', 'incoming' => '/tmp/orbit-sqlite-abc-1'], 'install' => ['state' => 'changed']]);
    [$seeder, $moves] = seed_seeder($agents);

    expect($seeder->seed(seed_source(), seed_target(), SEED_SOURCE))->toEqual(SqliteSeedResult::changed());

    $calls = array_map(static fn (array $call): string => $call['command'].':'.$call['input']['mode'], $agents->calls);
    expect($calls)->toBe(['instance.prepare-sqlite-seed:prepare', 'instance.install-sqlite-seed:prepare', 'instance.install-sqlite-seed:install', 'instance.prepare-sqlite-seed:cleanup'])
        ->and($moves[0][1])->toBe('/tmp/orbit-sqlite-abc-1')
        ->and($moves[0][2])->toBe(4096)
        ->and($agents->calls[0]['input'])->toMatchArray(['base' => '/srv/apps/acme', 'source_path' => SEED_SOURCE, 'source_user' => 'orbit', 'transport_user' => 'orbit'])
        ->and($agents->calls[0]['input']['snapshot_path'])->toStartWith('/tmp/orbit-sqlite-')
        ->and($agents->calls[1]['input'])->toMatchArray(['base' => '/home/orbit-app-22', 'target_user' => 'orbit-app-22', 'relative_path' => 'database.sqlite', 'environment' => 'production', 'expected_size' => 4096]);
});

it('reports an earlier install as unchanged and resumes a moved snapshot without moving it again', function (string $state, string $installed, SqliteSeedResult $result, int $moves): void {
    $agents = seed_agents(['prepare' => ['state' => $state], 'install' => ['state' => $installed]]);
    [$seeder, $recorded] = seed_seeder($agents);

    expect($seeder->seed(seed_source(), seed_target(), SEED_SOURCE))->toEqual($result)
        ->and($recorded)->toHaveCount($moves);
})->with([
    'complete' => ['complete', 'complete', SqliteSeedResult::unchanged(), 0],
    'resume' => ['resume', 'changed', SqliteSeedResult::changed(), 0],
]);

it('cleans up the source and fails preflight when the target refuses', function (): void {
    $agents = seed_agents([], ['prepare' => 'instance.sqlite_seed_refused']);

    expect(fn () => seed_seeder($agents)[0]->seed(seed_source(), seed_target(), SEED_SOURCE))
        ->toThrow(fn (ResourceOperationException $exception) => expect($exception->errorCode)->toBe('sqlite.seed_preflight_failed'));
    expect(array_column(array_column($agents->calls, 'input'), 'mode'))->toBe(['prepare', 'prepare', 'cleanup']);
});

it('maps each install outcome', function (string $refusal, ?string $errorCode, bool $cleansSource): void {
    $agents = seed_agents(['prepare' => ['state' => 'ready', 'incoming' => '/tmp/orbit-sqlite-abc-1']], ['install' => $refusal]);
    $seeder = seed_seeder($agents)[0];

    if ($errorCode === null) {
        expect($seeder->seed(seed_source(), seed_target(), SEED_SOURCE))->toEqual(SqliteSeedResult::unconfirmed());
    } else {
        expect(fn () => $seeder->seed(seed_source(), seed_target(), SEED_SOURCE))
            ->toThrow(fn (ResourceOperationException $exception) => expect($exception->errorCode)->toBe($errorCode));
    }
    expect(end($agents->calls)['input']['mode'] === 'cleanup')->toBe($cleansSource);
})->with([
    'refused' => ['instance.sqlite_seed_refused', 'sqlite.seed_failed', true],
    'failed' => ['instance.sqlite_seed_failed', 'sqlite.seed_failed', false],
    'unconfirmed' => ['instance.sqlite_seed_unconfirmed', null, false],
]);

it('fails the transfer safely and keeps both halves for a retry', function (): void {
    $agents = seed_agents(['prepare' => ['state' => 'ready', 'incoming' => '/tmp/orbit-sqlite-abc-1']]);
    [$seeder] = seed_seeder($agents, static fn () => throw new RuntimeException('scp failed'));

    expect(fn () => $seeder->seed(seed_source(), seed_target(), SEED_SOURCE))
        ->toThrow(fn (ResourceOperationException $exception) => expect($exception->errorCode)->toBe('sqlite.seed_transfer_failed'));
    expect(array_column(array_column($agents->calls, 'input'), 'mode'))->toBe(['prepare', 'prepare']);
});

it('refuses an incoming path outside the private seed files', function (): void {
    $agents = seed_agents(['prepare' => ['state' => 'ready', 'incoming' => '/home/orbit/elsewhere']]);

    expect(fn () => seed_seeder($agents)[0]->seed(seed_source(), seed_target(), SEED_SOURCE))
        ->toThrow(fn (ResourceOperationException $exception) => expect($exception->errorCode)->toBe('sqlite.seed_preflight_failed'));
});

it('abandons both halves and reports whether both cleanups were confirmed', function (bool $targetCleans): void {
    $agents = seed_agents(['cleanup' => ['state' => $targetCleans ? 'cleaned' : 'ready']]);

    expect(seed_seeder($agents)[0]->abandon(seed_source(), seed_target(), SEED_SOURCE))->toBe($targetCleans);
})->with([true, false]);

it('refuses an unsafe request before it asks an agent', function (Closure $request): void {
    $agents = new FakeAgentCommandRunner;
    [$source, $target, $path] = $request();

    expect(fn () => seed_seeder($agents)[0]->seed($source, $target, $path))
        ->toThrow(fn (ResourceOperationException $exception) => expect($exception->errorCode)->toBe('sqlite.seed_preflight_failed'));
    expect($agents->calls)->toBe([]);
})->with([
    'outside source' => fn () => [seed_source(), seed_target(), '/srv/apps/other/database.sqlite'],
    'relative source' => fn () => [seed_source(), seed_target(), 'database.sqlite'],
    'production target outside its home' => fn () => [seed_source(), seed_placement(22, 'production', '/srv/elsewhere', 'orbit-app-22', 2), SEED_SOURCE],
    'development target of another Instance' => fn () => [seed_source(), seed_placement(22, 'development', '/srv/apps/acme', 'orbit', 2), SEED_SOURCE],
    'development target on the same Node' => fn () => [seed_source(), seed_placement(11, 'development', '/srv/apps/acme', 'orbit', 1), SEED_SOURCE],
]);
