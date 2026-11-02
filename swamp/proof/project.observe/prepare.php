<?php

declare(strict_types=1);

use App\Domain\Operations\OperationIdempotency;
use App\Domain\Projects\ProjectMaintenanceIntent;
use App\Models\Project;

chdir(dirname(__DIR__, 3).'/apps/gateway');
require 'vendor/autoload.php';
$app = require 'bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
set_exception_handler(static function (Throwable $error): void {
    fwrite(STDERR, 'observe fixture: '.get_class($error)."\n");
    exit(1);
});
$identity = app(OperationIdempotency::class);
$identity->kind = 'project.observe';
$identity->key = 'proof-project-observe-'.$argv[1];
$identity->fingerprint = hash('sha256', $identity->key);
// Observe has no public mutation endpoint. Use the same admission action as the native Schedule.
$operation = app(ProjectMaintenanceIntent::class)->start(Project::query()->findOrFail(32), 'observe');
echo json_encode(['bindings' => ['operation' => $operation->id]], JSON_THROW_ON_ERROR)."\n";
