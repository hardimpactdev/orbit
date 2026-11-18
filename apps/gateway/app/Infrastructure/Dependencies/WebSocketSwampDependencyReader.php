<?php

declare(strict_types=1);

namespace App\Infrastructure\Dependencies;

use App\Domain\Instances\Dependencies\DependencyReadFailure;
use App\Domain\Instances\Dependencies\SwampDependencyReader;
use App\Domain\Instances\Dependencies\SwampDependencyReadException;
use App\Infrastructure\AgentView\WebSocketClient;
use App\Infrastructure\AgentView\WebSocketEndpoint;
use App\Infrastructure\AgentView\WebSocketException;
use App\Infrastructure\Swamp\SwampCaller;
use App\Infrastructure\Swamp\SwampHandshakes;
use Closure;

/**
 * Reads dependency resources from the `orbit-gateway` model. Every read of one reader, which lives for one request, uses one serve
 * connection: a screen read is a view, so it connects only while the serve window leaves room (SwampHandshakes).
 */
final class WebSocketSwampDependencyReader implements SwampDependencyReader
{
    private const int MaxBytes = 1_048_576;

    private ?WebSocketClient $socket = null;

    /** @var Closure(): float */
    private readonly Closure $clock;

    /**
     * @param  Closure(int): WebSocketClient  $socketFactory
     * @param  (Closure(): float)|null  $clock
     */
    public function __construct(
        private readonly Closure $socketFactory,
        private readonly string $address,
        #[\SensitiveParameter] private readonly string $token,
        private readonly string $caPath,
        ?Closure $clock = null,
    ) {
        $this->clock = $clock ?? static fn (): float => microtime(true);
    }

    public function read(string $dataName, ?int $version, int $maxBytes): string
    {
        if ($this->token === '' || preg_match('/[\r\n]/', $this->token.$this->address) === 1 || ! in_array($maxBytes, [65_536, self::MaxBytes], true)) {
            throw new SwampDependencyReadException(DependencyReadFailure::Unavailable);
        }
        $deadline = ($this->clock)() + 5.0;
        try {
            $socket = $this->connected();
            $id = $this->request($socket, $dataName, $version);
            while ($socket->isConnected() && ($remaining = $deadline - ($this->clock)()) > 0) {
                foreach ($socket->receive(min(0.2, $remaining)) as $message) {
                    if (($message['id'] ?? null) === $id) {
                        return $this->content($message, $dataName, $version, $maxBytes);
                    }
                }
            }
            throw new SwampDependencyReadException(DependencyReadFailure::Timeout);
        } catch (WebSocketException $exception) {
            $this->close();
            $message = strtolower($exception->getMessage());
            $tls = str_contains($message, 'certificate') || str_contains($message, 'ssl') || str_contains($message, 'tls');
            $failure = in_array($exception->getCode(), [401, 403], true) ? DependencyReadFailure::Auth : ($tls ? DependencyReadFailure::Tls : DependencyReadFailure::Unavailable);
            throw new SwampDependencyReadException($failure);
        } catch (SwampDependencyReadException $exception) {
            // A late answer would be matched to nothing; an answered refusal keeps the connection for the next read.
            if ($exception->failure === DependencyReadFailure::Timeout) {
                $this->close();
            }
            throw $exception;
        }
    }

    public function readLatest(array $dataNames, int $maxBytes): array
    {
        if ($dataNames === [] || $this->socket === null || ! $this->socket->isConnected()) {
            return [];
        }
        $socket = $this->socket;
        $deadline = ($this->clock)() + 5.0;
        $pending = [];
        $contents = [];
        try {
            foreach (array_values(array_unique($dataNames)) as $dataName) {
                $pending[$this->request($socket, $dataName, null)] = $dataName;
            }
            while ($pending !== [] && $socket->isConnected() && ($remaining = $deadline - ($this->clock)()) > 0) {
                foreach ($socket->receive(min(0.2, $remaining)) as $message) {
                    $id = $message['id'] ?? null;
                    if (! is_string($id) || ! isset($pending[$id])) {
                        continue;
                    }
                    $dataName = $pending[$id];
                    unset($pending[$id]);
                    try {
                        $contents[$dataName] = $this->content($message, $dataName, null, $maxBytes);
                    } catch (SwampDependencyReadException) {
                        // Serve answered without a valid index.
                        $contents[$dataName] = null;
                    }
                }
            }
        } catch (WebSocketException) {
            $this->close();
        }

        return $contents;
    }

    public function close(): void
    {
        $this->socket?->close();
        $this->socket = null;
    }

    public function __destruct()
    {
        $this->close();
    }

    private function connected(): WebSocketClient
    {
        if ($this->socket !== null && $this->socket->isConnected()) {
            return $this->socket;
        }
        $socket = ($this->socketFactory)(6 * self::MaxBytes + 4_096);
        app(SwampHandshakes::class)->connect($socket, new WebSocketEndpoint($this->address, 443, 'swamp.orbit', '/', $this->caPath, $this->token), 2.0, SwampCaller::Dependencies);

        return $this->socket = $socket;
    }

    private function request(WebSocketClient $socket, string $dataName, ?int $version): string
    {
        $id = bin2hex(random_bytes(8));
        $socket->send(['type' => 'data.get', 'id' => $id, 'payload' => ['modelIdOrName' => 'orbit-gateway', 'dataName' => $dataName, 'includeContent' => true, ...($version === null ? [] : ['version' => $version])]]);

        return $id;
    }

    /** @param array<string, mixed> $message */
    private function content(array $message, string $dataName, ?int $version, int $maxBytes): string
    {
        $payload = $message['payload'] ?? null;
        $data = is_array($payload) ? ($payload['data'] ?? null) : null;
        if (($message['type'] ?? null) !== 'data.get' || ! is_array($data)) {
            throw new SwampDependencyReadException(DependencyReadFailure::Invalid);
        }
        $content = $data['content'] ?? null;
        if (($data['modelName'] ?? null) !== 'orbit-gateway' || ($data['name'] ?? null) !== $dataName
            || ! is_int($data['version'] ?? null) || $data['version'] < 1 || ($version !== null && $data['version'] !== $version)
            || ($data['contentType'] ?? null) !== 'application/json' || ($data['contentEncoding'] ?? null) !== 'utf-8'
            || ! is_string($content) || strlen($content) > $maxBytes || ($data['size'] ?? null) !== strlen($content)
            || ($data['checksum'] ?? null) !== hash('sha256', $content)) {
            throw new SwampDependencyReadException(DependencyReadFailure::Invalid);
        }

        return $content;
    }
}
