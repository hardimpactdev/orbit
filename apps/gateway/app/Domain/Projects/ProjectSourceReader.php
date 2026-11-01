<?php

declare(strict_types=1);

namespace App\Domain\Projects;

use App\Models\Project;

/** Reads committed files from the head of a Project's default branch. It changes nothing. */
interface ProjectSourceReader
{
    /**
     * @param  list<string>  $paths  Repository-relative paths.
     * @return array{head:string, files:array<string, array{mode:string, object:string, content:string}|null>} The head
     *                                                                                                         commit and each path's tree mode, object and bytes, or null when absent.
     */
    public function read(string $repositoryUrl, ProjectSourceAccess $access, string $branch, array $paths): array;
}
