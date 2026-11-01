<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Domain\Projects\ProjectSourceAccess;
use App\Domain\Projects\ProjectSourceReader;

/** Committed files keyed by repository-relative path; an unlisted path is absent. Records each read. */
final class FakeProjectSourceReader implements ProjectSourceReader
{
    /** @var list<array{repository:string,branch:string,paths:list<string>}> */
    public array $reads = [];

    /** @param array<string, array{mode:string, content:string}> $files */
    public function __construct(public array $files = [], public string $head = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa') {}

    public function read(string $repositoryUrl, ProjectSourceAccess $access, string $branch, array $paths): array
    {
        $this->reads[] = ['repository' => $repositoryUrl, 'branch' => $branch, 'paths' => $paths];

        return ['head' => $this->head, 'files' => array_combine($paths, array_map(fn (string $path): ?array => isset($this->files[$path])
            ? [...$this->files[$path], 'object' => sha1($this->files[$path]['content'])] : null, $paths))];
    }
}
