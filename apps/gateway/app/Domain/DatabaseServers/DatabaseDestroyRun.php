<?php

declare(strict_types=1);

namespace App\Domain\DatabaseServers;

use App\Domain\DatabaseConnections\DatabaseDriver;
use App\Infrastructure\Swamp\SwampCommandStep;
use App\Infrastructure\Swamp\SwampSshExecutionInput;
use App\Models\DatabaseServer;
use App\Models\Node;

/**
 * The inputs of one `operation-database-destroy` run, for `database:destroy` and for each server child of
 * `instance:destroy`: the admin login and one whole vault value per batch, the password line first (Q-DBAUTH).
 */
final readonly class DatabaseDestroyRun
{
    public const string KEY_PATTERN = '^database-destroy-[1-9][0-9]*(-[1-9][0-9]*)?-(fence|drop|gone|restore)$';

    public const array EXECUTION_COPY = ['vault' => 'orbit-control-inputs', 'key_pattern' => self::KEY_PATTERN];

    /**
     * @param  array{fence: string, drop: string, gone: string, restore: string}  $statements
     * @return array{intent: array<string, mixed>, execution_secrets: array<string, array{vault: string, key: string}>}
     */
    public static function render(DatabaseServer $server, string $container, array $statements, string $runKey, bool $push): array
    {
        $secrets = [];
        foreach (['fence', 'drop', 'gone', 'restore'] as $batch) {
            $key = "database-destroy-{$runKey}-{$batch}";
            $secrets[$batch] = $push
                ? app(SwampCommandStep::class)->executionCopy('orbit-control-inputs', $key, $server->root_password."\n".$statements[$batch])
                : ['vault' => 'orbit-control-inputs', 'key' => $key];
        }
        [$admin, $database] = match ($server->driver) {
            DatabaseDriver::Pgsql => ['postgres', 'postgres'], DatabaseDriver::Redis => ['default', ''], default => ['root', 'mysql']
        };

        return ['intent' => ['driver' => $server->driver->value, 'admin_client' => DatabaseStatements::login($server->driver, $container, $admin, $database),
            'ssh_execution' => app(SwampSshExecutionInput::class)->forNode(Node::query()->findOrFail($server->node_id))],
            'execution_secrets' => $secrets];
    }
}
