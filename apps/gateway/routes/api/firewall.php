<?php

declare(strict_types=1);

use App\Http\Controllers\Api\FirewallRulesController;
use App\Http\Middleware\RequireActiveWireGuardPeer;
use App\Http\Middleware\RequireNodeAccess;
use Illuminate\Support\Facades\Route;

Route::middleware([
    RequireActiveWireGuardPeer::class,
    RequireNodeAccess::class,
])->group(function (): void {
    Route::get('firewall-rules', [FirewallRulesController::class, 'index'])
        ->name('firewall:list');
    Route::post('nodes/{node}/firewall-rules/allow', [FirewallRulesController::class, 'allow'])
        ->defaults('firewall_action', 'allow')
        ->name('firewall:allow');
    Route::post('nodes/{node}/firewall-rules/deny', [FirewallRulesController::class, 'deny'])
        ->defaults('firewall_action', 'deny')
        ->name('firewall:deny');
    Route::post('nodes/{node}/firewall-rules/remove', [FirewallRulesController::class, 'destroy'])
        ->name('firewall:remove');
});
