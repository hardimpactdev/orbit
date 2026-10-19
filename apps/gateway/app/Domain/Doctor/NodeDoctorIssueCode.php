<?php

declare(strict_types=1);

namespace App\Domain\Doctor;

enum NodeDoctorIssueCode: string implements DoctorIssueCode
{
    case LifecycleNotActive = 'node.lifecycle_not_active';
    case DiskLow = 'node.disk_low';
    case SshUnreachable = 'node.ssh_unreachable';
    case PlatformMismatch = 'node.platform_mismatch';
    case ArchitectureMismatch = 'node.architecture_mismatch';
    case PrivateDnsPolicyMismatch = 'node.private_dns_policy_mismatch';
    case PrivateDnsUnreachable = 'node.private_dns_unreachable';
    case WireGuardAddressMismatch = 'node.wireguard_ip_mismatch';
    case AgentMissing = 'node.agent_missing';
    case AgentBinaryMismatch = 'node.agent_binary_mismatch';
    case AgentInactive = 'node.agent_inactive';
    case AgentViewStale = 'node.agent_view_stale';
    case AgentSecretMismatch = 'node.agent_secret_mismatch';
    case ReleaseLag = 'node.release_lag';
    case RouteResidueRetained = 'node.route_residue_retained';
    case CliForeign = 'node.cli_foreign';
    case TaskVmOrphaned = 'node.task_vm_orphaned';
    case TaskVmMissing = 'node.task_vm_missing';
    case TaskVmInspectionFailed = 'node.task_vm_inspection_failed';
    case TaskVmStrayInstance = 'node.task_vm_stray_instance';
    case TaskVmStrayVolume = 'node.task_vm_stray_volume';
    case TaskVmStrayRecord = 'node.task_vm_stray_record';
    case TaskVmStrayNode = 'node.task_vm_stray_node';
    case TaskVmParked = 'node.task_vm_parked';
    case InspectionFailed = 'node.inspection_failed';

    public function code(): string
    {
        return $this->value;
    }

    public function family(): DoctorFamily
    {
        return DoctorFamily::Node;
    }
}
