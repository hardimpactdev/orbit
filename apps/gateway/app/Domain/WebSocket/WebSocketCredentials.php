<?php

declare(strict_types=1);

namespace App\Domain\WebSocket;

use SensitiveParameter;

/**
 * One Reverb app's identity: an app id, a public key, and a secret. For the Gateway's own app, read from
 * an active assignment, it also names the Reverb servers that hold clients.
 */
final readonly class WebSocketCredentials
{
    public function __construct(
        public string $appId,
        public string $appKey,
        #[SensitiveParameter]
        public string $appSecret,
        /** WireGuard address of the node that serves Reverb, when read from an active assignment. */
        public ?string $servingAddress = null,
        /**
         * Every address whose Reverb holds clients, the serving address first. During a `websocket` move it
         * also names the old Node until that Node withdraws, so the Gateway publishes to and listens on both.
         *
         * @var list<string>
         */
        public array $servingAddresses = [],
    ) {}

    /** @return list<string> */
    public function addresses(): array
    {
        if ($this->servingAddresses !== []) {
            return $this->servingAddresses;
        }

        return $this->servingAddress === null || $this->servingAddress === '' ? [] : [$this->servingAddress];
    }

    public function __debugInfo(): array
    {
        return [
            'appId' => $this->appId,
            'appKey' => $this->appKey,
            'appSecret' => '[PROTECTED]',
        ];
    }
}
