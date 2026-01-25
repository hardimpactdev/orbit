<?php

$home = $_SERVER['HOME'] ?? getenv('HOME') ?: '/tmp';
$dbPath = getenv('DB_DATABASE') ?: getenv('ORBIT_TEST_DB') ?: "{$home}/.config/orbit/database.sqlite";

return [

    'default' => 'sqlite',

    'connections' => [
        'sqlite' => [
            'driver' => 'sqlite',
            'database' => $dbPath,
            'prefix' => '',
            'foreign_key_constraints' => true,
        ],
    ],

    'migrations' => [
        'table' => 'migrations',
        'update_date_on_publish' => true,
    ],

];
