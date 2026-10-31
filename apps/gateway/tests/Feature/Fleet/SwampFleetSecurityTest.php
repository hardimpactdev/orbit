<?php

declare(strict_types=1);

use App\Infrastructure\Activity\CommandActivityInputSanitizer;

it('redacts a GitHub token followed by an underscore suffix', function (): void {
    $secret = 'ghs_'.str_repeat('a', 36);
    expect(app(CommandActivityInputSanitizer::class)->redactText('_'.$secret.'_suffix'))->not->toContain($secret);
});
