<?php

declare(strict_types=1);

namespace App\Services\Extensions;

use App\Models\GatewayExtension;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Schema;
use Orbit\Core\Extensions\OrbitExtensionDefinition;
use Orbit\Core\Extensions\OrbitExtensionRegistry;

final readonly class GatewayExtensionState
{
    public function __construct(
        private OrbitExtensionRegistry $registry,
    ) {}

    public function enabled(string $slug): bool
    {
        $this->registry->require($slug);

        if (! Schema::hasTable('gateway_extensions')) {
            return false;
        }

        return GatewayExtension::query()
            ->where('slug', $slug)
            ->where('enabled', true)
            ->exists();
    }

    public function enable(string $slug): GatewayExtension
    {
        $this->registry->require($slug);
        $this->requireTable();

        $extension = GatewayExtension::query()->firstOrNew(['slug' => $slug]);
        $extension->enabled = true;

        if (! $extension->enabled_at instanceof Carbon) {
            $extension->enabled_at = Carbon::now();
        }

        $extension->save();

        return $extension;
    }

    public function disable(string $slug): GatewayExtension
    {
        $this->registry->require($slug);
        $this->requireTable();

        $extension = GatewayExtension::query()->firstOrNew(['slug' => $slug]);
        $extension->enabled = false;
        $extension->enabled_at = null;
        $extension->save();

        return $extension;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function snapshot(): array
    {
        $recordsBySlug = $this->recordsBySlug();

        return array_map(
            fn (OrbitExtensionDefinition $definition): array => $this->snapshotForDefinition(
                $definition,
                $recordsBySlug[$definition->slug] ?? null,
            ),
            $this->registry->all(),
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function snapshotFor(string $slug): array
    {
        $definition = $this->registry->require($slug);
        $record = $this->recordsBySlug()[$slug] ?? null;

        return $this->snapshotForDefinition($definition, $record);
    }

    public function isKnownSlug(string $slug): bool
    {
        return $this->registry->get($slug) instanceof OrbitExtensionDefinition;
    }

    /**
     * @return array<string, mixed>
     */
    private function snapshotForDefinition(
        OrbitExtensionDefinition $definition,
        ?GatewayExtension $record,
    ): array {
        return [
            'slug' => $definition->slug,
            'label' => $definition->label,
            'description' => $definition->description,
            'enabled' => $record->enabled ?? false,
            'enabled_at' => $record?->enabled_at?->toIso8601ZuluString(),
        ];
    }

    /**
     * @return array<string, GatewayExtension>
     */
    private function recordsBySlug(): array
    {
        if (! Schema::hasTable('gateway_extensions')) {
            return [];
        }

        /** @var array<string, GatewayExtension> $recordsBySlug */
        $recordsBySlug = [];
        /** @var iterable<GatewayExtension> $records */
        $records = GatewayExtension::query()->whereIn('slug', $this->registry->slugs())->get();

        foreach ($records as $record) {
            $recordsBySlug[$record->slug] = $record;
        }

        return $recordsBySlug;
    }

    private function requireTable(): void
    {
        if (! Schema::hasTable('gateway_extensions')) {
            throw new GatewayExtensionStorageUnavailable;
        }
    }
}
