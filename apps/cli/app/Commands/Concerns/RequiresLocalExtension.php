<?php

declare(strict_types=1);

namespace App\Commands\Concerns;

use App\Services\Extensions\LocalExtensionState;
use Orbit\Core\Extensions\OrbitExtensionRegistry;

trait RequiresLocalExtension
{
    abstract protected function extensionSlug(): string;

    /**
     * @param  array<string, mixed>  $meta
     * @param  array<string, mixed>  $data
     */
    abstract protected function renderFailure(string $code, string $message, array $meta = [], array $data = []): int;

    public function isHidden(): bool
    {
        return ! $this->localExtensionState()->enabled($this->extensionSlug());
    }

    protected function guardLocalExtension(): ?int
    {
        $slug = $this->extensionSlug();

        if ($this->localExtensionState()->enabled($slug)) {
            return null;
        }

        $definition = app(OrbitExtensionRegistry::class)->require($slug);

        return $this->renderFailure(
            'extension_disabled',
            "The {$definition->label} extension is not enabled on this node. Run `orbit extension:enable {$slug}` first.",
            [
                'extension' => $slug,
                'scope' => 'local',
            ],
        );
    }

    private function localExtensionState(): LocalExtensionState
    {
        return app(LocalExtensionState::class);
    }
}
