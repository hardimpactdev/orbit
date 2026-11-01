<?php

declare(strict_types=1);

namespace App\Infrastructure\GatewayReleases;

use App\Domain\GatewayReleases\GatewayReleaseException;
use App\Infrastructure\AgentView\WebSocketClient;
use App\Infrastructure\AgentView\WebSocketEndpoint;
use Throwable;

/** Read-only proof through the Gateway's configured token and CA. Never changes authority. */
final readonly class GatewayReleaseGrantProof
{
    public function __construct(private WebSocketClient $socket) {}

    /** @param array<string,mixed> $policy
     * @return array{principal:string,checks:int,checked_at:string,mode:string}
     */
    public function verify(array $policy): array
    {
        $address = config('swamp.address');
        $ca = config('swamp.ca_path');
        $token = config('swamp.run_token');
        if (($policy['version'] ?? null) !== 2 || ($policy['principal'] ?? null) !== 'user:orbit-gateway'
            || ! is_array($policy['workflows'] ?? null) || ! is_array($policy['vaults'] ?? null)
            || ! is_string($address) || $address === '' || ! is_string($ca) || $ca === '' || ! is_string($token) || $token === '') {
            throw $this->refused();
        }
        $deadline = microtime(true) + 60;
        try {
            $this->socket->connect(new WebSocketEndpoint($address, 443, 'swamp.orbit', '/', $ca, $token), 5);
            $inventory = $this->query([], $deadline);
            $decisions = $inventory['decisions'] ?? null;
            if (! is_array($decisions) || ! array_is_list($decisions)) {
                throw $this->refused();
            }
            $checks = 1;
            $expectedVaults = [];
            foreach ($policy['vaults'] as $vault) {
                if (! is_array($vault) || ! is_string($vault['vault'] ?? null) || ! is_string($vault['key_pattern'] ?? null)) {
                    throw $this->refused();
                }
                $condition = "key.matches('".str_replace(['\\', "'"], ['\\\\', "\\'"], $vault['key_pattern'])."')";
                foreach (['read', 'write'] as $action) {
                    $expectedVaults[json_encode([$action, 'vault:'.$vault['vault'], $condition], JSON_THROW_ON_ERROR)] = false;
                }
            }
            foreach ($decisions as $decision) {
                if (! is_array($decision) || ! is_string($decision['resource'] ?? null)) {
                    throw $this->refused();
                }
                if (! str_starts_with($decision['resource'], 'vault:')) {
                    continue;
                }
                $signature = json_encode([$decision['action'] ?? null, $decision['resource'], $decision['condition'] ?? null], JSON_THROW_ON_ERROR);
                if (($decision['effect'] ?? null) !== 'allow' || ! array_key_exists($signature, $expectedVaults)
                    || ! empty($decision['methods']) || ! empty($decision['impliedBy'])) {
                    throw $this->refused();
                }
                $expectedVaults[$signature] = true;
            }
            if (in_array(false, $expectedVaults, true)) {
                throw $this->refused();
            }
            $checks += count($expectedVaults);
            foreach ($policy['workflows'] as $workflow) {
                if (! is_array($workflow) || ! is_string($workflow['workflow'] ?? null) || ! is_array($workflow['actions'] ?? null)) {
                    throw $this->refused();
                }
                foreach (['read', 'run', 'write'] as $action) {
                    $reply = $this->query(['action' => $action, 'resource' => 'workflow:'.$workflow['workflow']], $deadline);
                    $this->decision($reply, $action, 'workflow:'.$workflow['workflow'], in_array($action, $workflow['actions'], true));
                    $checks++;
                }
            }
            foreach ([['admin', 'access:*'], ['run', 'workflow:orbit-release-unrelated-denied'],
                ['read', 'workflow:orbit-release-unrelated-denied'], ['write', 'workflow:orbit-release-unrelated-denied']] as [$action, $resource]) {
                $this->decision($this->query(['action' => $action, 'resource' => $resource], $deadline), $action, $resource, false);
                $checks++;
            }

            return ['principal' => 'user:orbit-gateway', 'checks' => $checks, 'checked_at' => now()->toIso8601String(),
                'mode' => 'configured_token_allow_deny_and_exact_vault_conditions'];
        } catch (Throwable) {
            throw $this->refused();
        } finally {
            $this->socket->close();
        }
    }

    /** @param array<string,mixed> $payload
     * @return array<string,mixed>
     */
    private function query(array $payload, float $deadline): array
    {
        $id = bin2hex(random_bytes(16));
        $this->socket->send(['type' => 'access.can-i', 'id' => $id, 'payload' => $payload === [] ? (object) [] : $payload]);
        while ($this->socket->isConnected() && ($remaining = $deadline - microtime(true)) > 0) {
            foreach ($this->socket->receive(min(0.2, $remaining)) as $message) {
                if (($message['id'] ?? null) !== $id) {
                    continue;
                }
                $data = $message['payload'] ?? null;
                if (($message['type'] ?? null) !== 'access.can-i' || ! is_array($data) || ($data['principal'] ?? null) !== 'user:orbit-gateway') {
                    throw $this->refused();
                }

                return $data;
            }
        }

        throw $this->refused();
    }

    /** @param array<string,mixed> $reply */
    private function decision(array $reply, string $action, string $resource, bool $allowed): void
    {
        $rows = $reply['decisions'] ?? null;
        if (! is_array($rows) || ! array_is_list($rows)) {
            throw $this->refused();
        }
        $allow = false;
        $deny = false;
        foreach ($rows as $row) {
            if (! is_array($row) || ($row['action'] ?? null) !== $action || ($row['resource'] ?? null) !== $resource
                || ! in_array($row['effect'] ?? null, ['allow', 'deny'], true)) {
                throw $this->refused();
            }
            $allow = $allow || $row['effect'] === 'allow';
            $deny = $deny || $row['effect'] === 'deny';
            if ($allowed && (! empty($row['condition']) || ! empty($row['methods']))) {
                throw $this->refused();
            }
        }
        if (($allow && ! $deny) !== $allowed || (! $allowed && $allow)) {
            throw $this->refused();
        }
    }

    private function refused(): GatewayReleaseException
    {
        return new GatewayReleaseException('swamp', 'gateway.release_swamp_token_unverified', 'The configured Swamp token and CA did not prove the release grants. The release remains paused.');
    }
}
