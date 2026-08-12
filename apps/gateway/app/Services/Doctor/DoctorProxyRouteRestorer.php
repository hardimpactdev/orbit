<?php

declare(strict_types=1);

namespace App\Services\Doctor;

use App\Data\Doctor\DoctorIssue;
use App\Data\Doctor\DriftEntry;
use App\Enums\DriftKind;
use App\Models\Node;
use App\Models\ProxyRoute;
use App\Services\Proxy\ProxyRouteAdopter;
use App\Services\Proxy\ProxyRouteFixer;
use App\Services\Proxy\ProxyRouteProbe;
use Throwable;

final readonly class DoctorProxyRouteRestorer
{
    public function __construct(
        private ProxyRouteProbe $proxyRouteProbe,
        private ProxyRouteFixer $proxyRouteFixer,
        private ProxyRouteAdopter $proxyRouteAdopter,
    ) {}

    /**
     * @return array<string, mixed>|null
     */
    public function applyExtra(Node $node, string $mode, string $key, DoctorIssue $issue): ?array
    {
        if ($mode === 'adopt') {
            $snapshot = $this->proxyRouteProbe->snapshotForAdopt($node);

            foreach ($this->proxyRouteAdopter->adopt($node, $snapshot) as $result) {
                if ($result->key === $key) {
                    return [
                        'family' => $result->family,
                        'node' => $node->name,
                        'code' => $result->key,
                        'key' => $result->key,
                        'mode' => 'adopt',
                        'status' => $result->action->value,
                        'summary' => $result->summary,
                        'details' => $result->detail,
                    ];
                }
            }

            return null;
        }

        if ($mode === 'verify') {
            return null;
        }

        $entry = new DriftEntry(
            family: 'proxy',
            key: $key,
            kind: DriftKind::Extra,
            summary: $issue->summary !== ''
                ? $issue->summary
                : "Proxy route '{$key}' exists on node but not in gateway registry.",
        );

        try {
            return $this->proxyRouteFixer->removeExtra($node, $entry->key);
        } catch (Throwable $throwable) {
            return [
                'family' => $entry->family,
                'node' => $node->name,
                'code' => $entry->key,
                'key' => $entry->key,
                'mode' => $mode,
                'status' => 'failed',
                'summary' => "Failed to remove extra proxy route {$entry->key}.",
                'details' => [
                    'error' => $throwable->getMessage(),
                ],
            ];
        }
    }

    /**
     * @param  array<string, mixed>  $detail
     * @return array<string, mixed>|null
     */
    public function applyStored(string $mode, array $detail, DriftEntry $entry): ?array
    {
        $domain = is_string($detail['domain'] ?? null) ? $detail['domain'] : null;

        if ($domain === null) {
            return null;
        }

        $route = ProxyRoute::query()
            ->where('domain', $domain)
            ->first();

        if (! $route instanceof ProxyRoute || $mode === 'verify') {
            return null;
        }

        try {
            return $this->proxyRouteFixer->fix($route, $entry);
        } catch (Throwable $throwable) {
            $route->loadMissing('node');

            return [
                'family' => $entry->family,
                'node' => $route->node->name,
                'code' => $entry->key,
                'key' => $entry->key,
                'mode' => $mode,
                'status' => 'failed',
                'summary' => "Failed to fix {$entry->key}.",
                'details' => [
                    'error' => $throwable->getMessage(),
                ],
            ];
        }
    }
}
