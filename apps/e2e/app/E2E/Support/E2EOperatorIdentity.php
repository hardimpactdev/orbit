<?php

declare(strict_types=1);

namespace App\E2E\Support;

final readonly class E2EOperatorIdentity
{
    public static function ensure(E2EInstance $operator, string $operatorUser, SshKeyPair $key): void
    {
        $configPath = "/home/{$operatorUser}/.config/orbit/config.json";
        $configPathValue = var_export($configPath, true);

        $php = <<<'PHP'
$configPath = CONFIG_PATH;

if (! is_file($configPath)) {
    exit(0);
}

$contents = file_get_contents($configPath);

if ($contents === false || trim($contents) === '') {
    exit(0);
}

$config = json_decode($contents, true, flags: JSON_THROW_ON_ERROR);

if (! is_array($config)) {
    exit(0);
}

if (($config['defaults']['node'] ?? null) === 'operator-1') {
    $config['defaults']['node'] = null;
}

if (isset($config['nodes']) && is_array($config['nodes'])) {
    unset($config['nodes']['operator-1']);
}

file_put_contents($configPath, json_encode($config, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES).PHP_EOL);
chmod($configPath, 0600);
PHP;

        $php = str_replace('CONFIG_PATH', $configPathValue, $php);

        E2ECommand::ssh(
            $operator,
            $operatorUser,
            $key,
            'php -r '.escapeshellarg($php),
            timeoutSeconds: 60,
        );
    }
}
