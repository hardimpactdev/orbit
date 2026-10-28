<?php

declare(strict_types=1);

namespace App\Infrastructure\Dependencies;

use App\Domain\Instances\Dependencies\DependencyReadFailure;
use App\Domain\Instances\Dependencies\SwampDependencyReader;
use App\Domain\Instances\Dependencies\SwampDependencyReadException;
use App\Infrastructure\AgentView\WebSocketClient;
use App\Infrastructure\AgentView\WebSocketEndpoint;
use App\Infrastructure\AgentView\WebSocketException;
use Closure;

final readonly class WebSocketSwampDependencyReader implements SwampDependencyReader
{
    /** @param Closure(int): WebSocketClient $socketFactory */
    public function __construct(
        private Closure $socketFactory,
        private string $address,
        #[\SensitiveParameter] private string $token,
        private string $caPath,
    ) {}

    public function read(string $dataName, ?int $version, int $maxBytes): string
    {
        if ($this->token === '' || preg_match('/[\r\n]/', $this->token.$this->address) === 1 || ! in_array($maxBytes, [65_536, 1_048_576], true)) {
            throw new SwampDependencyReadException(DependencyReadFailure::Unavailable);
        }
        $socket = ($this->socketFactory)(6 * $maxBytes + 4_096);
        $deadline = microtime(true) + 5.0;
        try {
            $socket->connect(new WebSocketEndpoint($this->address, 443, 'swamp.orbit', '/', $this->caPath, $this->token), 2.0);
            $id = bin2hex(random_bytes(8));
            $socket->send(['type' => 'data.get', 'id' => $id, 'payload' => ['modelIdOrName' => 'orbit-gateway', 'dataName' => $dataName, 'includeContent' => true, ...($version === null ? [] : ['version' => $version])]]);
            while ($socket->isConnected() && ($remaining = $deadline - microtime(true)) > 0) {
                foreach ($socket->receive(min(0.2, $remaining)) as $message) {
                    if (($message['id'] ?? null) !== $id) {
                        continue;
                    }
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
            throw new SwampDependencyReadException(DependencyReadFailure::Timeout);
        } catch (WebSocketException $exception) {
            $message = strtolower($exception->getMessage());
            $tls = str_contains($message, 'certificate') || str_contains($message, 'ssl') || str_contains($message, 'tls');
            $failure = in_array($exception->getCode(), [401, 403], true) ? DependencyReadFailure::Auth : ($tls ? DependencyReadFailure::Tls : DependencyReadFailure::Unavailable);
            throw new SwampDependencyReadException($failure);
        } finally {
            $socket->close();
        }
    }
}
