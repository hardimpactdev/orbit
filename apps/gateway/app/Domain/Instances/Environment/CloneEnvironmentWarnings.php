<?php

declare(strict_types=1);

namespace App\Domain\Instances\Environment;

use App\Models\Node;

final readonly class CloneEnvironmentWarnings
{
    /**
     * @param  array<string, string>  $values
     * @return list<string>
     */
    public function detect(#[\SensitiveParameter] array $values, Node $candidateNode, string $productionHome, ?Node $targetNode = null): array
    {
        $warnings = [];
        ksort($values);
        foreach ($values as $key => $value) {
            if (str_starts_with($key, 'DB_')) {
                continue;
            }

            $reason = $this->reason($key, $value, $candidateNode, $productionHome, $targetNode);
            if ($reason !== null) {
                $warnings[] = "{$key}: {$reason} Review with env:update, then env:sync.";
            }
        }

        return $warnings;
    }

    private function reason(string $key, #[\SensitiveParameter] string $value, Node $node, string $home, ?Node $targetNode): ?string
    {
        if ($key !== 'SESSION_PATH' && ! str_ends_with($key, '_URL') && str_starts_with($value, '/') && ! str_starts_with($value, '//')) {
            $path = $this->normalizePath($value);
            $home = rtrim($this->normalizePath($home), '/');
            if ($path !== $home && ! str_starts_with($path, $home.'/')) {
                return 'Copied absolute path is outside the production home.';
            }
        }

        $host = parse_url(str_contains($value, '://') ? $value : '//'.$value, PHP_URL_HOST);
        if (is_string($host)) {
            $host = strtolower(trim(trim($host, '[]'), '.'));
            $hosts = array_filter([$node->public_ssh_host, $node->wireguard_ip, $node->lan_ip]);
            $tld = strtolower(trim($node->tld ?? '', '.'));
            $targetTld = strtolower(trim($targetNode->tld ?? '', '.'));
            $underTargetTld = $targetTld !== '' && ($host === $targetTld || str_ends_with($host, '.'.$targetTld));
            if (in_array($host, array_map(strtolower(...), $hosts), true)
                || (! $underTargetTld && $tld !== '' && ($host === $tld || str_ends_with($host, '.'.$tld)))) {
                return 'Copied host points at the candidate Node or its development TLD.';
            }
        }

        $review = match ($key) {
            'QUEUE_CONNECTION' => in_array($value, ['database', 'sync', 'null'], true),
            'CACHE_DRIVER', 'CACHE_STORE', 'SESSION_DRIVER' => $value === 'array',
            'MAIL_MAILER', 'MAIL_DRIVER' => in_array($value, ['log', 'array'], true),
            default => false,
        };

        return $review ? 'Copied service driver needs production review; it was not changed.' : null;
    }

    private function normalizePath(#[\SensitiveParameter] string $path): string
    {
        $parts = [];
        foreach (explode('/', $path) as $part) {
            if ($part === '..') {
                array_pop($parts);
            } elseif ($part !== '' && $part !== '.') {
                $parts[] = $part;
            }
        }

        return '/'.implode('/', $parts);
    }
}
