<?php

declare(strict_types=1);

namespace App\Domain\Extensions;

use App\Domain\Settings\SettingRepository;
use App\Domain\Settings\SettingScope;
use App\Domain\Settings\SettingScopeType;

/** The Gateway-owned extension switches, one Gateway setting per known extension. */
final readonly class ExtensionStore
{
    public function __construct(private SettingRepository $settings, private ExtensionRegistry $registry) {}

    public function enabled(string $extension): bool
    {
        $this->registry->find($extension);

        return $this->settings->get($this->scope(), "extension.{$extension}.enabled") === '1';
    }

    /** @return array<string, bool> */
    public function all(): array
    {
        $names = $this->registry->names();

        return array_combine($names, array_map($this->enabled(...), $names));
    }

    public function set(string $extension, bool $enabled): void
    {
        $this->registry->find($extension);
        $key = "extension.{$extension}.enabled";
        if ($enabled) {
            $this->settings->put($this->scope(), $key, '1');
        } else {
            $this->settings->delete($this->scope(), $key);
        }
    }

    private function scope(): SettingScope
    {
        return new SettingScope(SettingScopeType::Gateway);
    }
}
