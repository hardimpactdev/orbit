<?php

declare(strict_types=1);

namespace App\Infrastructure\GatewayReleases;

use App\Domain\GatewayReleases\GatewayReleaseException;
use App\Domain\GatewayReleases\GatewayReleaseLayout;
use App\Domain\GitHub\GitHubRepository;
use Illuminate\Support\Facades\Http;
use Throwable;

/** Selects the published delivery; native commands verify and install it in the target checkout. */
final readonly class GatewayReleaseAgentDelivery
{
    public function __construct(private GatewayReleaseLayout $layout, private GatewayReleaseCommands $commands) {}

    public function install(string $sha, string $stepLock): void
    {
        $source = $this->commands->inspectAgentDelivery($this->layout, $sha);
        if ($source['installed']) {
            return;
        }
        $repository = GitHubRepository::fromOrigin($this->commands->origin($this->layout));
        if ($repository === null) {
            throw $this->unavailable($sha);
        }
        $base = "https://github.com/{$repository->owner}/{$repository->name}/releases/download/agent-v{$source['commit']}";
        try {
            $response = Http::timeout(30)->connectTimeout(10)->get($base.'/manifest.json');
            $body = $response->body();
            $manifest = $response->successful() && strlen($body) <= 65536 ? json_decode($body, true, flags: JSON_THROW_ON_ERROR) : null;
            if (! is_array($manifest) || ($manifest['commit'] ?? null) !== $source['commit'] || ($manifest['version'] ?? null) !== $source['version'] || ! is_array($manifest['artifacts'] ?? null)) {
                throw $this->unavailable($sha);
            }
            foreach ($manifest['artifacts'] as $platform => $artifact) {
                if (! is_array($artifact) || ($artifact['url'] ?? null) !== $base.'/orbit-agent-'.$platform) {
                    throw $this->unavailable($sha);
                }
            }
        } catch (Throwable) {
            throw $this->unavailable($sha);
        }
        $this->commands->installAgentDelivery($this->layout, $sha, $stepLock, $manifest);
    }

    private function unavailable(string $sha): GatewayReleaseException
    {
        return new GatewayReleaseException('agent-delivery', 'gateway.release_agent_delivery_unavailable', 'The release needs its matching published agent manifest before it can be prepared.', sha: $sha);
    }
}
