<?php

declare(strict_types=1);

use App\Domain\Instances\InstanceFolderPlan;

/** Runs the wrapper with the real tool's path replaced by a stub that prints its arguments, one per line. */
function btrfstuneWrapperCall(string ...$args): string
{
    $folder = sys_get_temp_dir().'/btrfstune-'.bin2hex(random_bytes(4));
    mkdir($folder);
    file_put_contents("{$folder}/real", "#!/bin/sh\nfor a in \"\$@\"; do printf '%s\\n' \"\$a\"; done\n");
    chmod("{$folder}/real", 0755);
    file_put_contents("{$folder}/btrfstune", str_replace('/usr/bin/btrfstune', "{$folder}/real", InstanceFolderPlan::Btrfstune));
    chmod("{$folder}/btrfstune", 0755);
    $output = shell_exec(implode(' ', array_map('escapeshellarg', ["{$folder}/btrfstune", ...$args])));
    array_map('unlink', ["{$folder}/real", "{$folder}/btrfstune"]);
    rmdir($folder);

    return (string) $output;
}

it('turns exactly Incus\'s `btrfstune -f -u <device>` into `-f -m` and passes every other call through unchanged', function (): void {
    expect(btrfstuneWrapperCall('-f', '-u', '/dev/zvol/pool/vol'))->toBe("-f\n-m\n/dev/zvol/pool/vol\n")
        ->and(btrfstuneWrapperCall('-u', '/dev/x'))->toBe("-u\n/dev/x\n")
        ->and(btrfstuneWrapperCall('-f', '-u', '/dev/x', 'extra'))->toBe("-f\n-u\n/dev/x\nextra\n")
        ->and(btrfstuneWrapperCall('-f', '-m', '/dev/x'))->toBe("-f\n-m\n/dev/x\n")
        ->and(btrfstuneWrapperCall('--version'))->toBe("--version\n")
        ->and(btrfstuneWrapperCall('-f', '-u', 'dev with space'))->toBe("-f\n-m\ndev with space\n");
});

it('puts the wrapper in the runtime folder\'s bin, so a change to it is a new folder version', function (): void {
    $plan = (new ReflectionMethod(InstanceFolderPlan::class, 'version'));
    $folder = ['path' => '/opt/orbit-runtime', 'parts' => [], 'scripts' => ['btrfstune' => InstanceFolderPlan::Btrfstune]];

    expect($plan->invoke(null, $folder))->not->toBe($plan->invoke(null, [...$folder, 'scripts' => []]));
});
