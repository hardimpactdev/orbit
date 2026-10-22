<?php

declare(strict_types=1);

namespace Tests\Support;

final class ScriptedBashCommand
{
    /** Source an executable fixture in a subshell, retaining command-local variables and options. */
    public static function define(string $name, string $path): string
    {
        return $name.'() ( source '.escapeshellarg($path).' "$@"; )'."\n";
    }
}
