<?php

declare(strict_types=1);

namespace App\Services\Doctor;

use App\Data\Doctor\DoctorIssue;
use App\Models\Node;
use Throwable;

final readonly class DoctorDnsRuntimeRestorer
{
    public function __construct(
        private DnsRuntimeProbe $dnsRuntimeProbe,
    ) {}

    public function isRestorable(string $key): bool
    {
        return $this->dnsRuntimeProbe->isRestorable($key);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function apply(Node $node, DoctorIssue $issue): ?array
    {
        $key = $issue->key;
        $detail = $issue->detail;

        if (! $this->isRestorable($key)) {
            return null;
        }

        try {
            $restored = $this->dnsRuntimeProbe->restore($key);
        } catch (Throwable $exception) {
            return [
                'family' => 'tool',
                'node' => $node->name,
                'code' => $key,
                'key' => $key,
                'mode' => 'restore',
                'status' => 'failed',
                'summary' => "Failed to fix {$key}.",
                'details' => [
                    ...$detail,
                    'error' => $exception->getMessage(),
                ],
            ];
        }

        $summary = $issue->summary !== '' ? $issue->summary : "Fixed {$key}.";

        if (! $restored) {
            $summary = "Failed to fix {$key}.";
        }

        return [
            'family' => 'tool',
            'node' => $node->name,
            'code' => $key,
            'key' => $key,
            'mode' => 'restore',
            'status' => $restored ? 'completed' : 'failed',
            'summary' => $summary,
            'details' => $restored
                ? $detail
                : [
                    ...$detail,
                    'error' => 'restore_returned_false',
                ],
        ];
    }
}
