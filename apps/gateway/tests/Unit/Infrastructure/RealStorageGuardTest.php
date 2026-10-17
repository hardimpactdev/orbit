<?php

declare(strict_types=1);

use PHPUnit\Framework\ExpectationFailedException;
use Tests\Support\RealStorageGuard;

it('detects actual new overwritten and deleted files independently of the storage facade', function (string $change): void {
    $root = sys_get_temp_dir().'/orbit-storage-guard-'.bin2hex(random_bytes(12));
    mkdir($root);
    file_put_contents($root.'/existing', 'original');
    $guard = RealStorageGuard::watch($root);

    try {
        match ($change) {
            'new transcript' => file_put_contents($root.'/transcript.json', '{}'),
            'overwrite' => file_put_contents($root.'/existing', 'changed'),
            'delete' => unlink($root.'/existing'),
        };

        expect(fn () => $guard->assertUnchanged())->toThrow(ExpectationFailedException::class, 'changed real storage');
    } finally {
        foreach (glob($root.'/*') as $file) {
            unlink($file);
        }
        rmdir($root);
    }
})->with(['new transcript', 'overwrite', 'delete']);

it('accepts unchanged storage while another temporary archive is written', function (): void {
    $root = sys_get_temp_dir().'/orbit-storage-guard-'.bin2hex(random_bytes(12));
    mkdir($root);
    $archive = tempnam(sys_get_temp_dir(), 'orbit-archive-');
    $guard = RealStorageGuard::watch($root);

    try {
        file_put_contents($archive, '{}');
        $guard->assertUnchanged();
    } finally {
        unlink($archive);
        rmdir($root);
    }
});
