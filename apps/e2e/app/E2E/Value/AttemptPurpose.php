<?php

declare(strict_types=1);

namespace App\E2E\Value;

/** Why an attempt topology exists. Discovery is the only purpose. */
enum AttemptPurpose: string
{
    case Discovery = 'discovery';
}
