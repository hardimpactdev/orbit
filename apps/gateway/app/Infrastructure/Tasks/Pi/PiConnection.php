<?php

declare(strict_types=1);

namespace App\Infrastructure\Tasks\Pi;

use App\Domain\Tasks\AgentDriverException;
use App\Infrastructure\Shared\StoredValue;
use App\Models\Node;
use App\Models\TaskVm;
use SensitiveParameter;

/**
 * Resolves a Node's Pi server address and token. A task VM Node uses its own token, and a warm one has none. Otherwise Node
 * settings under `pi` take precedence; a Node with `pi` settings never falls back to the Gateway-wide token.
 */
final readonly class PiConnection
{
    public function baseUrl(Node $node): string
    {
        $url = $this->settings($node)['url'];
        if ($url !== null) {
            return rtrim($url, '/');
        }
        $host = $node->wireguard_ip;
        if (! is_string($host) || filter_var($host, FILTER_VALIDATE_IP) === false) {
            throw new AgentDriverException('The agent Node address is unavailable.');
        }
        $host = str_contains($host, ':') ? '['.$host.']' : $host;

        return 'http://'.$host.':'.$this->port();
    }

    /** The port of every managed Pi server, `ORBIT_PI_PORT`. */
    public function port(): int
    {
        $port = StoredValue::integer(config('orbit.pi.port', 3774), 3774);

        return $port > 0 && $port <= 65535 ? $port : 3774;
    }

    public function token(Node $node): string
    {
        $vm = TaskVm::forNode($node);
        if ($vm instanceof TaskVm) {
            // A warm VM has no token until a claim: it never falls back to Node settings or the Gateway-wide token.
            return $vm->pi_token ?? throw new AgentDriverException('The task VM has no Pi token, because no task group claimed it yet.');
        }

        return $this->settings($node)['token'] ?? throw new AgentDriverException('The Node has no Pi server token configured.');
    }

    /**
     * Values that never leave the driver in a transcript: the token, and a task VM's model key.
     *
     * @return list<string>
     */
    public function secrets(Node $node): array
    {
        $vm = TaskVm::forNode($node);
        if ($vm instanceof TaskVm) {
            return array_values(array_filter([$vm->pi_token ?? '', $vm->model_key ?? ''], static fn (string $value): bool => $value !== ''));
        }

        return [$this->token($node)];
    }

    /** @return array{token: string|null, url: string|null} */
    private function settings(Node $node): array
    {
        $settings = $node->settings;
        if (! is_array($settings) || ! array_key_exists('pi', $settings)) {
            return ['token' => $this->string(config('orbit.pi.token')), 'url' => null];
        }
        $pi = $settings['pi'];
        if (! is_array($pi)) {
            return ['token' => null, 'url' => null];
        }

        return ['token' => $this->string($pi['token'] ?? null), 'url' => $this->string($pi['url'] ?? null)];
    }

    private function string(#[SensitiveParameter] mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
    }
}
