<?php

declare(strict_types=1);

use App\Domain\Schedules\ScheduleTrigger;

it('picks the native trigger for cron and keeps the Node timer for a systemd calendar', function (string $calendar, ScheduleTrigger $trigger): void {
    expect(ScheduleTrigger::forCalendar($calendar))->toBe($trigger);
})->with([
    ['0 3 * * *', ScheduleTrigger::Swamp],
    ['20 * * * * *', ScheduleTrigger::Swamp],
    ['@daily', ScheduleTrigger::Swamp],
    ['*-*-* *:*:00', ScheduleTrigger::Node],
    ['daily', ScheduleTrigger::Node],
    ['Mon..Fri 02:00', ScheduleTrigger::Node],
]);
