<?php

declare(strict_types=1);

namespace App\Domain\WebSocket;

use App\Models\WebSocketApp;
use App\Models\WebSocketRuntime;
use Closure;
use Illuminate\Support\Facades\DB;

/** One consistent snapshot and one write serialization point for registry transactions. */
final readonly class WebSocketRegistry
{
    /**
     * @template T
     *
     * @param  Closure(): T  $callback
     * @return T
     */
    public function write(Closure $callback): mixed
    {
        return DB::transaction(static function () use ($callback): mixed {
            // Acquire SQLite's writer before any reads; FOR UPDATE alone does not lock SQLite.
            DB::table('websocket_registry')->where('id', 1)->update(['revision' => DB::raw('revision')]);

            return $callback();
        });
    }

    /** The caller must hold operator confirmation and establish that the Node is unreachable or deleted. */
    public function abandonNode(int $nodeId): void
    {
        $this->write(fn (): int => WebSocketRuntime::query()->where('node_id', $nodeId)->update(['required' => false]));
    }

    /** @return array{epoch: string, revision: int} */
    public function version(): array
    {
        $row = DB::table('websocket_registry')->where('id', 1)->firstOrFail();

        return ['epoch' => (string) $row->epoch, 'revision' => (int) $row->revision];
    }

    /** @return array{epoch: string, revision: int} */
    public function advance(): array
    {
        DB::table('websocket_registry')->where('id', 1)->increment('revision');

        return $this->version();
    }

    /** @return array{schema_version: int, epoch: string, revision: int, apps: list<array<string, mixed>>} */
    public function snapshot(): array
    {
        return DB::transaction(fn (): array => ['schema_version' => 1, ...$this->version(), 'apps' => array_values(WebSocketApp::query()
            ->orderBy('app_id')->get()->map(static fn (WebSocketApp $app): array => [
                'app_id' => $app->app_id, 'key' => $app->key, 'secret' => $app->secret,
                'credential_generation' => $app->credential_generation,
                'allowed_origins' => ['*'], 'ping_interval' => 60, 'activity_timeout' => 30,
                'max_message_size' => 10000, 'max_connections' => null,
                'accept_client_events_from' => 'members', 'rate_limiting' => null, 'options' => [],
            ])->all())]);
    }
}
