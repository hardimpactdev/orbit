<?php

declare(strict_types=1);

use App\Domain\WebSocket\WebSocketAppEnvProjection;
use App\Models\Route;
use App\Models\WebSocketApp;
use App\Models\WebSocketAppTarget;

it('points a private app at the reverb.orbit listener of its own, and a public app at its Route on 443', function (): void {
    $app = new WebSocketApp(['name' => 'tool', 'app_id' => '1001', 'key' => 'reverb-key', 'secret' => 'reverb-secret']);
    $private = new WebSocketAppTarget;
    $public = new WebSocketAppTarget;
    $public->setRelation('route', new Route(['domain' => 'tool.example.com']));
    $projection = new WebSocketAppEnvProjection;

    expect(array_intersect_key($projection->project($app, $private), array_flip(['REVERB_HOST', 'REVERB_PORT', 'REVERB_SCHEME'])))
        ->toBe(['REVERB_HOST' => 'reverb.orbit', 'REVERB_PORT' => '8443', 'REVERB_SCHEME' => 'https'])
        ->and(array_intersect_key($projection->project($app), array_flip(['REVERB_HOST', 'REVERB_PORT'])))
        ->toBe(['REVERB_HOST' => 'reverb.orbit', 'REVERB_PORT' => '8443'])
        ->and(array_intersect_key($projection->project($app, $public), array_flip(['REVERB_HOST', 'REVERB_PORT', 'REVERB_SCHEME'])))
        ->toBe(['REVERB_HOST' => 'tool.example.com', 'REVERB_PORT' => '443', 'REVERB_SCHEME' => 'https']);
});
