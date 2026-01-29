<?php
declare(strict_types=1);

namespace HardImpact\Orbit\Core\Services;

use HardImpact\Orbit\Core\Models\Environment;

class EnvironmentManager
{
    public function current(): ?Environment
    {
        $environment = Environment::where('is_active', true)->first();

        if ($environment) {
            return $environment;
        }

        $fallback = Environment::where('is_local', true)->first() ?? Environment::first();

        if (! $fallback) {
            return null;
        }

        return $this->setActive($fallback->id);
    }

    public function setActive(int $environmentId): Environment
    {
        $environment = Environment::findOrFail($environmentId);

        Environment::where('id', '!=', $environment->id)->update(['is_active' => false]);
        $environment->update(['is_active' => true]);

        return $environment->refresh();
    }
}
