<?php

declare(strict_types=1);

namespace App\Commands\Node;

use App\Commands\GatewayCommand;
use App\Exceptions\GatewayApiException;
use App\Services\Node\AuthorizedKeysInstaller;
use App\Services\Platform\LocalPlatformDetector;
use Throwable;

final class NodeManageCommand extends GatewayCommand
{
    #[\Override]
    protected $signature = 'node:manage
        {--user= : Local SSH user the gateway should use}
        {--json : Output JSON}';

    #[\Override]
    protected $description = 'Opt this roleless node into gateway SSH management.';

    public function handle(AuthorizedKeysInstaller $authorizedKeys, LocalPlatformDetector $platform): int
    {
        try {
            $me = $this->gatewayGet('/api/me');
        } catch (GatewayApiException $exception) {
            return $this->renderGatewayFailure($exception);
        }

        if (! $this->isActiveRolelessSelf($me)) {
            return $this->renderFailure('node.not_operator', 'Only active roleless nodes can run node:manage.');
        }

        try {
            $keyResponse = $this->gatewayGet('/api/nodes/self/manage-key');
        } catch (GatewayApiException $exception) {
            return $this->renderGatewayFailure($exception);
        }

        $publicKey = $this->publicKey($keyResponse);

        if ($publicKey === null) {
            return $this->renderFailure(
                'node.management_key_unavailable',
                'Gateway management SSH public key was not returned.',
            );
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
            $authorizedKeys->install($this->homeDirectory(), $publicKey);
        } catch (Throwable $exception) {
            return $this->renderFailure('node.authorized_keys_failed', $exception->getMessage(), [
                'path' => $this->homeDirectory().'/.ssh/authorized_keys',
            ]);
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

    /**
     * @param  array<string, mixed>  $response
     */
    private function publicKey(array $response): ?string
    {
        $key =
            $response['success']['data']['management_ssh_key']['public_key'] ?? $response['success']['data']['public_key']
                ?? null;

        return is_string($key) && trim($key) !== '' ? trim($key) : null;
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

    private function homeDirectory(): string
    {
        foreach ([getenv('HOME'), $_SERVER['HOME'] ?? null, $_ENV['HOME'] ?? null] as $candidate) {
            if (is_string($candidate) && trim($candidate) !== '') {
                return rtrim(trim($candidate), '/');
            }
        }

        return (string) getcwd();
    }
}
