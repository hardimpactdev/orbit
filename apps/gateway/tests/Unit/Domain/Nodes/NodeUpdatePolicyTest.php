<?php

declare(strict_types=1);

use App\Domain\Nodes\NodeUpdatePolicy;
use App\Models\Node;
use Illuminate\Support\Facades\Process;

/** @return array<string, string> */
function updatePolicyFiles(string $address = '192.0.2.9'): array
{
    $files = NodeUpdatePolicy::for(new Node(['public_ssh_host' => $address]))['files'];

    return array_combine(array_column($files, 'path'), array_column($files, 'content'));
}

it('installs Ubuntu security updates every day and never reboots the Node itself', function (): void {
    $policy = NodeUpdatePolicy::for(new Node(['public_ssh_host' => '192.0.2.9']));
    $files = updatePolicyFiles();

    expect($policy['packages'])->toBe(['unattended-upgrades', 'needrestart'])
        ->and($policy['daily'])->toBeTrue()
        ->and($policy['timers'])->toBe(['apt-daily.timer', 'apt-daily-upgrade.timer'])
        ->and($files['/etc/apt/apt.conf.d/20auto-upgrades'])->toContain("APT::Periodic::Unattended-Upgrade \"1\";\n")
        ->and($files['/etc/apt/apt.conf.d/52orbit-unattended-upgrades'])
        ->toContain("#clear Unattended-Upgrade::Allowed-Origins;\n")
        ->toContain('Unattended-Upgrade::Allowed-Origins { "${distro_id}:${distro_codename}-security"; };')
        ->toContain("Unattended-Upgrade::Automatic-Reboot \"false\";\n");
});

it('keeps the daily timers off on a Node of a task VM nested fleet, so a proof cannot change under an upgrade', function (): void {
    expect(NodeUpdatePolicy::for(new Node(['public_ssh_host' => '10.232.2.11']))['daily'])->toBeFalse();
});

it('names the same protected units in needrestart and in the reboot-due hook', function (): void {
    $files = updatePolicyFiles();
    $needrestart = $files['/etc/needrestart/conf.d/50-orbit.conf'];

    expect($needrestart)->toContain("\$nrconf{restart} = 'a';\n");
    foreach (NodeUpdatePolicy::ProtectedUnits as $pattern) {
        expect($needrestart)->toContain('$nrconf{override_rc}->{qr('.$pattern.')} = 0;')
            ->and($files[NodeUpdatePolicy::RebootDueScript])->toContain($pattern);
    }
    expect($files['/etc/apt/apt.conf.d/53orbit-reboot-due'])->toContain('DPkg::Post-Invoke { "if [ -x '.NodeUpdatePolicy::RebootDueScript.' ]; then '.NodeUpdatePolicy::RebootDueScript.'; fi"; };');
});

it('marks the Node due when needrestart lists a protected unit, and only then', function (): void {
    $folder = sys_get_temp_dir().'/orbit-reboot-due-'.bin2hex(random_bytes(4));
    mkdir($folder.'/bin', 0700, true);
    $script = str_replace('/var/run/', $folder.'/', updatePolicyFiles()[NodeUpdatePolicy::RebootDueScript]);
    file_put_contents($folder.'/reboot-due', $script);
    $run = static function (string $listing) use ($folder): void {
        file_put_contents($folder.'/bin/needrestart', "#!/bin/sh\nprintf '%s' '".$listing."'\n");
        chmod($folder.'/bin/needrestart', 0755);
        Process::env(['PATH' => $folder.'/bin:/usr/bin:/bin'])->run(['/bin/sh', $folder.'/reboot-due'])->throw();
    };

    try {
        $run("NEEDRESTART-VER: 3.8\nNEEDRESTART-SVC: cron.service\n");
        expect(file_exists($folder.'/reboot-required'))->toBeFalse();

        $run("NEEDRESTART-SVC: cron.service\nNEEDRESTART-SVC: orbit-swamp-worker@a.service\nNEEDRESTART-SVC: docker.service\n");
        expect(file_get_contents($folder.'/reboot-required'))->toBe("*** System restart required ***\n")
            ->and(file_get_contents($folder.'/reboot-required.pkgs'))->toBe("orbit-swamp-worker@a.service\ndocker.service\n");
    } finally {
        Process::run(['rm', '-rf', $folder]);
    }
});
