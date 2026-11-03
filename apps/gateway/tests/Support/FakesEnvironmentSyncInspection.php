<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Domain\Instances\Environment\InstanceEnvironmentContext;

/** Compose the configured preflight and file-reader fakes for synchronization tests. */
trait FakesEnvironmentSyncInspection
{
    public function readWritableEnvironment(InstanceEnvironmentContext $context, int $requiredCapacityBytes): string
    {
        $this->assertEnvironmentWritable($context, $requiredCapacityBytes);

        return $this->read($context);
    }
}
