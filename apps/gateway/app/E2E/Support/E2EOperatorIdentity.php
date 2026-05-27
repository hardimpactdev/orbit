<?php

declare(strict_types=1);

namespace App\E2E\Support;

final readonly class E2EOperatorIdentity
{
    public static function ensure(E2EInstance $operator, string $operatorUser, SshKeyPair $key): void
    {
        $php = <<<'PHP'
\App\Models\Node::query()
    ->where('name', 'operator-1')
    ->where('role', \App\Models\Node::OPERATOR_STORAGE_ROLE)
    ->delete();
PHP;

        E2ECommand::ssh(
            $operator,
            $operatorUser,
            $key,
            'cd '.escapeshellarg("/home/{$operatorUser}/orbit").' && php apps/gateway/artisan tinker --execute='.escapeshellarg($php),
            timeoutSeconds: 60,
        );
    }
}
