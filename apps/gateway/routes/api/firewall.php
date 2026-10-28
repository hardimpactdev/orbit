<?php

declare(strict_types=1);

use App\Http\Controllers\Api\FirewallRulesController;
use App\Http\Controllers\Api\FleetFirewallRulesController;
use App\Http\Middleware\RequireActiveWireGuardPeer;
use App\Http\Middleware\RequireNodeAccess;
use Illuminate\Support\Facades\Route;

Route::middleware([
    RequireActiveWireGuardPeer::class,
    RequireNodeAccess::class,
])->group(function (): void {
    Route::get('firewall-rules', [FleetFirewallRulesController::class, 'index'])
        ->name('firewall:fleet:list');
    Route::get('nodes/{node}/firewall-rules', [FirewallRulesController::class, 'index'])
        ->name('firewall:list');
    Route::get('nodes/{node}/managed-firewall-rules', [FirewallRulesController::class, 'managed'])
        ->whereNumber('node')
        ->name('firewall:managed:list');
    Route::get('nodes/{node}/live-firewall-rules', [FirewallRulesController::class, 'live'])
        ->whereNumber('node')
        ->name('firewall:live:list');
    Route::post('nodes/{node}/firewall-rules/allow', [FirewallRulesController::class, 'store'])
        ->defaults('firewall_action', 'allow')
        ->name('firewall:allow');
    Route::post('nodes/{node}/firewall-rules/deny', [FirewallRulesController::class, 'store'])
        ->defaults('firewall_action', 'deny')
        ->name('firewall:deny');
    Route::delete(
        'nodes/{node}/firewall-rules/{firewallRule:name}',
        [FirewallRulesController::class, 'destroy'],
    )
        ->scopeBindings()
        ->name('firewall:remove');
});
