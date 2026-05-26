<?php

declare(strict_types=1);

namespace App\E2E\Support;

final readonly class E2EControlIdentity
{
    public static function ensureOperator(E2EInstance $control, string $operatorUser, SshKeyPair $key): void
    {
        self::ensure($control, $operatorUser, $key);
    }

    #[\Deprecated(message: 'Migration alias. The legacy control identity remains in place for this E2E window.')]
    public static function ensure(E2EInstance $control, string $operatorUser, SshKeyPair $key): void
    {
        $php = <<<'PHP'
\App\Models\Node::query()
    ->where('name', 'control-1')
    ->where('role', 'control')
    ->delete();
PHP;

        E2ECommand::ssh(
            $control,
            $operatorUser,
            $key,
            'cd '.escapeshellarg("/home/{$operatorUser}/orbit").' && php artisan tinker --execute='.escapeshellarg($php),
            timeoutSeconds: 60,
        );
    }
}
