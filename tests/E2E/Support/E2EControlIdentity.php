<?php

declare(strict_types=1);

namespace Tests\E2E\Support;

final readonly class E2EControlIdentity
{
    public static function ensure(E2EInstance $control, string $controlUser, SshKeyPair $key): void
    {
        $controlUserValue = var_export($controlUser, true);
        $orbitPathValue = var_export("/home/{$controlUser}/orbit", true);

        $php = <<<PHP
\$node = \\App\\Models\\Node::query()->firstOrNew(['name' => 'control-1']);
\$node->fill([
    'role' => 'control',
    'environment' => null,
    'tld' => null,
    'platform' => \$node->platform ?: 'unknown',
    'host' => \$node->host ?: '127.0.0.1',
    'wireguard_address' => \$node->wireguard_address,
    'gateway_endpoint' => \$node->gateway_endpoint,
    'ssh_user' => \$node->ssh_user ?: {$controlUserValue},
    'user' => \$node->user ?: {$controlUserValue},
    'orbit_path' => \$node->orbit_path ?: {$orbitPathValue},
    'status' => 'active',
    'is_local' => true,
]);
\$node->save();
PHP;

        E2ECommand::ssh(
            $control,
            $controlUser,
            $key,
            'cd '.escapeshellarg("/home/{$controlUser}/orbit").' && php artisan tinker --execute='.escapeshellarg($php),
            timeoutSeconds: 60,
        );
    }
}
