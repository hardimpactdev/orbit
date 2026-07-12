<?php

declare(strict_types=1);

namespace App\Commands\Node;

use App\Commands\GatewayCommand;
use App\Exceptions\GatewayApiException;
use App\Services\Platform\LocalPlatformDetector;

final class NodeManageCommand extends GatewayCommand
{
    #[\Override]
    protected $signature = 'node:manage
        {--user= : Local user the Orbit Agent runs as}
        {--json : Output JSON}';

    #[\Override]
    protected $description = 'Opt this roleless node into managed Orbit Agent execution.';

    public function handle(LocalPlatformDetector $platform): int
    {
        try {
            $me = $this->gatewayGet('/api/me');
        } catch (GatewayApiException $exception) {
            return $this->renderGatewayFailure($exception);
        }

        if (! $this->isActiveRolelessSelf($me)) {
            return $this->renderFailure('node.not_operator', 'Only active roleless nodes can run node:manage.');
        }

        $targetUser = $this->targetUser();
        $currentUser = $this->currentUser();

        if ($targetUser !== $currentUser) {
            return $this->renderFailure(
                'validation_failed',
                '--user must match the current local user for node:manage.',
                [
                    'field' => 'user',
                    'current_user' => $currentUser,
                ],
            );
        }

        try {
            $response = $this->gatewayPost('/api/nodes/self/manage', [
                'user' => $targetUser,
                'platform' => $platform->current(),
            ]);
        } catch (GatewayApiException $exception) {
            return $this->renderGatewayFailure($exception);
        }

        return $this->renderSuccess($response);
    }

    /**
     * @param  array<string, mixed>  $response
     */
    private function isActiveRolelessSelf(array $response): bool
    {
        $self = $response['success']['data']['self'] ?? null;

        if (! is_array($self) || ($self['status'] ?? null) !== 'active') {
            return false;
        }

        $roles = $self['roles'] ?? [];

        if (! is_array($roles)) {
            return false;
        }

        return array_all(
            $roles,
            fn (mixed $role): bool => ! (is_array($role) && ($role['status'] ?? 'active') === 'active'),
        );
    }

    private function targetUser(): string
    {
        $option = $this->option('user');

        if (is_string($option) && trim($option) !== '') {
            return trim($option);
        }

        return $this->currentUser();
    }

    private function currentUser(): string
    {
        foreach (['USER', 'LOGNAME'] as $key) {
            $value = getenv($key);

            if (is_string($value) && trim($value) !== '') {
                return trim($value);
            }
        }

        return get_current_user();
    }
}
