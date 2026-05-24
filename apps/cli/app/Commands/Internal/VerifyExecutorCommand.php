<?php

declare(strict_types=1);

namespace App\Commands\Internal;

use App\Commands\OrbitCommand;
use App\Exceptions\OperationTokenGuardException;
use App\Services\Executor\OperationTokenGuard;

final class VerifyExecutorCommand extends OrbitCommand
{
    protected $signature = 'internal:executor:verify {--operation-token=} {--json}';

    protected $description = 'Verify an internal executor operation token';

    public function handle(OperationTokenGuard $guard): int
    {
        $compactToken = $this->option('operation-token');

        if (! is_string($compactToken) || trim($compactToken) === '') {
            return $this->renderFailure('missing_token', 'Operation token is required.', []);
        }

        try {
            $token = $guard->verify($compactToken, 'internal:executor:verify');
        } catch (OperationTokenGuardException) {
            return $this->renderFailure('invalid_token', 'Operation token is invalid.', []);
        }

        return $this->renderSuccess([
            'operation_id' => $token->id,
            'node' => $token->node,
            'command' => $token->command,
        ], []);
    }
}
