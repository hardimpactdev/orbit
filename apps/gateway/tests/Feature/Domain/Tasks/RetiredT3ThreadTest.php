<?php

declare(strict_types=1);

use App\Domain\Tasks\AgentDriverException;
use App\Domain\Tasks\AgentDriverRegistry;
use Illuminate\Support\Facades\Artisan;

describe('retired T3 task history', function (): void {
    it('registers only Pi and exposes no T3 metrics or archive commands', function (): void {
        $drivers = app(AgentDriverRegistry::class);
        expect($drivers->get('pi')->key())->toBe('pi');
        expect(fn () => $drivers->get('t3'))->toThrow(AgentDriverException::class, 'The recorded agent driver is unavailable.');
        expect(Artisan::all())->not->toHaveKey('tasks:collect-t3-metrics')->not->toHaveKey('tasks:archive-threads');
    });

});
