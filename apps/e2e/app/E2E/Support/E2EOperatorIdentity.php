<?php

declare(strict_types=1);

namespace App\E2E\Support;

final readonly class E2EOperatorIdentity
{
    public static function ensure(E2EInstance $operator, string $operatorUser, SshKeyPair $key): void
    {
        $database = "/home/{$operatorUser}/.config/orbit/gateway.sqlite";
        $databaseValue = var_export($database, true);

        $php = <<<'PHP'
$database = DATABASE_PATH;
$pdo = new PDO('sqlite:'.$database);
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->exec('PRAGMA busy_timeout = 5000');
$statement = $pdo->prepare('DELETE FROM nodes WHERE name = :name');
$statement->execute(['name' => 'operator-1']);
PHP;

        $php = str_replace('DATABASE_PATH', $databaseValue, $php);

        E2ECommand::ssh(
            $operator,
            $operatorUser,
            $key,
            'php -r '.escapeshellarg($php),
            timeoutSeconds: 60,
        );
    }
}
