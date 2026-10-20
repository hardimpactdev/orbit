<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Schema;

it('leaves no task definitions table after migration', function (): void {
    expect(Schema::hasTable('task_definitions'))->toBeFalse();
});
