<?php

declare(strict_types=1);

namespace App\Services\Cloudflare;

use App\Models\Project;
use Orbit\Sdk\Laravel\GatewayApiException;

final readonly class CloudflareZoneResolver
{
    public function __construct(
        private CloudflareClient $client,
    ) {}

    /**
     * @return array{id: string, name: string, status: string}
     */
    public function resolveZone(string $identifier): array
    {
        $identifier = trim($identifier);

        if ($identifier === '') {
            throw new GatewayApiException(
                message: 'A Cloudflare zone is required.',
                errorCode: 'validation_failed',
                errorMeta: ['field' => 'zone'],
            );
        }

        if ($this->looksLikeZoneId($identifier)) {
            return ['id' => $identifier, 'name' => $identifier, 'status' => 'unknown'];
        }

        foreach ($this->client->zones() as $zone) {
            if ($zone['name'] === $identifier) {
                return $zone;
            }
        }

        throw new GatewayApiException(
            message: 'The selected Cloudflare zone was not found.',
            errorCode: 'validation_failed',
            errorMeta: ['field' => 'zone', 'zone' => $identifier],
        );
    }

    /**
     * @return array{id: string, name: string, status: string}
     */
    public function resolveZoneForRecordName(string $recordName): array
    {
        $recordName = trim($recordName);

        if ($recordName === '') {
            throw new GatewayApiException(
                message: 'A DNS record name is required.',
                errorCode: 'validation_failed',
                errorMeta: ['field' => 'name'],
            );
        }

        $zones = collect($this->client->zones())
            ->sortByDesc(fn (array $zone): int => strlen($zone['name']))
            ->values();

        foreach ($zones as $zone) {
            if ($recordName === $zone['name'] || str_ends_with($recordName, ".{$zone['name']}")) {
                return $zone;
            }
        }

        throw new GatewayApiException(
            message: 'The DNS record name does not belong to a visible Cloudflare zone.',
            errorCode: 'validation_failed',
            errorMeta: ['field' => 'name', 'name' => $recordName],
        );
    }

    /**
     * @return array{project: Project, zone: array{id: string, name: string, status: string}}
     */
    public function resolveProjectZone(string $projectName): array
    {
        $projectName = trim($projectName);

        if ($projectName === '') {
            throw new GatewayApiException(
                message: 'A project name is required.',
                errorCode: 'validation_failed',
                errorMeta: ['field' => 'project'],
            );
        }

        $project = Project::query()->where('name', $projectName)->first();

        if (! $project instanceof Project) {
            throw new GatewayApiException(
                message: 'The selected project was not found.',
                errorCode: 'validation_failed',
                errorMeta: ['field' => 'project', 'project' => $projectName],
            );
        }

        $domain = is_string($project->domain) ? trim($project->domain) : '';

        if ($domain === '' || ! str_contains($domain, '.')) {
            throw new GatewayApiException(
                message: 'The project has no Cloudflare-backed domain.',
                errorCode: 'validation_failed',
                errorMeta: ['field' => 'project', 'project' => $projectName],
            );
        }

        return [
            'project' => $project,
            'zone' => $this->resolveZoneForRecordName($domain),
        ];
    }

    /**
     * @return array{zone: array{id: string, name: string, status: string}, project: Project|null}
     */
    public function resolveZoneOrProject(string $identifier): array
    {
        $project = Project::query()->where('name', $identifier)->first();

        if ($project instanceof Project) {
            $resolved = $this->resolveProjectZone($identifier);

            return [
                'zone' => $resolved['zone'],
                'project' => $resolved['project'],
            ];
        }

        return [
            'zone' => $this->resolveZone($identifier),
            'project' => null,
        ];
    }

    private function looksLikeZoneId(string $identifier): bool
    {
        return preg_match('/^[0-9a-f]{32}$/', $identifier) === 1;
    }
}
