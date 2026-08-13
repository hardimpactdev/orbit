<?php

declare(strict_types=1);

namespace App\Services\Doctor;

use App\Data\Doctor\DoctorIssue;
use App\Models\Node;
use App\Services\Dns\DnsmasqReconciler;
use LogicException;
use Throwable;

final readonly class DoctorDnsProjectionRestorer
{
    public function __construct(
        private DnsmasqReconciler $dnsmasqReconciler,
        private DoctorIssueNodeResolver $issueNodeResolver,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function apply(Node $fallbackNode, DoctorIssue $issue): array
    {
        $key = $issue->key;
        $family = $key === 'node.dns_mapping_mismatch' ? 'node' : 'proxy';
        $node = $this->issueNodeResolver->resolve($issue) ?? $fallbackNode;
        $detail = $issue->detail;

        if (! DoctorDnsProjectionRestoreSupport::supports($key)) {
            return [
                'family' => $family,
                'node' => $node->name,
                'code' => $key,
                'key' => $key,
                'mode' => 'restore',
                'status' => 'skipped',
                'summary' => "No restore action is registered for {$key}.",
                'details' => [
                    ...$detail,
                    'reason' => 'mode_not_supported',
                ],
            ];
        }

        if (! $this->dnsmasqReconciler->projectionDirectoryIsMounted()) {
            return [
                'family' => $family,
                'node' => $node->name,
                'code' => $key,
                'key' => $key,
                'mode' => 'restore',
                'status' => 'failed',
                'summary' => "Failed to fix {$key}.",
                'details' => [
                    ...$detail,
                    'error' => 'The live orbit-dns runtime does not consume the managed projection directory.',
                ],
            ];
        }

        try {
            match ($key) {
                'node.dns_mapping_mismatch' => $this->dnsmasqReconciler->reconcileNodeRecords(),
                'proxy.dns_mapping_mismatch' => $this->dnsmasqReconciler->reconcileProxyRecords(),
                default => throw new LogicException("Unsupported DNS projection issue [{$key}]."),
            };
        } catch (Throwable $exception) {
            return [
                'family' => $family,
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

        return [
            'family' => $family,
            'node' => $node->name,
            'code' => $key,
            'key' => $key,
            'mode' => 'restore',
            'status' => 'completed',
            'summary' => $issue->summary !== '' ? $issue->summary : "Fixed {$key}.",
            'details' => $detail,
        ];
    }
}
