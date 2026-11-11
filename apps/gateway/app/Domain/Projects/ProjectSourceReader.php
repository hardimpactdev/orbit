<?php

declare(strict_types=1);

namespace App\Domain\Projects;

/** Reads committed files from a Project branch head or an exact commit. It changes nothing. */
interface ProjectSourceReader
{
    /**
     * @param  list<string>  $paths  Repository-relative paths.
     * @return array{head:string, files:array<string, array{mode:string, object:string, content:string}|null>} The head
     *                                                                                                         commit and each path's tree mode, object and bytes, or null when absent.
     */
    public function read(string $repositoryUrl, ProjectSourceAccess $access, string $branch, array $paths, ?string $commit = null): array;
}
