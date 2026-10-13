<?php

declare(strict_types=1);

namespace App\Domain\TaskVms;

/**
 * The UpCloud overflow of task VMs, as {@see self::fromConfig()} validated it. A `vm` group gets an UpCloud
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

    /**
     * The overflow from `task_vms.upcloud`, or null while its token file is unset. Its switch needs task VMs enabled,
     * the token file, and `max_vms`: the cost guard has no default.
     *
     * @param  array<string, mixed>  $config
     *
     * @throws TaskVmException
     */
    public static function fromConfig(array $config, bool $taskVmsEnabled): ?self
    {
        $enabled = ($config['enabled'] ?? null) === true;
        $tokenFile = TaskVmConfig::filled($config['token_file'] ?? null);
        if ($tokenFile === null) {
            if ($enabled) {
                throw TaskVmConfig::invalid('upcloud.token_file is required while the UpCloud overflow is enabled.');
            }

            return null;
        }
        TaskVmConfig::validate(['token_file' => $tokenFile], ['token_file' => ['required', 'string', 'starts_with:/', 'not_regex:/\x00/']], [
            'token_file' => 'upcloud.token_file must be an absolute path.',
        ]);
        if ($enabled && ! $taskVmsEnabled) {
            throw TaskVmConfig::invalid('the UpCloud overflow needs task VMs enabled.');
        }
        $valid = TaskVmConfig::validate($config, [
            'projects' => ['present', 'list'],
            'projects.*' => ['integer:strict', 'min:1'],
            'max_vms' => ['required', 'integer', 'between:1,16'],
            'disk_gb' => ['required', 'integer', 'between:10,200'],
            'zone' => ['required', 'string', 'regex:/\A[a-z]{2}-[a-z]{3}[1-9]\z/D'],
            'plan' => ['required', 'string', 'regex:/\A[A-Za-z0-9][A-Za-z0-9-]{0,63}\z/D'],
            'source_template' => ['required', 'string', 'regex:/\A[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}\z/D'],
        ], [
            'projects' => 'upcloud.projects must be a JSON list of Project ids.',
            'projects.*' => 'upcloud.projects must be a JSON list of Project ids.',
            'max_vms' => 'upcloud.max_vms must be a whole number from 1 to 16.',
            'disk_gb' => 'upcloud.disk_gb must be a whole number of GB from 10 to 200.',
            'zone' => 'upcloud.zone is invalid.',
            'plan' => 'upcloud.plan is invalid.',
            'source_template' => 'upcloud.source_template is invalid.',
        ]);

        return new self(
            enabled: $enabled,
            projectIds: array_values(array_unique(TaskVmConfig::ints($config['projects']))),
            tokenFile: is_string($tokenFile) ? $tokenFile : '',
            maxVms: $valid->integer('max_vms'),
            zone: $valid->string('zone')->value(),
            plan: $valid->string('plan')->value(),
            diskGb: $valid->integer('disk_gb'),
            sourceTemplate: $valid->string('source_template')->value(),
        );
    }

    /** Whether a group of this Project may overflow to UpCloud now. */
    public function allows(int $projectId): bool
    {
        return $this->enabled && in_array($projectId, $this->projectIds, true);
    }
}
