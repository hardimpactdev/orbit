<?php

declare(strict_types=1);

namespace App\Services\Nodes;

use App\Models\Node;
use Illuminate\Support\Facades\File;

class DevelopmentDnsMappingEnactor
{
    public function __construct(private readonly ?string $configDir = null) {}

    /**
     * @return array{
     *     status: string,
     *     changed: bool,
     *     domain?: string,
     *     target?: string,
     *     path?: string,
     * }
     */
    public function converge(Node $node): array
    {
        $mapping = $this->mappingFor($node);

        if ($mapping === null) {
            return [
                'status' => 'not_applicable',
                'changed' => false,
            ];
        }

        File::ensureDirectoryExists($this->configDir());

        $path = $this->configPath($mapping['tld']);
        $content = $this->content($mapping);

        if (File::exists($path) && File::get($path) === $content) {
            return [
                'status' => 'already_configured',
                'changed' => false,
                'domain' => $mapping['domain'],
                'target' => $mapping['target'],
                'path' => $path,
            ];
        }

        File::put($path, $content);

        return [
            'status' => 'configured',
            'changed' => true,
            'domain' => $mapping['domain'],
            'target' => $mapping['target'],
            'path' => $path,
        ];
    }

    /**
     * @return array{
     *     status: string,
     *     changed: bool,
     *     domain?: string,
     *     target?: string,
     *     path?: string,
     * }
     */
    public function remove(Node $node): array
    {
        $mapping = $this->mappingFor($node);

        if ($mapping === null) {
            return [
                'status' => 'not_applicable',
                'changed' => false,
            ];
        }

        $path = $this->configPath($mapping['tld']);

        if (! File::exists($path)) {
            return [
                'status' => 'already_absent',
                'changed' => false,
                'domain' => $mapping['domain'],
                'target' => $mapping['target'],
                'path' => $path,
            ];
        }

        File::delete($path);

        return [
            'status' => 'removed',
            'changed' => true,
            'domain' => $mapping['domain'],
            'target' => $mapping['target'],
            'path' => $path,
        ];
    }

    /**
     * @return array{
     *     node: string,
     *     tld: string,
     *     domain: string,
     *     target: string,
     * }|null
     */
    public function mappingFor(Node $node): ?array
    {
        if ($node->role !== 'app' || $node->environment !== 'development' || $node->status !== 'active') {
            return null;
        }

        if (! is_string($node->tld) || trim($node->tld) === '') {
            return null;
        }

        if (! is_string($node->wireguard_address) || trim($node->wireguard_address) === '') {
            return null;
        }

        return [
            'node' => (string) $node->name,
            'tld' => trim($node->tld),
            'domain' => '*.'.$node->tld,
            'target' => trim($node->wireguard_address),
        ];
    }

    public function configDir(): string
    {
        return $this->configDir ?? storage_path('app/orbit/node-development-dns.d');
    }

    /**
     * @param  array{node: string, tld: string, domain: string, target: string}  $mapping
     */
    private function content(array $mapping): string
    {
        return implode("\n", [
            '# orbit-managed=node-development-dns',
            "# node={$mapping['node']}",
            '# bind-scope=orbit_network',
            "address=/.{$mapping['tld']}/{$mapping['target']}",
            '',
        ]);
    }

    private function configPath(string $tld): string
    {
        return $this->configDir()."/{$tld}.conf";
    }
}
