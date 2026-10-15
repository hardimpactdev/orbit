<?php

declare(strict_types=1);

namespace App\Infrastructure\TaskVms;

use App\Domain\TaskVms\TaskVmException;
use App\Domain\TaskVms\TaskVmHost;
use App\Domain\TaskVms\TaskVmImageBuildStep;
use App\Domain\TaskVms\TaskVmSettings;
use App\Infrastructure\Processes\ProtectedInput;
use App\Infrastructure\Ssh\SshKeyProvider;
use App\Jobs\TaskVms\ImageBuild\SyncBuiltImage;
use App\Models\TaskVmImageBuild;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Sleep;

/** Executes one durable step. No rendered programs or infrastructure objects enter the queue payload. */
final readonly class TaskVmImageBuildSteps
{
    public function __construct(private TaskVmSettings $settings, private IncusHost $incus, private IncusTaskVmBaseImage $image, private IncusTaskVmImageBuilder $builder, private TaskVmCloudInit $cloudInit, private SshKeyProvider $keys, private TaskVmImageRecipe $recipe) {}

    public function run(TaskVmImageBuild $build, TaskVmImageBuildStep $step, ?string $program): bool
    {
        $node = $build->hostNode;
        $host = $this->settings->host($node->id);
        $name = (string) $build->builder_vm_name;
        $shell = $this->image->shell($host, $node, $name);
        $alias = $host->imageAlias.'-'.$build->created_at?->format('YmdHis').'-'.$build->id;
        switch ($step) {
            case TaskVmImageBuildStep::Launch:
                if ($build->source_alias === null) {
                    $current = $build->from_stock ? null : $this->incus->aliasTarget($host, $node, $host->imageAlias);
                    $build->forceFill(['source_alias' => $current === null ? TaskVmHost::SourceImage : $host->imageAlias, 'source_fingerprint' => $current ?? $this->incus->aliasTarget($host, $node, TaskVmHost::SourceImage)])->save();
                }
                $this->image->sweep($host, $node, discardFailed: true);
                if (! array_key_exists($name, $this->image->instances($host, $node))) {
                    $this->image->launch($host, $node, $name, $build->source_fingerprint ?? (string) $build->source_alias, $this->cloudInit->renderImageBuilder($this->keys->publicKey(), upgradePackages: false));
                }
                break;
            case TaskVmImageBuildStep::AwaitBoot:
                return $this->booted($shell);
            case TaskVmImageBuildStep::UpgradePackages:
                $this->image->checked($name, 'owner', $shell->asRoot(TaskVmImageRecipe::ownerCheck()));
                $packages = implode(' ', ['openssh-server', ...TaskVmCloudInit::ChromiumLibraries, ...TaskVmCloudInit::WorkspaceTools]);
                $this->image->checked($name, 'update package lists', $shell->asRoot(TaskVmScripts::bash('apt-update.sh', ['300']), timeout: 360));
                $this->image->checked($name, 'upgrade packages', $shell->asRoot(['bash', '-ceu', 'export DEBIAN_FRONTEND=noninteractive; apt-get -o DPkg::Lock::Timeout=300 -y full-upgrade; apt-get -o DPkg::Lock::Timeout=300 -y install '.$packages], timeout: 1080));
                break;
            case TaskVmImageBuildStep::Program:
                if ($program === 'agent-binary') {
                    $arch = $this->image->checked($name, 'uname', $shell->asRoot(['uname', '-m']))->stdout;
                    $result = $shell->asRoot($this->recipe->installAgent($arch, $name));
                } elseif ($program === 'pi-artifact') {
                    $result = $shell->asUser(['bash', '-ceu', TaskVmRuntime::InstallPi, '--', (string) $this->settings->piArtifactSha256], ProtectedInput::fromFile((string) $this->settings->piArtifactPath, '', TaskVmRuntime::ArtifactMaxBytes));
                } else {
                    $command = $this->recipe->programs()[(string) $program] ?? throw new TaskVmException('task_vm.image_build_failed', "Image program [{$program}] is no longer in the recipe.");
                    $result = $shell->asUser($command->arguments, $command->protectedInput ?? $command->input);
                }
                $this->image->checked($name, 'program '.(string) $program, $result);
                break;
            case TaskVmImageBuildStep::PrepareBoot:
                $this->builder->singleBoot($host, $node, $shell);
                $this->image->checked($name, 'lean boot', $shell->asRoot(['bash', '-ceu', TaskVmScripts::read('lean-boot.sh').TaskVmImageRecipe::Masks]));
                break;
            case TaskVmImageBuildStep::Clean:
                $this->builder->clean($shell);
                break;
            case TaskVmImageBuildStep::Publish:
                $fingerprint = $build->image_fingerprint ?? $this->incus->aliasTarget($host, $node, $alias);
                $fingerprint ??= $this->builder->publish($host, $node, $name, $alias);
                $build->image_fingerprint = $fingerprint;
                $build->save();
                $this->image->delete($host, $node, $name);
                break;
            case TaskVmImageBuildStep::Smoke:
                $smoke = $name.'-smoke';
                if (! array_key_exists($smoke, $this->image->instances($host, $node))) {
                    $this->image->launch($host, $node, $smoke, (string) $build->image_fingerprint, $this->cloudInit->render($this->keys->publicKey()));
                }
                $shell = $this->image->shell($host, $node, $smoke);
                if (! $this->booted($shell)) {
                    return false;
                }
                $this->image->checkSmoke($shell);
                $this->image->checked($smoke, 'image check', $shell->asRoot(TaskVmScripts::bash('image-check.sh', [TaskVmCloudInit::User, TaskVmCloudInit::Owner, '--lean-boot'])));
                // Keep the VM until promotion so a lost reply does not repeat the smoke boot.
                break;
            case TaskVmImageBuildStep::Promote:
                $fingerprint = (string) $build->image_fingerprint;
                $this->image->promote($host, $node, $fingerprint);
                $this->image->delete($host, $node, $name.'-smoke');
                $this->image->prune($host, $node, $fingerprint);
                $sync = [];
                foreach ($this->settings->hosts as $peer) {
                    if ($peer->nodeId !== $node->id) {
                        $sync[] = new SyncBuiltImage($build->id, $peer->nodeId);
                    }
                }
                if ($sync !== []) {
                    Bus::chain($sync)->onConnection('gateway')->onQueue('builds')->dispatch();
                }
                break;
        }

        return true;
    }

    private function booted(IncusGuestShell $shell): bool
    {
        // Keep polling in this worker run: releasing to builds would count other builds' queue time.
        $deadline = now()->addSeconds(600);
        do {
            if (TaskVmGuest::cloudInitDone($shell->vm, $shell->asRoot(['cloud-init', 'status', '--format', 'json'], timeout: 20))) {
                return true;
            }
            if (now()->greaterThanOrEqualTo($deadline)) {
                throw new TaskVmException('task_vm.bootstrap_timeout', 'Cloud-init was not done after 600 seconds of boot polling.');
            }
            Sleep::for(5)->seconds();
        } while (true);
    }
}
