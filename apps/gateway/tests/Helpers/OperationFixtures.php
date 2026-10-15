<?php

declare(strict_types=1);

use App\Domain\Gateway\GatewayQueue;
use App\Jobs\RunOperation;
use Illuminate\Support\Facades\DB;

/** Turns the Gateway queue on for a test, so a start request queues its operation on the database test queue. */
function enable_operation_queue(): void
{
    config(['orbit.gateway_queue.connection' => 'services-valkey']);
}

/**
 * Runs the operation jobs that wait on the `operations` queue of the database test queue, in order, as Horizon would,
 * and returns how many ran.
 */
function run_operation_jobs(): int
{
    $ran = 0;

    while (($row = DB::table('jobs')->where('queue', GatewayQueue::Operations->value)->orderBy('id')->first()) !== null) {
        DB::table('jobs')->where('id', $row->id)->delete();
        $payload = json_decode($row->payload, true, flags: JSON_THROW_ON_ERROR);
        $job = unserialize($payload['data']['command']);
        assert($job instanceof RunOperation);
        app()->call([$job, 'handle']);
        $ran++;
    }

    return $ran;
}
