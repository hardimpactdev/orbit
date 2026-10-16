<?php

declare(strict_types=1);

/*
 * `bash -s` reads its script from stdin while it runs it. A child that reads stdin, such as `incus exec` without `-n`,
 * takes the rest of the script, and Bash then exits 0 having run only its start. The role image transfer lost every
 * copy this way. These are the only files that pipe a script into `bash -s`, with the resource scripts they pipe. Each
 * resource script closes stdin inside main(), which Bash reads whole before it runs it. Each inline script runs no child
 * that reads stdin. Any other script goes as the argument of `bash -c`, as `TaskVmScripts::bash()` passes it.
 */
const StdinScriptFiles = [
    'app/Infrastructure/Doctor/NativeInstanceStateInspector.php' => [],
    'app/Infrastructure/Doctor/NativeScheduleStateInspector.php' => [],
    'app/Infrastructure/Schedules/RemoteScheduleRuntimeManager.php' => [],
    'app/Infrastructure/TaskVms/IncusTaskVmImageBuilder.php' => ['task-vms/base-image-clean.sh'],
    'app/Infrastructure/TaskVms/TaskVmSetupScript.php' => ['task-vms/hub.sh', 'task-vms/incus-host.sh'],
    'app/Infrastructure/TaskVms/UpCloudTaskVmImageBuilder.php' => ['task-vms/base-image-clean.sh'],
];

/** `incus exec` or `lxc exec` that forwards stdin: without `-n`. `${incus[@]}` is how the scripts name `incus --project P`. */
const StdinIncusExec = '/(?:\bincus\b|\blxc\b|\$\{incus\[@\]\}"?)(?:\s+--project\s+\S+)?\s+exec\s+(?!-n\b|--disable-stdin\b)/';

/** A child that reads stdin: `incus exec` without `-n`, `ssh` without `-n`, `docker exec -i`, or `read` without a redirect. */
const StdinReaders = [
    StdinIncusExec,
    '/(?<![\w.\/-])ssh\s+(?!(?:[^\n]*\s)?-n\b)/',
    '/\bdocker\s+exec\s+(?:[^\n]*\s)?-\w*i\b/',
    '/(?<![\w.$-])read\s(?![^\n]*<)/',
];

/** The path of `$path`, relative to the Gateway. */
function stdin_script_path(string $path): string
{
    return dirname(path: __DIR__, levels: 3).'/'.$path;
}

/** The lines of a shell script without its comments. */
function stdin_script_commands(string $script): string
{
    return (string) preg_replace('/^\s*#.*$/m', '', $script);
}

/** @return list<string> the paths, below the Gateway, of the files of `$directories` with the extension `$extension` */
function stdin_script_files(array $directories, string $extension): array
{
    $gateway = stdin_script_path('');
    $found = [];
    foreach ($directories as $directory) {
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($gateway.$directory, FilesystemIterator::SKIP_DOTS)) as $file) {
            if ($file instanceof SplFileInfo && $file->getExtension() === $extension) {
                $found[] = substr($file->getPathname(), strlen($gateway));
            }
        }
    }
    sort($found);

    return $found;
}

it('lists exactly the files that pipe a script into bash -s', function (): void {
    $found = array_values(array_filter(
        stdin_script_files(['app', 'extensions'], 'php'),
        static fn (string $path): bool => preg_match('/[\'"](?:ba)?sh[\'"]\s*,\s*[\'"]-s[\'"]/', (string) file_get_contents(stdin_script_path($path))) === 1,
    ));

    expect($found)->toBe(array_keys(StdinScriptFiles), 'Pass the script as the argument of `bash -c` (TaskVmScripts::bash()), or review it and list it here.');
});

it('closes stdin in main() of every resource script that goes into bash -s', function (string $name): void {
    $script = (string) file_get_contents(stdin_script_path('resources/'.$name));

    expect($script)->toMatch('/^main\(\) \{\n    exec [^\n]*<\/dev\/null\n/m')
        ->toEndWith("\nmain \"\$@\"\n");
})->with(array_values(array_unique(array_merge(...array_values(StdinScriptFiles)))));

it('runs no child that reads stdin in an inline script that goes into bash -s', function (string $path): void {
    preg_match_all('/<<<[ \t]*([\'"]?)([A-Z]+)\1\n(.*?)\n[ \t]*\2\b/s', (string) file_get_contents(stdin_script_path($path)), $heredocs);
    $scripts = stdin_script_commands(implode("\n", $heredocs[3]));

    expect($heredocs[3])->not->toBe([]);
    foreach (StdinReaders as $reader) {
        expect(preg_match($reader, $scripts, $match))->toBe(0, "[{$path}] runs a child that reads stdin: ".($match[0] ?? ''));
    }
})->with(array_keys(array_filter(StdinScriptFiles, static fn (array $resources): bool => $resources === [])));

it('runs every incus exec of a resource script with stdin closed', function (string $path): void {
    $script = stdin_script_commands((string) file_get_contents(stdin_script_path($path)));

    expect(preg_match(StdinIncusExec, $script, $match))->toBe(0, "[{$path}] runs `incus exec` without `-n`: ".($match[0] ?? ''));
    if (preg_match('/\sexec\s+-n\s/', $script) === 1) {
        expect($script)->toMatch('/^\s*exec [^\n]*<\/dev\/null$/m');
    }
})->with(stdin_script_files(['resources'], 'sh'));

it('catches the stdin readers that cut a script short', function (string $command, bool $reads): void {
    expect(array_any(StdinReaders, static fn (string $reader): bool => preg_match($reader, $command) === 1))->toBe($reads);
})->with([
    ['"${incus[@]}" exec "$vm" -- install -d /x', true],
    ['"${incus[@]}" exec -n "$vm" -- install -d /x', false],
    ['incus --project orbit-tasks exec tvm-1 -- true', true],
    ['incus exec --disable-stdin tvm-1 -- true', false],
    ['ssh host uptime', true],
    ['ssh -n host uptime', false],
    ['ssh-keygen -l -f key.pub', false],
    ['docker exec -i app php -v', true],
    ['docker exec app php -v', false],
    ['read -r line', true],
    ['IFS=. read -r -a octets <<<"$range"', false],
]);
