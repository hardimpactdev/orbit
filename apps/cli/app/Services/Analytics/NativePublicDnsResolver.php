<?php

declare(strict_types=1);

namespace App\Services\Analytics;

final readonly class NativePublicDnsResolver implements PublicDnsResolver
{
    public function resolve(string $host): array
    {
        $records = dns_get_record($host, DNS_A | DNS_AAAA);

        if (! is_array($records)) {
            return [];
        }

        $answers = [];

        foreach ($records as $record) {
            if (! is_array($record)) {
                continue;
            }

            $type = $record['type'] ?? null;
            $value = match ($type) {
                'A' => $record['ip'] ?? null,
                'AAAA' => $record['ipv6'] ?? null,
                default => null,
            };

            if (! is_string($type) || ! is_string($value)) {
                continue;
            }

            $normalized = $this->normalizeIp($value);

            if ($normalized === null) {
                continue;
            }

            $answer = ['type' => $type, 'value' => $normalized];

            if (! in_array($answer, $answers, strict: true)) {
                $answers[] = $answer;
            }
        }

        usort(
            $answers,
            static fn (array $left, array $right): int => (
                [$left['type'], $left['value']] <=> [$right['type'], $right['value']]
            ),
        );

        return $answers;
    }

    private function normalizeIp(string $value): ?string
    {
        $packed = inet_pton($value);

        if ($packed === false) {
            return null;
        }

        $normalized = inet_ntop($packed);

        return is_string($normalized) ? $normalized : null;
    }
}
