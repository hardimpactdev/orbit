<?php

declare(strict_types=1);

namespace App\Domain\SwampCredentials;

use App\Domain\Tasks\SwampReaderCredential;
use Carbon\CarbonImmutable;
use RuntimeException;
use Throwable;

/** A freshly minted Gateway token, read from the Swamp Node entrypoint's reveal. Its secret never reaches a message. */
final readonly class SwampGatewayCredential
{
    private function __construct(
        public SwampGatewayToken $kind,
        public string $name,
        public CarbonImmutable $expiresAt,
        #[\SensitiveParameter] private string $token,
    ) {}

    public static function fromReveal(SwampGatewayToken $kind, #[\SensitiveParameter] string $json): self
    {
        try {
            $reveal = json_decode($json, true, 8, JSON_THROW_ON_ERROR);
            $expiry = is_array($reveal) && is_string($reveal['expiresAt'] ?? null) ? SwampReaderCredential::expiry($reveal['expiresAt']) : null;
            if (strlen($json) > SwampReaderCredential::InputLimit || ! is_array($reveal)
                || ! is_string($reveal['name'] ?? null)
                || preg_match('/\A'.preg_quote($kind->base(), '/').'-\d{8}(?:-\d+)?\z/', $reveal['name']) !== 1
                || ! is_string($reveal['token'] ?? null) || preg_match('/\A[A-Za-z0-9._~-]{16,4096}\z/', $reveal['token']) !== 1
                || ! str_starts_with($reveal['token'], $reveal['name'].'.')
                || ($reveal['expired'] ?? null) !== false || $expiry === null
                || $expiry->lessThanOrEqualTo(now()->addDays(SwampGatewayToken::RenewDays))) {
                throw new RuntimeException;
            }

            return new self($kind, $reveal['name'], $expiry, $reveal['token']);
        } catch (Throwable) {
            throw new RuntimeException("Expected a non-expired {$kind->base()} reveal that expires more than ten days from now.");
        }
    }

    public function token(): string
    {
        return $this->token;
    }
}
