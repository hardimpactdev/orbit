<?php

declare(strict_types=1);

namespace App\Services\Extensions;

use App\Models\GatewayExtension;
use Illuminate\Support\Carbon;
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

        return GatewayExtension::query()
            ->where('slug', $slug)
            ->where('enabled', true)
            ->exists();
    }

    public function enable(string $slug): GatewayExtension
    {
        $this->registry->require($slug);

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
        /** @var array<string, GatewayExtension> $recordsBySlug */
        $recordsBySlug = [];

        foreach (GatewayExtension::query()->whereIn('slug', $this->registry->slugs())->get() as $record) {
            $recordsBySlug[$record->slug] = $record;
        }

        return array_map(
            fn (OrbitExtensionDefinition $definition): array => $this->snapshotForDefinition(
                $definition,
                $this->recordForSlug($recordsBySlug, $definition->slug),
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
        $record = GatewayExtension::query()->where('slug', $slug)->first();

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
            'enabled' => $record?->enabled ?? false,
            'enabled_at' => $record?->enabled_at?->toIso8601ZuluString(),
        ];
    }

    /**
     * @param  array<array-key, mixed>  $recordsBySlug
     */
    private function recordForSlug(array $recordsBySlug, string $slug): ?GatewayExtension
    {
        $record = $recordsBySlug[$slug] ?? null;

        if (! $record instanceof GatewayExtension) {
            return null;
        }

        return $record;
    }
}
