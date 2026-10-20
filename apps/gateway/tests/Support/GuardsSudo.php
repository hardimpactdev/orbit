<?php

declare(strict_types=1);

namespace Tests\Support;

use PHPUnit\Framework\Attributes\After;
use PHPUnit\Framework\Attributes\Before;

trait GuardsSudo
{
    #[Before(100)]
    protected function guardSudo(): void
    {
        NoSudo::start($this->groups());
    }

    #[After(-100)]
    protected function assertNoSudo(): void
    {
        NoSudo::finish();
    }
}
