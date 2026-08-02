<?php

declare(strict_types=1);

namespace App\Services\Tools;

use InvalidArgumentException;

final readonly class LocalToolRunScriptPayload
{
    public const string FIXED_CWD = '/tmp';

    private const int DEFAULT_TIMEOUT = 900;

    private const int MAX_TIMEOUT = 3600;

    private const string TOOL_PATTERN = '/\A[a-z][a-z0-9-]*\z/';

    /**
     * @var list<string>
     */
    private const array ALLOWED_ACTIONS = [
        'install',
        'update',
        'remove',
        'probe',
        'probe-images',
        'probe-many',
        'probe-php-cli',
        'reconfigure',
        'start',
        'stop',
        'restart',
        'logs',
        'credentials',
    ];

    private function __construct(
        public string $tool,
        public string $action,
        public string $script,
        public int $timeout,
    ) {}

    /**
     * @param  array<string, mixed>  $payload
     */
    public static function fromArray(array $payload): self
    {
        $tool = $payload['tool'] ?? null;

        if (! is_string($tool) || preg_match(self::TOOL_PATTERN, $tool) !== 1) {
            throw new InvalidArgumentException('Tool run payload tool is invalid.');
        }

        $action = $payload['action'] ?? null;

        if (! is_string($action) || ! in_array($action, self::ALLOWED_ACTIONS, true)) {
            throw new InvalidArgumentException('Tool run payload action is invalid.');
        }

        $script = $payload['script'] ?? null;

        if (! is_string($script) || trim($script) === '') {
            throw new InvalidArgumentException('Tool run payload script is invalid.');
        }

        return new self(
            tool: $tool,
            action: $action,
            script: $script,
            timeout: self::timeoutFrom($payload['timeout'] ?? null),
        );
    }

    private static function timeoutFrom(mixed $value): int
    {
        if ($value === null) {
            return self::DEFAULT_TIMEOUT;
        }

        if (! is_int($value) || $value < 1) {
            throw new InvalidArgumentException('Tool run payload timeout is invalid.');
        }

        return min($value, self::MAX_TIMEOUT);
    }
}
