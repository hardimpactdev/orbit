<?php

declare(strict_types=1);

namespace App\Providers;

use App\Domain\Extensions\Extension;
use Illuminate\Support\ServiceProvider;

/**
 * The one service provider of an extension ([Extensions](/reference/extensions#the-provider)).
 *
 * The provider registers what the extension owns, and contributes to core plug-in points with {@see contribute()}.
 */
abstract class ExtensionServiceProvider extends ServiceProvider implements Extension
{
    /** @return list<string> */
    public function dependencies(): array
    {
        return [];
    }

    public function enable(): void {}

    public function disable(): void {}

    /**
     * Tags an implementation with the plug-in interface it contributes to. Core resolves every tagged implementation.
     *
     * @param  class-string  $point
     * @param  class-string  $implementation
     */
    protected function contribute(string $point, string $implementation): void
    {
        $this->app->tag([$implementation], $point);
    }
}
