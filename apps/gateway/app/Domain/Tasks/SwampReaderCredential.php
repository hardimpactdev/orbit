<?php

declare(strict_types=1);

namespace App\Domain\Tasks;

use Carbon\CarbonImmutable;
use RuntimeException;
use Throwable;

final readonly class SwampReaderCredential
{
    public const int InputLimit = 16_384;

    private function __construct(
        public string $name,
        public CarbonImmutable $expiresAt,
        #[\SensitiveParameter] private string $token,
    ) {}

    public static function fromReveal(#[\SensitiveParameter] string $json, string $expiresAt): self
    {
        try {
            $reveal = json_decode($json, true, 8, JSON_THROW_ON_ERROR);
            $expiry = self::expiry($expiresAt);
            if (strlen($json) > self::InputLimit || ! is_array($reveal)
                || ! is_string($reveal['name'] ?? null) || preg_match('/\Aorbit-gateway-read-[a-zA-Z0-9_-]{1,100}\z/', $reveal['name']) !== 1
                || ! is_string($reveal['token'] ?? null) || preg_match('/\A[A-Za-z0-9._~-]{16,4096}\z/', $reveal['token']) !== 1
                || ($reveal['expired'] ?? null) !== false || $expiry === null || $expiry->lessThanOrEqualTo(now()->addDays(7))) {
                throw new RuntimeException;
            }

            return new self($reveal['name'], $expiry, $reveal['token']);
        } catch (Throwable) {
            throw new RuntimeException('Expected a non-expired Swamp reader reveal and a UTC expiry more than seven days away.');
        }
    }

    public static function expiry(string $value): ?CarbonImmutable
    {
        if (preg_match('/\A\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d{1,6})?Z\z/', $value) !== 1) {
            return null;
        }
        try {
            $date = CarbonImmutable::parse($value);

            return $date->format('Y-m-d\TH:i:s') === substr($value, 0, 19) ? $date : null;
        } catch (Throwable) {
            return null;
        }
    }

    public function token(): string
    {
        return $this->token;
    }
}
