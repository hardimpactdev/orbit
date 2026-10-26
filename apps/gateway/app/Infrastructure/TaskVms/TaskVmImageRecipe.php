<?php

declare(strict_types=1);

namespace App\Infrastructure\TaskVms;

use App\Domain\Certificates\LeafCertificateSigner;
use App\Domain\TaskVms\TaskVmException;
use App\Infrastructure\AgentCommands\AgentCommandRefused;
use App\Infrastructure\AgentCommands\AgentGuest;
use App\Infrastructure\AgentCommands\GuestAgentCommandRunner;

/** Common guest identity checks and cleanup for recipe-built task VM images. */
final readonly class TaskVmImageRecipe
{
    /** Names the builder and smoke VMs or servers, followed by the build's time stamp. */
    public const string WorkPrefix = 'tvm-image-';

    public function __construct(private GuestAgentCommandRunner $agents, private LeafCertificateSigner $certificates) {}

    public function owner(AgentGuest $guest): void
    {
        $this->agents->installGuest($guest);
        $this->request($guest, 'image.owner');
    }

    public function boot(AgentGuest $guest, bool $lean): void
    {
        $this->request($guest, 'image.boot', ['lean' => $lean]);
    }

    public function firewall(AgentGuest $guest): void
    {
        $this->request($guest, 'image.firewall');
    }

    public function mark(AgentGuest $guest, string $identity, string $profile): void
    {
        $this->request($guest, 'image.recipe-marker', ['identity' => $identity, 'profile' => $profile]);
    }

    public function log(AgentGuest $guest, string $profile, string $phase, string $text): void
    {
        $this->request($guest, 'image.recipe-log', ['profile' => $profile, 'phase' => $phase, 'text' => mb_strcut($text, -65536, encoding: 'UTF-8')]);
    }

    public function check(AgentGuest $guest, bool $leanBoot = false, bool $firewall = false): void
    {
        $this->request($guest, 'image.check', ['lean_boot' => $leanBoot, 'firewall' => $firewall]);
    }

    /** @param array<string, mixed> $input */
    private function request(AgentGuest $guest, string $command, array $input = []): void
    {
        try {
            $result = $this->agents->runGuest($guest, $command, $input);
        } catch (AgentCommandRefused $error) {
            if (! in_array($error->errorCode, ['task_vm.image_check_failed', 'task_vm.image_build_failed'], true)) {
                throw $error;
            }
            $reason = is_string($error->details['reason'] ?? null) ? $error->details['reason'] : $error->getMessage();
            throw new TaskVmException('task_vm.image_build_failed', "Image command [{$command}] failed on [{$guest->name}]: ".$reason, 502);
        }
        if (($result['ready'] ?? null) !== true) {
            throw new TaskVmException('task_vm.image_build_failed', "Image command [{$command}] did not confirm on [{$guest->name}].", 502);
        }
    }

    /** Installs the pin explicitly, then removes only an unenrolled builder's identity. */
    public function clean(AgentGuest $guest): void
    {
        $this->agents->installGuest($guest);
        try {
            $this->request($guest, 'image.trust-ca', ['certificate' => $this->certificates->rootCertificate()]);
            $result = $this->agents->runGuest($guest, 'image.clean');
        } catch (AgentCommandRefused $error) {
            if (! in_array($error->errorCode, ['task_vm.image_clean_failed', 'task_vm.image_identity_present'], true)) {
                throw $error;
            }
            throw new TaskVmException('task_vm.image_build_failed', "Image cleanup failed on [{$guest->name}]: ".$error->getMessage(), 502);
        }
        if (($result['ready'] ?? null) !== true) {
            throw new TaskVmException('task_vm.image_build_failed', "Image cleanup did not confirm on [{$guest->name}].", 502);
        }
    }
}
