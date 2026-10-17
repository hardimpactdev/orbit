<?php

declare(strict_types=1);

namespace Tests\Support;

use FilesystemIterator;
use PHPUnit\Framework\Assert;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/** Detect writes bypassing Storage::fake(), including direct transcript file writes. */
final readonly class RealStorageGuard
{
    /** @param array<string, string> $before */
    private function __construct(private string $root, private array $before) {}

    public static function watch(string $root): self
    {
        return new self($root, self::snapshot($root));
    }

    public function assertUnchanged(): void
    {
        Assert::assertSame($this->before, self::snapshot($this->root), 'A Feature test changed real storage at '.$this->root.'. Use Storage::fake() or a per-test temporary root.');
    }

    /** @return array<string, string> */
    private static function snapshot(string $root): array
    {
        clearstatcache();
        if (! is_dir($root)) {
            return [];
        }
        $files = [];
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::SELF_FIRST) as $file) {
            $path = $file->getPathname();
            $files[substr($path, strlen($root))] = $file->isDir() ? 'directory' : implode(':', [$file->getInode(), $file->getMTime(), $file->getCTime(), hash_file('sha256', $path)]);
        }
        ksort($files);

        return $files;
    }
}
