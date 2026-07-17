<?php

declare(strict_types=1);

namespace App\Services\Php;

use App\Data\Php\PhpRuntimeImageInventory;

final readonly class PhpRuntimeImageInventoryMapper
{
    public function __construct(
        private PhpRuntimeCatalog $catalog,
    ) {}

    /**
     * @param  array<string, mixed>  $config
     */
    public function stored(array $config): PhpRuntimeImageInventory
    {
        $images = $this->catalog->approvedImages($config['images'] ?? null);
        $status = $config['image_inventory_status'] ?? null;

        $status = match ($status) {
            'confirmed', 'stale', 'unavailable' => $status,
            default => $images !== [] ? 'confirmed' : 'stale',
        };

        return new PhpRuntimeImageInventory(
            status: $status,
            images: $images,
            versions: $this->catalog->versionsForImages($images),
            error: is_string($config['image_inventory_error'] ?? null)
                ? $config['image_inventory_error']
                : null,
        );
    }

    /**
     * @param  array<string, mixed>  $config
     */
    public function unavailable(array $config, string $error): PhpRuntimeImageInventory
    {
        $images = $this->catalog->approvedImages($config['images'] ?? null);

        return new PhpRuntimeImageInventory(
            status: 'unavailable',
            images: $images,
            versions: $this->catalog->versionsForImages($images),
            error: $error,
        );
    }

    public function confirmed(mixed $images): PhpRuntimeImageInventory
    {
        $approvedImages = $this->catalog->approvedImages($images);

        return new PhpRuntimeImageInventory(
            status: 'confirmed',
            images: $approvedImages,
            versions: $this->catalog->versionsForImages($approvedImages),
        );
    }
}
