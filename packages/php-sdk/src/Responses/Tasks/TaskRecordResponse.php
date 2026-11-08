<?php

declare(strict_types=1);

namespace Orbit\Sdk\Responses\Tasks;

interface TaskRecordResponse
{
    /** @return array<string, mixed> */
    public function toArray(): array;
}
