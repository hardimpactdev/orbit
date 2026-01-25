<?php

namespace HardImpact\Orbit\Ui\Facades;

use Illuminate\Support\Facades\Facade;

/**
 * @see \HardImpact\Orbit\Ui\Ui
 */
class Ui extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return \HardImpact\Orbit\Ui\Ui::class;
    }
}
