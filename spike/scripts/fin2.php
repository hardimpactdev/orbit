<?php
require 'vendor/autoload.php'; $app = require 'bootstrap/app.php'; $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
[$route, $engine] = [(int) $argv[1], (int) $argv[2]];
$r = App\Models\Operation::query()->findOrFail($route);
$r->forceFill(['state' => [...($r->state ?? []), 'swamp_run_id' => 'spike-sm-'.$engine]])->save();
App\Spike\StateMachine\RouteCreateFlow::finished(App\Models\Operation::query()->findOrFail($engine), 'failed');
echo "$route: ", $r->refresh()->status->value, PHP_EOL;
