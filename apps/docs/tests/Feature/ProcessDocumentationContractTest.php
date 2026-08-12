<?php

declare(strict_types=1);

use Illuminate\Support\Facades\File;

it('keeps process documentation aligned with the removed crash notifier surface', function (): void {
    $processDocs = collect(File::allFiles(base_path('content/domains/7_process')))
        ->filter(static fn (SplFileInfo $file): bool => $file->getExtension() === 'md')
        ->map(static fn (SplFileInfo $file): string => $file->getContents())
        ->implode("\n");

    expect($processDocs)
        ->not->toContain('lifecycle event notifier material')
        ->not->toContain('event notifier material')
        ->not->toContain('Lifecycle notifier material')
        ->not->toContain('runtime hook on the node reports')
        ->not->toContain('runtime hooks that Orbit manages')
        ->not->toContain('crash-notification parity')
        ->not->toContain('One of `none`, `none`');

    expect($processDocs)
        ->toContain('The only supported value is `none`.')
        ->toContain('does not install a process crash hook')
        ->toContain('Existing `crashed` events remain readable');
});
