<?php

declare(strict_types=1);

namespace App\Services\DatabaseConnections;

final readonly class DatabaseConnectionPayload
{
    public function __construct(
        public string $driver,
        public ?string $host,
        public ?int $port,
        public ?string $database,
        public ?string $path,
        public ?string $username,
        public ?string $password,
    ) {}

    /**
     * @param  array<string, mixed>  $payload
     */
    public static function fromArray(array $payload): self
    {
        $credentials = is_array($payload['credentials'] ?? null) ? $payload['credentials'] : [];
        $password = $payload['password'] ?? $credentials['password'] ?? null;

        return new self(
            driver: is_string($payload['driver'] ?? null) ? $payload['driver'] : '',
            host: is_string($payload['host'] ?? null) ? $payload['host'] : null,
            port: is_int($payload['port'] ?? null) ? $payload['port'] : (is_numeric($payload['port'] ?? null) ? (int) $payload['port'] : null),
            database: is_string($payload['database'] ?? null) ? $payload['database'] : null,
            path: is_string($payload['path'] ?? null) ? $payload['path'] : null,
            username: is_string($payload['username'] ?? null) ? $payload['username'] : null,
            password: is_string($password) ? $password : null,
        );
    }

    /**
     * @return array{password?: string}
     */
    public function credentials(): array
    {
        if ($this->password === null) {
            return [];
        }

        return ['password' => $this->password];
    }
}
