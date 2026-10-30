<?php

declare(strict_types=1);

use App\Domain\DatabaseServers\DatabaseChildAdmission;
use App\Domain\DatabaseServers\DatabaseOperationCompletion;
use App\Domain\DatabaseServers\DatabaseOperationExecution;
use App\Domain\Operations\OperationKinds;

return (static function (): array {
    $direct = ['database.sqlite_cleanup' => ['executor' => 'swamp', 'result_fields' => ['backend', 'error_code'], 'admission' => DatabaseChildAdmission::class], 'database_server.close' => ['executor' => 'swamp', 'result_fields' => ['backend', 'error_code']], 'database_server.destroy' => ['executor' => 'swamp', 'result_fields' => ['backend', 'error_code']], 'database_user.create' => ['executor' => 'swamp', 'result_fields' => ['backend', 'error_code']]];
    if (! OperationKinds::supports('children') || ! OperationKinds::supports('receipts')) {
        return $direct;
    }
    $string = ['type' => 'string'];
    $object = ['type' => 'object'];
    $valkey = ['transport' => 'remote_program', 'name' => 'database.valkey', 'input_fields' => [], 'input_values' => [],
        'secrets' => ['password' => 'database.intent', 'payload' => 'database.intent'],
        'input_schema' => ['type' => 'object', 'additionalProperties' => false,
            'required' => ['host', 'port', 'password', 'default_open', 'action', 'payload'],
            'properties' => ['host' => $string, 'port' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 65535],
                'password' => $string, 'default_open' => ['type' => 'boolean'], 'action' => ['enum' => ['ready', 'create', 'remove', 'converge']], 'payload' => $object]]];
    $sql = ['transport' => 'agent', 'name' => 'database.server-admin', 'input_fields' => [], 'input_values' => ['operation' => 'execute'],
        'secrets' => ['password' => 'database.intent', 'sql' => 'database.intent']];
    $ready = [...$sql, 'input_values' => ['operation' => 'ready'], 'secrets' => ['password' => 'database.intent']];
    $container = ['transport' => 'agent', 'name' => 'process.converge-container', 'input_fields' => [], 'input_values' => [],
        'secrets' => ['environment' => 'database.intent']];
    $acl = ['transport' => 'agent', 'name' => 'database.write-valkey-acl', 'input_fields' => [], 'input_values' => [], 'secrets' => ['acl' => 'database.intent']];
    $pdo = ['transport' => 'remote_program', 'name' => 'database.write', 'input_fields' => [], 'input_values' => [],
        'secrets' => ['password' => 'database.intent', 'sql' => 'database.intent'],
        'input_schema' => ['type' => 'object', 'additionalProperties' => false, 'required' => ['dsn', 'username', 'password', 'sql'],
            'properties' => ['dsn' => $string, 'username' => ['type' => ['string', 'null']], 'password' => $string, 'sql' => $string]]];
    $sqlite = ['transport' => 'agent', 'name' => 'database.write-sqlite', 'input_fields' => [], 'input_values' => [], 'secrets' => ['sql' => 'database.intent']];
    $copy = [...$sql, 'input_values' => ['operation' => 'copy'], 'secrets' => ['password' => 'database.intent'], 'resumable' => false];
    $sqliteCopy = ['transport' => 'agent', 'name' => 'instance.copy-sqlite', 'input_fields' => [], 'input_values' => []];
    $owned = ['transport' => 'gateway', 'name' => 'database.owned-ready', 'input_fields' => [], 'input_values' => []];
    $relay = ['transport' => 'database_sqlite', 'name' => 'database.receive-snapshot', 'input_fields' => [], 'input_values' => []];
    $control = ['transport' => 'agent', 'name' => 'process.control', 'input_fields' => [], 'input_values' => ['operation' => 'restart']];
    $seedSource = ['transport' => 'agent', 'name' => 'instance.prepare-sqlite-seed', 'input_fields' => [], 'input_values' => []];
    $seedTarget = ['transport' => 'agent', 'name' => 'instance.install-sqlite-seed', 'input_fields' => [], 'input_values' => []];
    $kinds = [];
    foreach ([
        'database_server.create' => [$container, $acl, $ready, $valkey, $control],
        'database.create' => [$sql, $valkey],
        'database.destroy' => [[...$sql, 'transport' => 'database_removal'], $sql, $valkey],
        'database.clone' => [$sql, $copy, $sqliteCopy, $relay],
        'database.destroy_owned' => [$sqliteCopy, $owned],
        'database.sqlite_export' => [$sqliteCopy, $seedSource],
        'database.sqlite_seed' => [$seedTarget, $relay],
        'database.sqlite_seed_abandon' => [$seedTarget],
        'database.write' => [$sqlite, $pdo],
    ] as $kind => $runs) {
        if ($kind === 'database.sqlite_seed_abandon' && ! OperationKinds::supports('compensations')) {
            continue;
        }
        $kinds[$kind] = ['executor' => 'swamp', 'execution' => DatabaseOperationExecution::class,
            'completion' => DatabaseOperationCompletion::class, 'runs' => $runs,
            'children' => match ($kind) {
                'database.sqlite_seed_abandon' => [['kind' => 'database.sqlite_cleanup', 'node_from' => 'intent.node_ids']],
                'database.destroy_owned' => [['kind' => 'database.destroy', 'node_from' => 'intent.node_ids']],
                'database.clone', 'database.sqlite_seed' => [['kind' => 'database.sqlite_export', 'node_from' => 'intent.node_ids'], ['kind' => 'database.sqlite_cleanup', 'node_from' => 'intent.node_ids']],
                default => [],
            },
            'completion_contract' => ['step' => 'database-complete', 'transport' => 'gateway', 'name' => 'database.proven', 'result' => []],
            'result_fields' => ['backend', 'error_code', 'row_count', 'truncated', 'bytes', 'sha256', 'mode', 'incoming', 'state']];
    }

    foreach ($kinds as &$definition) {
        $definition['admission'] = DatabaseChildAdmission::class;
        $definition['runs'][] = ['transport' => 'gateway', 'name' => 'database.proven', 'input_fields' => ['intent_id'], 'input_values' => [], 'resumable' => true];
    }
    unset($definition);

    return [...$direct, ...$kinds];
})();
