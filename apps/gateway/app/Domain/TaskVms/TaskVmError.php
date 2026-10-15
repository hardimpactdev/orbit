<?php

declare(strict_types=1);

namespace App\Domain\TaskVms;

/**
 * Every stable `task_vm.*` error code, in one place. Rows, `tasks:status`, logs, and the API carry the value.
 * {@see TaskVmException} takes a case; a test checks that every `task_vm.*` code in the Gateway is a case here.
 */
enum TaskVmError: string
{
    case ImageRecipeSuperseded = 'task_vm.image_recipe_superseded';

    // Configuration and hosts.
    case InvalidConfig = 'task_vm.invalid_config';
    case UnknownHost = 'task_vm.unknown_host';
    case HostConfigured = 'task_vm.host_configured';
    case HostInUse = 'task_vm.host_in_use';
    case RangeInUse = 'task_vm.range_in_use';
    case FleetUnavailable = 'task_vm.fleet_unavailable';
    case SetupFailed = 'task_vm.setup_failed';
    case SetupUnavailable = 'task_vm.setup_unavailable';

    // The VM lifecycle.
    case JobFailed = 'task_vm.job_failed';
    case PoolStartTimeout = 'task_vm.pool_start_timeout';
    case VmMissing = 'task_vm.vm_missing';
    case VmNotRunning = 'task_vm.vm_not_running';
    case BootstrapFailed = 'task_vm.bootstrap_failed';
    case BootstrapTimeout = 'task_vm.bootstrap_timeout';
    case NotEnrolled = 'task_vm.not_enrolled';
    case ResumeTimeout = 'task_vm.resume_timeout';
    case InvalidUserData = 'task_vm.invalid_user_data';
    case InvalidGatewayKey = 'task_vm.invalid_gateway_key';

    // Commands on hosts and guests.
    case HostCommandFailed = 'task_vm.host_command_failed';
    case InvalidHostOutput = 'task_vm.invalid_host_output';
    case HostKeyFailed = 'task_vm.host_key_failed';

    // The group's runtime on the VM.
    case RuntimeFailed = 'task_vm.runtime_failed';
    case ModelKeyFailed = 'task_vm.model_key_failed';
    case TopologyFailed = 'task_vm.topology_failed';
    case GitCredentialRefused = 'task_vm.git_credential_refused';

    // Workspace ownership.
    case WorkspaceMismatch = 'task_vm.workspace_mismatch';
    case ForeignInstance = 'task_vm.foreign_instance';
    case Parked = 'task_vm.parked';
    case AccessRefused = 'task_vm.access_refused';

    // Base images.
    case ImageMismatch = 'task_vm.image_mismatch';
    case RecipeImagesMissing = 'task_vm.recipe_images_missing';
    case RecipeImagesTransferFailed = 'task_vm.recipe_images_transfer_failed';
    case BaseImageMissing = 'task_vm.base_image_missing';
    case ImageBuildFailed = 'task_vm.image_build_failed';
    case ImageBuildRunning = 'task_vm.image_build_running';
    case ImageBuildSuperseded = 'task_vm.image_build_superseded';
    case ImageSyncFailed = 'task_vm.image_sync_failed';

    // Shared package cache.
    case PackageCacheIntegrityConflict = 'task_vm.package_cache_integrity_conflict';
    case PackageCacheLockInvalid = 'task_vm.package_cache_lock_invalid';
    case PackageCacheInvalidLimit = 'task_vm.package_cache_invalid_limit';
    case PackageCacheInvalidResult = 'task_vm.package_cache_invalid_result';
    case PackageCacheMountInvalid = 'task_vm.package_cache_mount_invalid';
    case PackageCacheFailed = 'task_vm.package_cache_failed';

    // UpCloud.
    case UpCloudNotConfigured = 'task_vm.upcloud_not_configured';
    case UpCloudTokenUnavailable = 'task_vm.upcloud_token_unavailable';
    case UpCloudFailed = 'task_vm.upcloud_failed';
    case UpCloudInvalid = 'task_vm.upcloud_invalid';
    case UpCloudTimeout = 'task_vm.upcloud_timeout';
    case UpCloudBudgetFull = 'task_vm.upcloud_budget_full';

    // The Gateway host's queue worker units.
    case WorkersFailed = 'task_vm.workers_failed';
}
