<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Domain\Tasks\TaskAnalysisRunner;
use App\Models\Instance;

final class FakeTaskAnalysisRunner implements TaskAnalysisRunner
{
    public int $starts = 0;

    public int $baseReads = 0;

    public ?string $initialBase = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';

    public function initialAnalysisBase(Instance $instance): ?string
    {
        $this->baseReads++;

        return $this->initialBase;
    }
}
