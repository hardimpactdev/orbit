<?php

declare(strict_types=1);

namespace App\Domain\TaskVms;

/**
 * The UpCloud overflow of task VMs, as `TaskVmSettings::fromConfig()` validated it. A `vm` group gets an UpCloud
 * task VM only while `enabled` is true, its Project is in `projectIds`, and beast and shark have no free slot.
 * `maxVms` caps the live UpCloud task VMs, the cost guard. Present whenever the token file is set, also while
 * `enabled` is false, so the sweep still deletes what a switched-off overflow left.
 */
final readonly class TaskVmUpCloud
{
    /** The label key on every server and the value of each task VM's server: its row id. */
    public const string Label = 'orbit-task-vm';

    /** The title prefix of the task VM templates. A verified one is `orbit-task-base-<time>`. */
    public const string TemplatePrefix = TaskVmHost::BaseImage.'-';

    /** The title suffix of a template whose smoke boot has not passed yet. */
    public const string Unverified = '-unverified';

    /**
     * @param  list<int>  $projectIds
     */
    public function __construct(
        public bool $enabled,
        public array $projectIds,
        public string $tokenFile,
        public int $maxVms,
        public string $zone,
        public string $plan,
        public int $diskGb,
        public string $sourceTemplate,
    ) {}

    /** Whether a group of this Project may overflow to UpCloud now. */
    public function allows(int $projectId): bool
    {
        return $this->enabled && in_array($projectId, $this->projectIds, true);
    }
}
