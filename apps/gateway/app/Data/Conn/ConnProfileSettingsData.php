<?php

declare(strict_types=1);

namespace App\Data\Conn;

use App\Models\ConnProfile;
use App\Models\Node;
use Spatie\LaravelData\Attributes\MapOutputName;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\Mappers\SnakeCaseMapper;
use stdClass;

/**
 * The profile's settings document as Conn wrote it; its own keys keep Conn's camelCase. `sections` says
 * which Node last wrote each section and when. A section that is not listed, including one written
 * before sections had versions, is at version 0.
 */
#[MapOutputName(SnakeCaseMapper::class)]
final class ConnProfileSettingsData extends Data
{
    /**
     * @param  array<string, mixed>  $settings
     * @param  array<string, array{version: int, updated_at: string, updated_by: string|null}>  $sections
     */
    public function __construct(
        public int $profileId,
        public int $version,
        public array $settings,
        public array $sections,
        public string $updatedAt,
    ) {}

    public static function fromModel(ConnProfile $profile): self
    {
        $meta = $profile->section_meta ?? [];
        $ids = array_values(array_filter(array_column($meta, 'updated_by_node_id'), is_int(...)));
        $names = [];

        foreach (Node::query()->whereIn('id', $ids)->get(['id', 'name']) as $node) {
            $names[$node->id] = $node->name;
        }

        return new self(
            profileId: $profile->id,
            version: $profile->settings_version,
            settings: $profile->settings,
            sections: array_map(static fn (array $section): array => [
                'version' => $section['version'],
                'updated_at' => $section['updated_at'],
                'updated_by' => $section['updated_by_node_id'] === null ? null : ($names[$section['updated_by_node_id']] ?? null),
            ], $meta),
            updatedAt: $profile->updated_at->toIso8601String(),
        );
    }

    /** @return array<string, mixed> */
    #[\Override]
    public function toArray(): array
    {
        return [
            'profile_id' => $this->profileId,
            'version' => $this->version,
            'settings' => $this->settings,
            'sections' => $this->sections === [] ? new stdClass : $this->sections,
            'updated_at' => $this->updatedAt,
        ];
    }
}
