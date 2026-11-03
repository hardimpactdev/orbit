<?php

declare(strict_types=1);

use App\Actions\Nodes\AdmitWorkerKnownHostsAction;

chdir(dirname(__DIR__, 3).'/apps/gateway');
require 'vendor/autoload.php';
$app = require 'bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
// The same publication action called by committed Node pin changes, on the isolated Gateway.
$operation = app(AdmitWorkerKnownHostsAction::class)->execute();
echo json_encode(['bindings' => ['operation' => $operation->id]], JSON_THROW_ON_ERROR)."\n";
