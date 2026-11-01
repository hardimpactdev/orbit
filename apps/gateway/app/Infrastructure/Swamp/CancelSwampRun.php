<?php

declare(strict_types=1);

namespace App\Infrastructure\Swamp;

use App\Domain\Shared\ResourceOperationException;
use App\Models\Operation;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Throwable;

/** One request to Swamp's native cancellation endpoint; terminal projection stays native. */
final readonly class CancelSwampRun
{
    public function execute(Operation $operation): void
    {
        $run = $operation->state['swamp_run_id'] ?? null;
        $token = config('swamp.run_token');
        $address = config('swamp.address');
        $ca = config('swamp.ca_path');
        if (! is_string($run) || ! Str::isUuid($run) || ! is_string($token) || $token === ''
            || ! is_string($address) || $address === '' || ! is_string($ca) || $ca === '') {
            throw $this->unavailable();
        }
        try {
            $response = Http::withToken($token)->withOptions(['verify' => $ca,
                'curl' => [CURLOPT_CONNECT_TO => ['swamp.orbit:443:'.$address.':443']]])
                ->post('https://swamp.orbit/api/v1/cancel/workflow-run/'.$run, ['reason' => 'Orbit Operation cancellation requested']);
            if (! $response->successful()) {
                throw $this->unavailable();
            }
        } catch (Throwable) {
            throw $this->unavailable();
        }
    }

    private function unavailable(): ResourceOperationException
    {
        return new ResourceOperationException('swamp.cancel_unavailable', 'Native cancellation was not confirmed; the terminal run remains authoritative.', 503);
    }
}
