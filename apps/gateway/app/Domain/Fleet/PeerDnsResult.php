<?php

declare(strict_types=1);

namespace App\Domain\Fleet;

/** The persisted and live resolver settings must describe the same policy. */
final readonly class PeerDnsResult
{
    /** @param list<string> $domains
     * @param  array<string, mixed>  $result
     */
    public static function matches(string $server, array $domains, array $result): bool
    {
        foreach (['state', 'dns', 'domains', 'hooks', 'default_route'] as $field) {
            if (! is_string($result[$field] ?? null)) {
                return false;
            }
        }
        $live = explode(' ', self::setting($result['domains']) ?? '');
        sort($live, SORT_STRING);
        $expected = array_map(static fn (string $domain): string => '~'.$domain, $domains);
        sort($expected, SORT_STRING);
        $hook = 'PostUp = resolvectl dns %i '.$server.'; resolvectl domain %i '.implode(' ', array_map(static fn (string $domain): string => '\\~'.$domain, $domains));

        return $result['state'] === implode("\n", ['orbit', $server, ...$domains])
            && self::setting($result['dns']) === $server && $live === $expected
            && $result['hooks'] === $hook && self::setting($result['default_route']) === 'no';
    }

    private static function setting(string $output): ?string
    {
        return str_contains($output, ':') ? trim(explode(':', $output, 2)[1]) : null;
    }
}
