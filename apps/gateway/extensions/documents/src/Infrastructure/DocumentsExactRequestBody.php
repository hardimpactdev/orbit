<?php

declare(strict_types=1);

namespace Orbit\Extensions\Documents\Infrastructure;

use App\Domain\Gateway\ExactRequestBody;

/** Document requests bound their own JSON body after Node access ([Project Documents](/reference/project-documents#limits)). */
final readonly class DocumentsExactRequestBody implements ExactRequestBody
{
    public function matches(string $path): bool
    {
        return preg_match('~\Aapi/v1/projects/[0-9]+/documents(?:/|\z)~D', $path) === 1;
    }
}
