<?php

declare(strict_types=1);

namespace App\Domain\ProjectDefinitions;

enum DefinitionEnvironment: string
{
    case Production = 'production';
    /** Process definitions only: a development Instance creates and starts them after its setup steps. */
    case Development = 'development';
}
