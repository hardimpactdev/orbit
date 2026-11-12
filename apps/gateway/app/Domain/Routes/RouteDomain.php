<?php

declare(strict_types=1);

namespace App\Domain\Routes;

use App\Domain\Shared\ResourceOperationException;

final class RouteDomain
{
    private const string PATTERN = '/\A[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?(?:\.[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?)*\z/D';

    public static function normalize(string $domain): string
    {
        return mb_strtolower(trim($domain));
    }

    public static function isValid(string $domain): bool
    {
        $normalized = self::normalize($domain);

        return $normalized !== '' && strlen($normalized) <= 253 && preg_match(self::PATTERN, $normalized) === 1;
    }

    public static function validate(string $domain): string
    {
        $normalized = self::normalize($domain);

        foreach (explode('.', $normalized) as $label) {
            if (strlen($label) > 63) {
                throw new ResourceOperationException(
                    errorCode: 'route.domain_invalid',
                    message: 'The name part '.$label.' is too long: '.strlen($label).' characters, at most 63. Use a shorter name part.',
                );
            }
        }

        if (strlen($normalized) > 253) {
            throw new ResourceOperationException(
                errorCode: 'route.domain_invalid',
                message: 'The address '.$normalized.' is too long: '.strlen($normalized).' characters, at most 253. Use a shorter Instance name.',
            );
        }

        if (! self::isValid($normalized)) {
            throw new ResourceOperationException(
                errorCode: 'route.domain_invalid',
                message: 'The Route domain is invalid.',
            );
        }

        return $normalized;
    }
}
