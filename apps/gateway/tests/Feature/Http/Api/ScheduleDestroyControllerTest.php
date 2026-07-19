<?php

declare(strict_types=1);

use App\Models\Node;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('returns canonical destructive consent metadata before removing a schedule', function (): void {
    Node::factory()->create([
        'name' => 'schedule-operator',
        'wireguard_address' => '10.6.0.93',
    ]);

    $response = $this->withServerVariables([
        'REMOTE_ADDR' => '10.6.0.93',
    ])->deleteJson('/api/schedules/nightly');

    $response
        ->assertUnprocessable()
        ->assertJsonPath('error.code', 'validation_failed')
        ->assertJsonPath('error.meta.field', 'force')
        ->assertJsonPath('error.meta.reason', 'destructive_consent_required');
});
