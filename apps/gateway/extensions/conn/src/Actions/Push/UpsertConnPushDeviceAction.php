<?php

declare(strict_types=1);

namespace Orbit\Extensions\Conn\Actions\Push;

use App\Models\Node;
use Illuminate\Support\Facades\DB;
use Orbit\Extensions\Conn\Models\ConnPushDevice;

/** Upserts an iOS push device registration for the calling Node; a new token replaces the old. */
final readonly class UpsertConnPushDeviceAction
{
    /**
     * @param  array{
     *     device_id: string,
     *     apns_token: string,
     *     apns_environment: string,
     *     bundle_id: string,
     *     app_version: string,
     *     ios_version: string,
     *     locale: string,
     *     preferences: array{approval: bool, input: bool, turn_finished: bool, turn_failed: bool, hide_titles: bool}
     * }  $payload
     */
    public function execute(Node $node, array $payload): ConnPushDevice
    {
        return DB::transaction(static fn (): ConnPushDevice => ConnPushDevice::query()->updateOrCreate(
            ['node_id' => $node->id, 'device_id' => $payload['device_id']],
            [
                'apns_token' => $payload['apns_token'],
                'apns_environment' => $payload['apns_environment'],
                'bundle_id' => $payload['bundle_id'],
                'app_version' => $payload['app_version'],
                'ios_version' => $payload['ios_version'],
                'locale' => $payload['locale'],
                'preferences' => $payload['preferences'],
            ],
        ));
    }
}
