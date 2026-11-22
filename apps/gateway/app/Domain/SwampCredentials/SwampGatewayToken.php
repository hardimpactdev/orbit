<?php

declare(strict_types=1);

namespace App\Domain\SwampCredentials;

/**
 * The two Swamp access tokens the Gateway holds in its shared env. Each generation is `<base>-<YYYYMMDD>[-<n>]` of its UTC renewal
 * day; the plain base name is the legacy first generation. The Swamp Node's entrypoint fixes each base's principal, so the read
 * token stays read-only. The generation in use is the name before the first `.` of the env value.
 */
enum SwampGatewayToken: string
{
    case Read = 'read';
    case Run = 'run';

    /** Renew a token that expires within this many days. */
    public const int RenewDays = 10;

    /** A release fails while a live consumer's token expires within this many days. */
    public const int ReleaseDays = 3;

    public function base(): string
    {
        return 'orbit-gateway-'.$this->value;
    }

    public function principal(): string
    {
        return $this === self::Read ? 'user:orbit-gateway-read' : 'user:orbit-gateway';
    }

    public function key(): string
    {
        return 'ORBIT_SWAMP_'.strtoupper($this->value).'_TOKEN';
    }

    /** @return array{0:string,1:string,2:string} The token, its name and its expiry settings. */
    public function keys(): array
    {
        return [$this->key(), $this->key().'_NAME', $this->key().'_EXPIRES_AT'];
    }

    /**
     * The generation family a token name belongs to: any `<base>` or `<base>-<suffix>` for the two Gateway families, and otherwise
     * the name without a `-YYYYMMDD[-<n>]` or legacy `-YYYYMMDDTHHMMSSZ` suffix.
     */
    public static function family(string $name): string
    {
        foreach (self::cases() as $case) {
            $base = $case->base();
            if ($name === $base || str_starts_with($name, $base.'-')) {
                return $base;
            }
        }

        return (string) preg_replace('/-\d{8}(?:T\d{6}Z)?(?:-\d+)?\z/', '', $name);
    }

    /** The token name of `<name>.<secret>`; returns empty on an undelimited or malformed value so a secret never leaks. */
    public static function held(#[\SensitiveParameter] string $token): string
    {
        $trimmed = trim($token);
        $dot = strpos($trimmed, '.');
        if ($dot === false || $dot === 0 || $dot === strlen($trimmed) - 1) {
            return '';
        }
        $name = substr($trimmed, 0, $dot);

        return preg_match('/\A[A-Za-z0-9][A-Za-z0-9_-]*\z/', $name) === 1 ? $name : '';
    }
}
