<?php

declare(strict_types=1);

namespace App\Commands\Internal;

use Orbit\Core\Security\OperationToken;

final class VerifyExecutorCommand extends InternalExecutorCommand
{
    #[\Override]
    protected $signature = 'internal:executor:verify {--operation-token=} {--json}';

    #[\Override]
    protected $description = 'Verify an internal executor operation token';

    public function handle(): int
    {
        $compactToken = $this->option('operation-token');

        if (! is_string($compactToken) || trim($compactToken) === '') {
            return $this->renderFailure('missing_token', 'Operation token is required.', []);
        }

        if (! $this->verifyOperationToken('internal:executor:verify')) {
            return self::FAILURE;
        }

        $token = OperationToken::parse($compactToken);

        return $this->emitInternalSuccess([
            'operation_id' => $token->id,
            'node' => $token->node,
            'command' => $token->command,
        ]);
    }
}
